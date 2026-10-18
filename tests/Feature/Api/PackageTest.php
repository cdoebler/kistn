<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;

function makeProjectWithToken(): array
{
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $token = $project->createToken('test')->plainTextToken;

    return [$project, $token];
}

test('packages index returns paginated packages for project', function () {
    [$project, $token] = makeProjectWithToken();

    $packages = Package::factory(3)->create([
        'ecosystem' => EcosystemEnum::Composer->value,
    ]);

    foreach ($packages as $package) {
        ProjectPackage::factory()->create([
            'project_id' => $project->id,
            'package_id' => $package->id,
        ]);
    }

    $response = $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/packages")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(3);
});

test('packages index only returns packages for the authenticated project', function () {
    [$projectA, $tokenA] = makeProjectWithToken();
    [$projectB] = makeProjectWithToken();

    $packagesA = Package::factory(2)->create();
    foreach ($packagesA as $package) {
        ProjectPackage::factory()->create(['project_id' => $projectA->id, 'package_id' => $package->id]);
    }

    $packagesB = Package::factory(5)->create();
    foreach ($packagesB as $package) {
        ProjectPackage::factory()->create(['project_id' => $projectB->id, 'package_id' => $package->id]);
    }

    $response = $this->withToken($tokenA)
        ->getJson("/api/projects/{$projectA->uuid}/packages")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

test('packages index filters by ecosystem', function () {
    [$project, $token] = makeProjectWithToken();

    $composerPackages = Package::factory(2)->create(['ecosystem' => EcosystemEnum::Composer->value]);
    foreach ($composerPackages as $package) {
        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id]);
    }

    $npmPackages = Package::factory(3)->create(['ecosystem' => EcosystemEnum::Npm->value]);
    foreach ($npmPackages as $package) {
        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id]);
    }

    $response = $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/packages?ecosystem=composer")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

test('packages index returns 422 for invalid ecosystem filter', function () {
    [$project, $token] = makeProjectWithToken();

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/packages?ecosystem=invalid")
        ->assertUnprocessable();
});

test('packages index returns 401 for unauthenticated request', function () {
    $project = Project::factory()->create();

    $this->getJson("/api/projects/{$project->uuid}/packages")
        ->assertUnauthorized();
});

test('packages index returns 403 when token belongs to different project', function () {
    [$projectA, $tokenA] = makeProjectWithToken();
    $projectB = Project::factory()->create();

    $this->withToken($tokenA)
        ->getJson("/api/projects/{$projectB->uuid}/packages")
        ->assertForbidden();
});

test('packages response includes expected fields', function () {
    [$project, $token] = makeProjectWithToken();

    $pkg = Package::factory()->create([
        'ecosystem' => EcosystemEnum::Composer->value,
        'name' => 'vendor/example',
    ]);
    $pp = ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.2.3',
        'is_direct' => true,
        'depth' => 0,
    ]);

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/packages")
        ->assertOk()
        ->assertJsonPath('data.0.id', $pp->uuid)
        ->assertJsonPath('data.0.name', 'vendor/example')
        ->assertJsonPath('data.0.version', '1.2.3')
        ->assertJsonPath('data.0.is_direct', true)
        ->assertJsonPath('data.0.ecosystem', 'composer')
        ->assertJsonMissingPath('data.0.project_id');
});
