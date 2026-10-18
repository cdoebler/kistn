<?php

use App\Enums\EcosystemEnum;
use App\Events\PackagePublishedEvent;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\PackagePrivacyService;
use Illuminate\Support\Facades\Event;

function privacyProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('markPrivate updates an already per-project package in place', function (): void {
    $project = privacyProject();
    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'project_id' => $project->id,
        'name' => 'vendor/already-scoped',
        'is_private' => null,
    ]);

    $result = (new PackagePrivacyService)->markPrivate($project, $package);

    expect($result->is($package))->toBeTrue()
        ->and($result->is_private)->toBeTrue()
        ->and($result->advisory_checked_at)->not->toBeNull();
});

test('markPrivate migrates a global package to a new per-project row, copying registry metadata', function (): void {
    $project = privacyProject();
    $global = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'project_id' => 0,
        'name' => 'vendor/php-client',
        'source_url' => 'https://github.com/example/php-client',
        'author' => 'Example Author',
        'latest_version' => '2.0.0',
        'is_private' => null,
    ]);
    $pp = ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $global->id]);

    $result = (new PackagePrivacyService)->markPrivate($project, $global);

    expect($result->project_id)->toBe($project->id)
        ->and($result->is_private)->toBeTrue()
        ->and($result->source_url)->toBe('https://github.com/example/php-client')
        ->and($result->author)->toBe('Example Author')
        ->and($result->latest_version)->toBe('2.0.0')
        ->and(Package::find($global->id))->toBeNull(); // orphaned global row deleted

    expect($pp->refresh()->package_id)->toBe($result->id);
});

test('markPrivate updates an existing per-project row instead of creating a duplicate', function (): void {
    $project = privacyProject();
    $global = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['project_id' => 0, 'name' => '@scope/pkg']);
    $existingPerProject = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create([
        'project_id' => $project->id,
        'name' => '@scope/pkg',
        'is_private' => null,
    ]);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $global->id]);

    $result = (new PackagePrivacyService)->markPrivate($project, $global);

    expect($result->is($existingPerProject))->toBeTrue()
        ->and($result->is_private)->toBeTrue()
        ->and(Package::where('project_id', $project->id)->where('name', '@scope/pkg')->count())->toBe(1);
});

test('markPrivate on an already-private per-project package refreshes advisory_checked_at without bumping updated_at', function (): void {
    $project = privacyProject();
    $staleTimestamp = now()->subDay()->startOfSecond();
    $package = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'project_id' => $project->id,
        'name' => 'acme-plugin',
        'is_private' => true,
        'advisory_checked_at' => $staleTimestamp,
        'updated_at' => $staleTimestamp,
    ]);

    $now = now()->startOfSecond();
    (new PackagePrivacyService)->markPrivate($project, $package, $now);

    $fresh = $package->fresh();
    expect($fresh->updated_at->eq($staleTimestamp))->toBeTrue()
        ->and($fresh->advisory_checked_at->eq($now))->toBeTrue();
});

test('markPrivate on an existing per-project row (already migrated) does not bump updated_at on reconfirmation', function (): void {
    $project = privacyProject();
    $global = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create(['project_id' => 0, 'name' => 'shared-plugin']);
    $staleTimestamp = now()->subDay()->startOfSecond();
    $existingPerProject = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'project_id' => $project->id,
        'name' => 'shared-plugin',
        'is_private' => true,
        'advisory_checked_at' => $staleTimestamp,
        'updated_at' => $staleTimestamp,
    ]);
    // Simulate a second project still referencing the global row — this is why the
    // global row for "shared-plugin" survives day after day instead of getting
    // deleted the first time this project's own row was migrated to private.
    $otherProject = privacyProject();
    ProjectPackage::factory()->create(['project_id' => $otherProject->id, 'package_id' => $global->id]);

    $now = now()->addSeconds(5)->startOfSecond();
    $result = (new PackagePrivacyService)->markPrivate($project, $global, $now);

    expect($result->is($existingPerProject))->toBeTrue()
        ->and($result->fresh()->is_private)->toBeTrue()
        ->and($result->fresh()->advisory_checked_at->eq($now))->toBeTrue()
        ->and($result->fresh()->updated_at->eq($staleTimestamp))->toBeTrue();
});

test('markPublic migrates a per-project private package to a new global row and fires event', function (): void {
    Event::fake([PackagePublishedEvent::class]);

    $project = privacyProject();
    $private = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'project_id' => $project->id,
        'name' => 'vendor/php-client',
        'source_url' => 'https://github.com/example/php-client',
        'author' => 'Example Author',
        'is_private' => true,
    ]);
    $pp = ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $private->id]);

    $result = (new PackagePrivacyService)->markPublic($project, $private);

    expect($result->project_id)->toBe(0)
        ->and($result->is_private)->toBeFalse()
        ->and($result->source_url)->toBe('https://github.com/example/php-client')
        ->and($result->author)->toBe('Example Author')
        ->and(Package::find($private->id))->toBeNull();

    expect($pp->refresh()->package_id)->toBe($result->id);

    Event::assertDispatched(PackagePublishedEvent::class, fn (PackagePublishedEvent $event): bool => $event->project->id === $project->id
        && $event->ecosystem === EcosystemEnum::Composer
        && $event->package->name === 'vendor/php-client');
});

test('markPublic refreshes stale source_url and author when merging into an existing global row', function (): void {
    Event::fake([PackagePublishedEvent::class]);

    $project = privacyProject();

    // Global row exists with stale/missing registry metadata (the reported bug).
    $global = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'project_id' => 0,
        'name' => 'vendor/php-client',
        'source_url' => null,
        'author' => null,
        'is_private' => true,
    ]);
    $private = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'project_id' => $project->id,
        'name' => 'vendor/php-client',
        'source_url' => 'https://github.com/example/php-client',
        'author' => 'Example Author',
        'is_private' => true,
    ]);
    $pp = ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $private->id]);

    $result = (new PackagePrivacyService)->markPublic($project, $private);

    expect($result->is($global))->toBeTrue()
        ->and($result->is_private)->toBeFalse()
        ->and($result->source_url)->toBe('https://github.com/example/php-client')
        ->and($result->author)->toBe('Example Author')
        ->and(Package::find($private->id))->toBeNull();

    expect($pp->refresh()->package_id)->toBe($global->id);

    Event::assertDispatched(PackagePublishedEvent::class);
});

test('markPublic confirms an already-global package without firing an event', function (): void {
    Event::fake([PackagePublishedEvent::class]);

    $project = privacyProject();
    $global = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'project_id' => 0,
        'name' => 'akismet',
        'is_private' => null,
    ]);

    $result = (new PackagePrivacyService)->markPublic($project, $global);

    expect($result->is($global))->toBeTrue()
        ->and($result->fresh()->is_private)->toBeFalse();

    Event::assertNotDispatched(PackagePublishedEvent::class);
});

test('markPublic is a no-op for a package already confirmed public', function (): void {
    Event::fake([PackagePublishedEvent::class]);

    $project = privacyProject();
    $checkedAt = now()->subDay()->startOfSecond();
    $global = Package::factory()->forEcosystem(EcosystemEnum::WpPlugin)->create([
        'project_id' => 0,
        'name' => 'akismet',
        'is_private' => false,
        'advisory_checked_at' => $checkedAt,
    ]);

    (new PackagePrivacyService)->markPublic($project, $global);

    expect($global->fresh()->advisory_checked_at->eq($checkedAt))->toBeTrue();
    Event::assertNotDispatched(PackagePublishedEvent::class);
});
