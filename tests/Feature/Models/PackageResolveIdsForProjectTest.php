<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;

function resolverProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('resolves to the global package id when only a global row exists', function (): void {
    $project = resolverProject();
    $global = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['project_id' => 0, 'name' => 'vendor/pkg']);

    $ids = Package::resolveIdsForProject($project, EcosystemEnum::Composer, ['vendor/pkg']);

    expect($ids)->toBe(['vendor/pkg' => $global->id]);
});

test('resolves to the private package id when only a per-project row exists', function (): void {
    $project = resolverProject();
    $private = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['project_id' => $project->id, 'name' => 'vendor/pkg']);

    $ids = Package::resolveIdsForProject($project, EcosystemEnum::Composer, ['vendor/pkg']);

    expect($ids)->toBe(['vendor/pkg' => $private->id]);
});

test('prefers the global package id over a private row for the same name', function (): void {
    $project = resolverProject();
    $global = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['project_id' => 0, 'name' => 'vendor/pkg']);
    Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['project_id' => $project->id, 'name' => 'vendor/pkg']);

    $ids = Package::resolveIdsForProject($project, EcosystemEnum::Composer, ['vendor/pkg']);

    expect($ids)->toBe(['vendor/pkg' => $global->id]);
});

test('does not resolve another project private package', function (): void {
    $project = resolverProject();
    $otherProject = resolverProject();
    Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['project_id' => $otherProject->id, 'name' => 'vendor/pkg']);

    $ids = Package::resolveIdsForProject($project, EcosystemEnum::Composer, ['vendor/pkg']);

    expect($ids)->toBe([]);
});

test('excludes names with no matching package from the result', function (): void {
    $project = resolverProject();
    Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['project_id' => 0, 'name' => 'vendor/known']);

    $ids = Package::resolveIdsForProject($project, EcosystemEnum::Composer, ['vendor/known', 'vendor/unknown']);

    expect($ids)->toBe(['vendor/known' => Package::where('name', 'vendor/known')->sole()->id]);
});

test('returns empty array for an empty names list without querying', function (): void {
    $project = resolverProject();

    expect(Package::resolveIdsForProject($project, EcosystemEnum::Composer, []))->toBe([]);
});

test('deduplicates repeated names before resolving', function (): void {
    $project = resolverProject();
    $global = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['project_id' => 0, 'name' => 'vendor/pkg']);

    $ids = Package::resolveIdsForProject($project, EcosystemEnum::Composer, ['vendor/pkg', 'vendor/pkg']);

    expect($ids)->toBe(['vendor/pkg' => $global->id]);
});

test('does not resolve a package from a different ecosystem with the same name', function (): void {
    $project = resolverProject();
    Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['project_id' => 0, 'name' => 'vendor/pkg']);

    $ids = Package::resolveIdsForProject($project, EcosystemEnum::Composer, ['vendor/pkg']);

    expect($ids)->toBe([]);
});
