<?php

use App\Ecosystems\Composer\Processors\ComposerProcessor;
use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use App\Models\InventoryEvent;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function pushPackage(ComposerProcessor $processor, Project $project, string $hash, array $overrides = []): void
{
    $processor->process(
        project: $project,
        payloadHash: $hash,
        packages: [array_merge([
            'name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
            'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
            'available_version' => null, 'author' => null,
        ], $overrides)],
        findings: [],
        advisories: [],
        privatePackages: [],
    );
}

function composerProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('ComposerProcessor inserts packages on first push', function (): void {
    $project = composerProject();
    $processor = new ComposerProcessor;

    $result = $processor->process(
        project: $project,
        payloadHash: 'abc123',
        packages: [
            ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect($result->ecosystem)->toBe(EcosystemEnum::Composer)
        ->and(ProjectPackage::where('project_id', $project->id)->count())->toBe(1)
        ->and(Package::where('name', 'vendor/foo')->exists())->toBeTrue();
});

test('ComposerProcessor returns new findings', function (): void {
    $project = composerProject();
    $processor = new ComposerProcessor;

    $result = $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [
            ['package_name' => 'vendor/foo', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-abc', 'severity' => 'high'],
        ],
        advisories: [],
        privatePackages: [],
    );

    expect($result->newFindings)->toHaveCount(1)
        ->and($result->newFindings[0]['advisory_id'])->toBe('GHSA-abc');
});

test('ComposerProcessor skips processing when hash matches', function (): void {
    $project = composerProject();
    $processor = new ComposerProcessor;

    $packages = [
        ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
            'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
            'available_version' => null, 'author' => null],
    ];

    $processor->process(project: $project, payloadHash: 'same', packages: $packages,
        findings: [], advisories: [], privatePackages: []);

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(1);

    $processor->process(project: $project, payloadHash: 'same', packages: $packages,
        findings: [], advisories: [], privatePackages: []);

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(1);
});

test('ComposerProcessor still records findings when package hash is unchanged', function (): void {
    $project = composerProject();
    $processor = new ComposerProcessor;

    $packages = [
        ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
            'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
            'available_version' => null, 'author' => null],
    ];

    $processor->process(project: $project, payloadHash: 'same', packages: $packages,
        findings: [], advisories: [], privatePackages: []);

    $result = $processor->process(
        project: $project,
        payloadHash: 'same',
        packages: $packages,
        findings: [
            ['package_name' => 'vendor/foo', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-new', 'severity' => 'high'],
        ],
        advisories: [],
        privatePackages: [],
    );

    expect($result->newFindings)->toHaveCount(1)
        ->and($result->newFindings[0]['advisory_id'])->toBe('GHSA-new');
});

test('ComposerProcessor records an updated event when an existing package version changes', function (): void {
    $project = composerProject();
    $processor = new ComposerProcessor;

    pushPackage($processor, $project, 'hash-1', ['version' => '1.0.0']);
    pushPackage($processor, $project, 'hash-2', ['version' => '2.0.0']);

    expect(ProjectPackage::where('project_id', $project->id)->sole()->version)->toBe('2.0.0');

    $event = InventoryEvent::where('project_id', $project->id)->where('action', InventoryActionEnum::Updated->value)->sole();
    expect($event->old_version)->toBe('1.0.0')
        ->and($event->new_version)->toBe('2.0.0');
});

test('ComposerProcessor records an updated event when only is_direct changes', function (): void {
    $project = composerProject();
    $processor = new ComposerProcessor;

    pushPackage($processor, $project, 'hash-1', ['is_direct' => false]);
    pushPackage($processor, $project, 'hash-2', ['is_direct' => true]);

    expect(ProjectPackage::where('project_id', $project->id)->sole()->is_direct)->toBeTrue();
    expect(InventoryEvent::where('project_id', $project->id)->where('action', InventoryActionEnum::Updated->value)->exists())->toBeTrue();
});

test('ComposerProcessor writes no event when the resync payload has no actual changes', function (): void {
    $project = composerProject();
    $processor = new ComposerProcessor;

    pushPackage($processor, $project, 'hash-1');
    pushPackage($processor, $project, 'hash-2');

    expect(InventoryEvent::where('project_id', $project->id)->where('action', InventoryActionEnum::Updated->value)->exists())->toBeFalse();
});

test('ComposerProcessor removes a package no longer present and records a removed event', function (): void {
    $project = composerProject();
    $processor = new ComposerProcessor;

    pushPackage($processor, $project, 'hash-1');
    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(1);

    $processor->process(
        project: $project,
        payloadHash: 'hash-2',
        packages: [],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(0);

    $event = InventoryEvent::where('project_id', $project->id)->where('action', InventoryActionEnum::Removed->value)->sole();
    expect($event->old_version)->toBe('1.0.0')
        ->and($event->new_version)->toBeNull();
});

test('ComposerProcessor excludes outdated packages already notified in delta mode', function (): void {
    $user = User::factory()->create(['settings' => [
        'notification_mode' => 'daily_digest',
        'digest_content' => 'delta',
    ]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'last_notified_at' => now()->subHour(),
    ]);

    $package = Package::factory()->create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::Composer->value,
        'name' => 'vendor/stale-pkg',
        'latest_version' => '2.0.0',
    ]);
    DB::table('packages')->where('id', $package->id)->update(['updated_at' => now()->subHours(3)]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '1.0.0',
        'notified_latest_version' => '2.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    $result = (new ComposerProcessor)->process(
        project: $project,
        payloadHash: 'stale-hash',
        packages: [
            ['name' => 'vendor/stale-pkg', 'version' => '1.0.0', 'is_direct' => true,
                'is_dev' => false, 'is_active' => null, 'is_child' => null, 'depth' => 0,
                'source_url' => null, 'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect($result->outdatedSummary->isEmpty())->toBeTrue();
});

test('ComposerProcessor does not re-enter still-outdated package into delta digest when only author/source_url metadata changes', function (): void {
    $user = User::factory()->create(['settings' => [
        'notification_mode' => 'daily_digest',
        'digest_content' => 'delta',
    ]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'last_notified_at' => now()->subHour(),
    ]);

    $package = Package::factory()->create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::Composer->value,
        'name' => 'vendor/stale-pkg',
        'latest_version' => '2.0.0',
        'author' => 'Old Author',
        'source_url' => 'https://old.example.com',
    ]);
    DB::table('packages')->where('id', $package->id)->update(['updated_at' => now()->subHours(3)]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '1.0.0',
        'notified_latest_version' => '2.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    $result = (new ComposerProcessor)->process(
        project: $project,
        payloadHash: 'metadata-drift-hash',
        packages: [
            ['name' => 'vendor/stale-pkg', 'version' => '1.0.0', 'is_direct' => true,
                'is_dev' => false, 'is_active' => null, 'is_child' => null, 'depth' => 0,
                'source_url' => 'https://new.example.com', 'available_version' => null, 'author' => 'New Author'],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect($result->outdatedSummary->isEmpty())->toBeTrue();

    $package->refresh();
    expect($package->author)->toBe('New Author')
        ->and($package->source_url)->toBe('https://new.example.com')
        ->and($package->updated_at->lt(now()->subHours(2)))->toBeTrue();
});

test('ComposerProcessor includes newly-outdated packages in delta mode', function (): void {
    $user = User::factory()->create(['settings' => [
        'notification_mode' => 'daily_digest',
        'digest_content' => 'delta',
    ]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'last_notified_at' => now()->subHour(),
    ]);

    $package = Package::factory()->create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::Composer->value,
        'name' => 'vendor/fresh-pkg',
        'latest_version' => '2.0.0',
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '1.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    $result = (new ComposerProcessor)->process(
        project: $project,
        payloadHash: 'fresh-hash',
        packages: [
            ['name' => 'vendor/fresh-pkg', 'version' => '1.0.0', 'is_direct' => true,
                'is_dev' => false, 'is_active' => null, 'is_child' => null, 'depth' => 0,
                'source_url' => null, 'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect($result->outdatedSummary->isEmpty())->toBeFalse();
});

test('ComposerProcessor returns all outdated packages in full_state mode', function (): void {
    $user = User::factory()->create(['settings' => [
        'notification_mode' => 'daily_digest',
        'digest_content' => 'full_state',
    ]]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'last_notified_at' => now()->subHour(),
    ]);

    $package = Package::factory()->create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::Composer->value,
        'name' => 'vendor/old-pkg',
        'latest_version' => '2.0.0',
    ]);
    DB::table('packages')->where('id', $package->id)->update(['updated_at' => now()->subHours(3)]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '1.0.0',
        'notified_latest_version' => '2.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    $result = (new ComposerProcessor)->process(
        project: $project,
        payloadHash: 'full-state-hash',
        packages: [
            ['name' => 'vendor/old-pkg', 'version' => '1.0.0', 'is_direct' => true,
                'is_dev' => false, 'is_active' => null, 'is_child' => null, 'depth' => 0,
                'source_url' => null, 'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect($result->outdatedSummary->isEmpty())->toBeFalse();
});
