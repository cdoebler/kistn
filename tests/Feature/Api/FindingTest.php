<?php

use App\Enums\EcosystemEnum;
use App\Enums\SeverityEnum;
use App\Enums\VulnSourceEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;

function makeProjectWithFindingToken(): array
{
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $token = $project->createToken('test')->plainTextToken;

    return [$project, $token];
}

function attachPackageWithFinding(Project $project, EcosystemEnum $ecosystem, string $version, array $findingOverrides = []): VulnFinding
{
    $package = Package::factory()->forEcosystem($ecosystem)->create();
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => $version]);

    return VulnFinding::factory()->create(array_merge([
        'package_id' => $package->id,
        'package_version' => $version,
    ], $findingOverrides));
}

test('findings index returns paginated findings for installed packages', function () {
    [$project, $token] = makeProjectWithFindingToken();

    attachPackageWithFinding($project, EcosystemEnum::Composer, '1.0.0');
    attachPackageWithFinding($project, EcosystemEnum::Composer, '2.0.0');
    attachPackageWithFinding($project, EcosystemEnum::Composer, '3.0.0');

    $response = $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/findings")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(3);
});

test('findings index only returns findings for packages the authenticated project has installed', function () {
    [$projectA, $tokenA] = makeProjectWithFindingToken();
    [$projectB] = makeProjectWithFindingToken();

    attachPackageWithFinding($projectA, EcosystemEnum::Composer, '1.0.0');
    attachPackageWithFinding($projectB, EcosystemEnum::Composer, '1.0.0');
    attachPackageWithFinding($projectB, EcosystemEnum::Composer, '2.0.0');

    $response = $this->withToken($tokenA)
        ->getJson("/api/projects/{$projectA->uuid}/findings")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

test('findings index filters by ecosystem', function () {
    [$project, $token] = makeProjectWithFindingToken();

    attachPackageWithFinding($project, EcosystemEnum::Composer, '1.0.0');
    attachPackageWithFinding($project, EcosystemEnum::Npm, '1.0.0');

    $response = $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/findings?ecosystem=composer")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1);
});

test('findings index returns 422 for invalid ecosystem filter', function () {
    [$project, $token] = makeProjectWithFindingToken();

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/findings?ecosystem=bad")
        ->assertUnprocessable();
});

test('findings index returns 401 for unauthenticated request', function () {
    $project = Project::factory()->create();

    $this->getJson("/api/projects/{$project->uuid}/findings")
        ->assertUnauthorized();
});

test('findings index returns 403 when token belongs to different project', function () {
    [$projectA, $tokenA] = makeProjectWithFindingToken();
    $projectB = Project::factory()->create();

    $this->withToken($tokenA)
        ->getJson("/api/projects/{$projectB->uuid}/findings")
        ->assertForbidden();
});

test('findings response includes expected fields', function () {
    [$project, $token] = makeProjectWithFindingToken();

    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/vulnerable']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => '1.0.0']);

    $finding = VulnFinding::factory()->create([
        'package_id' => $package->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-test-0001-xxxx',
        'severity' => SeverityEnum::High->value,
        'source' => VulnSourceEnum::ClientAudit->value,
    ]);

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/findings")
        ->assertOk()
        ->assertJsonPath('data.0.id', $finding->uuid)
        ->assertJsonPath('data.0.package_name', 'vendor/vulnerable')
        ->assertJsonPath('data.0.advisory_id', 'GHSA-test-0001-xxxx')
        ->assertJsonPath('data.0.severity', 'high');
});
