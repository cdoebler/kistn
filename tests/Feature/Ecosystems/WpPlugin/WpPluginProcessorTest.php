<?php

use App\Ecosystems\WpPlugin\Processors\WpPluginProcessor;
use App\Enums\EcosystemEnum;
use App\Events\PackagePublishedEvent;
use App\Models\AdvisorySnapshot;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Notifications\ProjectChangesNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

function wpProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('WpPluginProcessor inserts packages', function (): void {
    $project = wpProject();
    $processor = new WpPluginProcessor;

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'akismet', 'version' => '5.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(1);
});

test('WpPluginProcessor marks private packages', function (): void {
    $project = wpProject();
    $processor = new WpPluginProcessor;

    Package::create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'my-private-plugin',
    ]);

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'my-private-plugin', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: ['my-private-plugin'],
    );

    $perProject = Package::where('project_id', $project->id)
        ->where('name', 'my-private-plugin')
        ->first();

    expect($perProject)->not->toBeNull()
        ->and($perProject->is_private)->toBeTrue();
});

test('WpPluginProcessor skips private package slug not found in DB', function (): void {
    $project = wpProject();
    $processor = new WpPluginProcessor;

    // Push with a private package slug that has no Package row at all.
    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [],
        findings: [],
        advisories: [],
        privatePackages: ['ghost-plugin'],
    );

    // No per-project package should have been created.
    expect(Package::where('project_id', $project->id)->where('name', 'ghost-plugin')->exists())->toBeFalse();
});

test('WpPluginProcessor updates already-per-project private package in place', function (): void {
    $project = wpProject();
    $processor = new WpPluginProcessor;

    // Package already scoped to this project (not global).
    $perProjectPkg = Package::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'my-local-plugin',
        'is_private' => null,
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $perProjectPkg->id,
        'version' => '1.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'my-local-plugin', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: ['my-local-plugin'],
    );

    $pkg = $perProjectPkg->fresh();

    expect($pkg->is_private)->toBeTrue()
        ->and($pkg->advisory_checked_at)->not->toBeNull()
        // The per-project row must still be the only package row for this project.
        ->and(Package::where('project_id', $project->id)->where('name', 'my-local-plugin')->count())->toBe(1);
});

test('WpPluginProcessor marks public packages migrates per-project private row to global and fires event', function (): void {
    Event::fake([PackagePublishedEvent::class]);
    Http::fake(['https://api.wordpress.org/plugins/info/1.2/*' => Http::response([], 404)]);

    $project = wpProject();
    $processor = new WpPluginProcessor;

    // A plugin previously (wrongly) confined to a per-project private row.
    $perProjectPkg = Package::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'kistn',
        'is_private' => true,
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $perProjectPkg->id,
        'version' => '1.0.1',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'kistn', 'version' => '1.0.1', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
        publicPackages: ['kistn'],
    );

    $global = Package::where('project_id', 0)
        ->where('ecosystem', EcosystemEnum::WpPlugin->value)
        ->where('name', 'kistn')
        ->first();

    expect($global)->not->toBeNull()
        ->and($global->is_private)->toBeFalse()
        ->and($global->advisory_checked_at)->not->toBeNull()
        // Per-project private row is gone; its ProjectPackage now points at the global row.
        ->and(Package::where('project_id', $project->id)->where('name', 'kistn')->exists())->toBeFalse()
        ->and(ProjectPackage::where('project_id', $project->id)->where('package_id', $global->id)->exists())->toBeTrue();

    Event::assertDispatched(PackagePublishedEvent::class, fn (PackagePublishedEvent $event): bool => $event->project->id === $project->id
        && $event->ecosystem === EcosystemEnum::WpPlugin
        && $event->package->name === 'kistn');
});

test('WpPluginProcessor confirms an already-global package as public without an event', function (): void {
    Event::fake([PackagePublishedEvent::class]);

    $project = wpProject();
    $processor = new WpPluginProcessor;

    $global = Package::create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'akismet',
        'is_private' => null,
    ]);

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'akismet', 'version' => '5.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
        publicPackages: ['akismet'],
    );

    expect($global->fresh()->is_private)->toBeFalse();
    Event::assertNotDispatched(PackagePublishedEvent::class);
});

test('WpPluginProcessor upserts AdvisorySnapshot when advisories are present', function (): void {
    $project = wpProject();
    $processor = new WpPluginProcessor;

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'contact-form-7', 'version' => '5.9.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [
            ['ecosystem' => 'wp-plugin', 'name' => 'contact-form-7', 'payload' => ['vulnerabilities' => []]],
        ],
        privatePackages: [],
    );

    $snapshot = AdvisorySnapshot::where('ecosystem', EcosystemEnum::WpPlugin->value)
        ->where('name', 'contact-form-7')
        ->first();

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->advisories)->toBe(['vulnerabilities' => []]);
});

test('WpPluginProcessor does not publish a package for an advisory belonging to a different ecosystem', function (): void {
    Event::fake([PackagePublishedEvent::class]);

    $project = wpProject();

    $perProjectPkg = Package::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'still-private-plugin',
        'is_private' => true,
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $perProjectPkg->id,
        'version' => '1.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    (new WpPluginProcessor)->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'still-private-plugin', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        // Same name, but a wp-theme advisory — must not be mistaken for this wp-plugin's own.
        advisories: [
            ['ecosystem' => 'wp-theme', 'name' => 'still-private-plugin', 'payload' => ['vulnerabilities' => []]],
        ],
        privatePackages: [],
    );

    Event::assertNotDispatched(PackagePublishedEvent::class);
    expect($perProjectPkg->fresh()->is_private)->toBeTrue();
});

