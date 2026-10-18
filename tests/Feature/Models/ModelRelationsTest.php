<?php

use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use App\Models\ApiInteraction;
use App\Models\InventoryEvent;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;
use Carbon\CarbonInterface;

// ─── ApiInteraction ───────────────────────────────────────────────────────────

test('ApiInteraction belongs to project', function (): void {
    $interaction = ApiInteraction::factory()->create();

    expect($interaction->project)->toBeInstanceOf(Project::class);
});

test('ApiInteraction belongs to token', function (): void {
    $interaction = ApiInteraction::factory()->create();

    expect($interaction->token)->not->toBeNull();
});

test('ApiInteraction casts hash_matched as boolean', function (): void {
    $interaction = ApiInteraction::factory()->create(['hash_matched' => true]);

    $fresh = ApiInteraction::find($interaction->id);

    expect($fresh->hash_matched)->toBeBool();
});

test('ApiInteraction casts occurred_at as datetime', function (): void {
    $interaction = ApiInteraction::factory()->create(['occurred_at' => now()]);

    $fresh = ApiInteraction::find($interaction->id);

    expect($fresh->occurred_at)->toBeInstanceOf(CarbonInterface::class);
});

// ─── InventoryEvent ───────────────────────────────────────────────────────────

test('InventoryEvent belongs to project', function (): void {
    $event = InventoryEvent::factory()->create();

    expect($event->project)->toBeInstanceOf(Project::class);
});

test('InventoryEvent casts ecosystem as EcosystemEnum', function (): void {
    $event = InventoryEvent::factory()->create(['ecosystem' => EcosystemEnum::Composer->value]);

    $fresh = InventoryEvent::find($event->id);

    expect($fresh->ecosystem)->toBeInstanceOf(EcosystemEnum::class)
        ->and($fresh->ecosystem)->toBe(EcosystemEnum::Composer);
});

test('InventoryEvent casts action as InventoryActionEnum', function (): void {
    $event = InventoryEvent::factory()->create(['action' => InventoryActionEnum::Added->value]);

    $fresh = InventoryEvent::find($event->id);

    expect($fresh->action)->toBeInstanceOf(InventoryActionEnum::class)
        ->and($fresh->action)->toBe(InventoryActionEnum::Added);
});

test('InventoryEvent has uuid route key', function (): void {
    $event = InventoryEvent::factory()->create();

    expect($event->getRouteKeyName())->toBe('uuid')
        ->and($event->uuid)->not->toBeEmpty();
});

// ─── Package ──────────────────────────────────────────────────────────────────

test('Package has many projectPackages', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $package = Package::factory()->create();

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
    ]);

    expect($package->projectPackages)->toHaveCount(1)
        ->and($package->projectPackages->first())->toBeInstanceOf(ProjectPackage::class);
});

// ─── Project ──────────────────────────────────────────────────────────────────

test('Project notifyImmediately returns false when settings key missing', function (): void {
    $project = Project::factory()->create(['settings' => []]);

    expect($project->notifyImmediately())->toBeFalse();
});

test('Project notifyImmediately returns false when settings is null', function (): void {
    $project = Project::factory()->create(['settings' => null]);

    expect($project->notifyImmediately())->toBeFalse();
});

test('Project notifyImmediately returns true when settings key is true', function (): void {
    $project = Project::factory()->create(['settings' => ['notify_immediately' => true]]);

    expect($project->notifyImmediately())->toBeTrue();
});

test('ecosystemState returns the state for the requested ecosystem only', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $composerState = ProjectEcosystem::factory()->create(['project_id' => $project->id, 'ecosystem' => EcosystemEnum::Composer->value]);
    ProjectEcosystem::factory()->create(['project_id' => $project->id, 'ecosystem' => EcosystemEnum::Npm->value]);

    $result = $project->ecosystemState(EcosystemEnum::Composer);

    expect($result?->id)->toBe($composerState->id);
});

test('ecosystemState returns null when the project has no state for that ecosystem', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    expect($project->ecosystemState(EcosystemEnum::Composer))->toBeNull();
});

// ─── ProjectEcosystem ─────────────────────────────────────────────────────────

test('ProjectEcosystem belongs to project', function (): void {
    $ecosystem = ProjectEcosystem::factory()->create();

    expect($ecosystem->project)->toBeInstanceOf(Project::class);
});

test('ProjectEcosystem casts ecosystem as EcosystemEnum', function (): void {
    $ecosystem = ProjectEcosystem::factory()->create(['ecosystem' => EcosystemEnum::Npm->value]);

    $fresh = ProjectEcosystem::find($ecosystem->id);

    expect($fresh->ecosystem)->toBeInstanceOf(EcosystemEnum::class)
        ->and($fresh->ecosystem)->toBe(EcosystemEnum::Npm);
});