test('WpPluginProcessor fires PackagePublishedEvent and migrates per-project package to global on advisory push', function (): void {
    Event::fake([PackagePublishedEvent::class]);
    Http::fake(['https://api.wordpress.org/plugins/info/1.2/*' => Http::response([], 404)]);

    $project = wpProject();

    // Start with a per-project (private) package.
    $perProjectPkg = Package::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'newly-public-plugin',
        'is_private' => true,
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $perProjectPkg->id,
        'version' => '1.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    $processor = new WpPluginProcessor;

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'newly-public-plugin', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [
            ['ecosystem' => 'wp-plugin', 'name' => 'newly-public-plugin', 'payload' => []],
        ],
        privatePackages: [],
    );

    // Global package row must now exist.
    expect(Package::where('project_id', 0)->where('name', 'newly-public-plugin')->exists())->toBeTrue();

    // Event must have fired.
    Event::assertDispatched(PackagePublishedEvent::class, fn (PackagePublishedEvent $event): bool => $event->project->id === $project->id
        && $event->ecosystem === EcosystemEnum::WpPlugin
        && $event->package->name === 'newly-public-plugin');
});

test('WpPluginProcessor refuses to auto-publish a package that predates its wordpress.org directory listing', function (): void {
    Event::fake([PackagePublishedEvent::class]);
    Http::fake(['https://api.wordpress.org/plugins/info/1.2/*' => Http::response(['added' => '2026-07-08'], 200)]);

    $project = wpProject();

    // Installed long before this slug ever appeared on wordpress.org — a slug collision.
    $perProjectPkg = Package::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'acme-plugin',
        'is_private' => true,
        'created_at' => '2026-06-14',
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $perProjectPkg->id,
        'version' => '3.3.11',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    (new WpPluginProcessor)->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'acme-plugin', 'version' => '3.3.11', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
        publicPackages: ['acme-plugin'],
    );

    expect(Package::where('project_id', 0)->where('name', 'acme-plugin')->exists())->toBeFalse()
        ->and($perProjectPkg->fresh()->is_private)->toBeTrue();

    Event::assertNotDispatched(PackagePublishedEvent::class);
});

test('WpPluginProcessor publishes a package created after its wordpress.org directory listing', function (): void {
    Event::fake([PackagePublishedEvent::class]);
    Http::fake(['https://api.wordpress.org/plugins/info/1.2/*' => Http::response(['added' => '2026-07-08'], 200)]);

    $project = wpProject();

    // Installed after the slug was already on wordpress.org — a genuine publish.
    $perProjectPkg = Package::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'genuinely-public-plugin',
        'is_private' => true,
        'created_at' => '2026-08-01',
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $perProjectPkg->id,
        'version' => '1.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    (new WpPluginProcessor)->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'genuinely-public-plugin', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
        publicPackages: ['genuinely-public-plugin'],
    );

    expect(Package::where('project_id', 0)->where('name', 'genuinely-public-plugin')->exists())->toBeTrue();

    Event::assertDispatched(PackagePublishedEvent::class);
});

test('WpPluginProcessor notifies a sibling project when a push reports a new shared latest_version', function (): void {
    Notification::fake();

    $project = wpProject();

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    $acmePlugin = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'acme-plugin', 'latest_version' => '3.1.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $acmePlugin->id, 'version' => '3.0.0']);

    (new WpPluginProcessor)->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'acme-plugin', 'version' => '3.1.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => '3.2.0', 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect($acmePlugin->fresh()->latest_version)->toBe('3.2.0');

    Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
        $summary = $n->payload->outdatedByEcosystem['wp-plugin'] ?? null;

        if ($summary === null) {
            return false;
        }

        $all = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

        return collect($all)->contains(fn (array $p): bool => $p['name'] === 'acme-plugin' && $p['latest'] === '3.2.0');
    });
});

test('WpPluginProcessor fires both sibling notifiers independently in the same push', function (): void {
    Notification::fake();

    $project = wpProject();

    $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

    // Sibling holds a package that gets a new finding this push (matched by exact
    // package_version — the sibling must be on the same version as the acting project).
    $dualFinding = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'dual-finding']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $dualFinding->id, 'version' => '1.0.0']);

    // Sibling holds a second package whose latest_version changes this push, on a
    // genuinely different installed version than the acting project.
    $dualOutdated = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['name' => 'dual-outdated', 'latest_version' => '1.0.0']);
    ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $dualOutdated->id, 'version' => '0.9.0']);

    (new WpPluginProcessor)->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'dual-finding', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
            ['name' => 'dual-outdated', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => '2.0.0', 'author' => null],
        ],
        findings: [
            ['package_name' => 'dual-finding', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-dual', 'severity' => 'high'],
        ],
        advisories: [],
        privatePackages: [],
    );

    expect($dualOutdated->fresh()->latest_version)->toBe('2.0.0');

    // Documented trade-off (see design doc's "Out of scope"): the finding-notifier and
    // outdated-notifier dispatch independently, so a sibling hit by both signals in one
    // run gets two separate emails, not one combined one.
    Notification::assertSentToTimes($siblingUser, ProjectChangesNotification::class, 2);

    Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
        $findings = $n->payload->findingsByEcosystem['wp-plugin'] ?? [];

        return $n->payload->outdatedByEcosystem === []
            && collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-dual');
    });

    Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
        $summary = $n->payload->outdatedByEcosystem['wp-plugin'] ?? null;

        if ($summary === null) {
            return false;
        }

        $all = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

        return $n->payload->findingsByEcosystem === []
            && collect($all)->contains(fn (array $p): bool => $p['name'] === 'dual-outdated' && $p['current'] === '0.9.0' && $p['latest'] === '2.0.0');
    });
});