test('ProjectEcosystem casts is_stale as boolean', function (): void {
    $ecosystem = ProjectEcosystem::factory()->create(['is_stale' => true]);

    $fresh = ProjectEcosystem::find($ecosystem->id);

    expect($fresh->is_stale)->toBeBool()->toBeTrue();
});

test('ProjectEcosystem has uuid route key', function (): void {
    $ecosystem = ProjectEcosystem::factory()->create();

    expect($ecosystem->getRouteKeyName())->toBe('uuid')
        ->and($ecosystem->uuid)->not->toBeEmpty();
});

// ─── ProjectPackage ───────────────────────────────────────────────────────────

test('ProjectPackage proxies name from Package relation when not set directly', function (): void {
    $package = Package::factory()->create(['name' => 'vendor/my-package']);
    $projectPackage = ProjectPackage::factory()->create(['package_id' => $package->id]);

    // Load fresh instance without JOIN-populated attributes
    $fresh = ProjectPackage::with('package')->find($projectPackage->id);

    expect($fresh->name)->toBe('vendor/my-package');
});

test('ProjectPackage proxies ecosystem from Package relation when not set directly', function (): void {
    $package = Package::factory()->create(['ecosystem' => EcosystemEnum::WpPlugin->value]);
    $projectPackage = ProjectPackage::factory()->create(['package_id' => $package->id]);

    $fresh = ProjectPackage::with('package')->find($projectPackage->id);

    expect($fresh->ecosystem)->toBeInstanceOf(EcosystemEnum::class)
        ->and($fresh->ecosystem)->toBe(EcosystemEnum::WpPlugin);
});

test('ProjectPackage proxies latest_version from Package relation when not set directly', function (): void {
    $package = Package::factory()->create(['latest_version' => '2.0.0']);
    $projectPackage = ProjectPackage::factory()->create(['package_id' => $package->id]);

    $fresh = ProjectPackage::with('package')->find($projectPackage->id);

    expect($fresh->latest_version)->toBe('2.0.0');
});

test('ProjectPackage proxies source_url from Package relation when not set directly', function (): void {
    $package = Package::factory()->create(['source_url' => 'https://example.com']);
    $projectPackage = ProjectPackage::factory()->create(['package_id' => $package->id]);

    $fresh = ProjectPackage::with('package')->find($projectPackage->id);

    expect($fresh->source_url)->toBe('https://example.com');
});

test('ProjectPackage proxies is_private from Package relation when not set directly', function (): void {
    $package = Package::factory()->create(['is_private' => true]);
    $projectPackage = ProjectPackage::factory()->create(['package_id' => $package->id]);

    $fresh = ProjectPackage::with('package')->find($projectPackage->id);

    expect($fresh->is_private)->toBeTrue();
});

test('ProjectPackage proxies advisory_checked_at from Package relation when not set directly', function (): void {
    $checkedAt = now()->subHour();
    $package = Package::factory()->create(['advisory_checked_at' => $checkedAt]);
    $projectPackage = ProjectPackage::factory()->create(['package_id' => $package->id]);

    $fresh = ProjectPackage::with('package')->find($projectPackage->id);

    expect($fresh->advisory_checked_at)->toBeInstanceOf(CarbonInterface::class);
});

// ─── User ─────────────────────────────────────────────────────────────────────

test('initials takes the first letter of the first two words of the name', function (): void {
    $user = User::factory()->make(['name' => 'Ada Lovelace']);

    expect($user->initials())->toBe('AL');
});

test('initials uses a single letter for a one-word name', function (): void {
    $user = User::factory()->make(['name' => 'Cher']);

    expect($user->initials())->toBe('C');
});

test('initials ignores words beyond the first two', function (): void {
    $user = User::factory()->make(['name' => 'Ada Marie Lovelace']);

    expect($user->initials())->toBe('AM');
});

// ─── VulnFinding ──────────────────────────────────────────────────────────────

test('VulnFinding belongs to a package', function (): void {
    $finding = VulnFinding::factory()->create();

    expect($finding->package)->toBeInstanceOf(Package::class);
});

test('VulnFinding proxies ecosystem and package_name from its package', function (): void {
    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/pkg']);
    $finding = VulnFinding::factory()->create(['package_id' => $package->id]);

    $fresh = VulnFinding::find($finding->id);

    expect($fresh->ecosystem)->toBe(EcosystemEnum::Composer)
        ->and($fresh->package_name)->toBe('vendor/pkg');
});

test('VulnFinding has uuid route key', function (): void {
    $finding = VulnFinding::factory()->create();

    expect($finding->getRouteKeyName())->toBe('uuid');
});
