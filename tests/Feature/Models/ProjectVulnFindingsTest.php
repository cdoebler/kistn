<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;

function projectWithPackage(string $version): array
{
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/pkg']);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => $version]);

    return [$project, $package];
}

test('vulnFindings returns a finding when the installed version matches exactly', function (): void {
    [$project, $package] = projectWithPackage('1.0.0');

    VulnFinding::factory()->create(['package_id' => $package->id, 'package_version' => '1.0.0']);

    expect($project->vulnFindings()->count())->toBe(1);
});

test('vulnFindings excludes a finding when the installed version differs', function (): void {
    [$project, $package] = projectWithPackage('2.0.0');

    VulnFinding::factory()->create(['package_id' => $package->id, 'package_version' => '1.0.0']);

    expect($project->vulnFindings()->count())->toBe(0);
});

test('vulnFindings includes a wildcard finding regardless of installed version', function (): void {
    [$project, $package] = projectWithPackage('2.0.0');

    VulnFinding::factory()->create(['package_id' => $package->id, 'package_version' => '*']);

    expect($project->vulnFindings()->count())->toBe(1);
});

test('vulnFindings excludes findings for packages this project does not have installed', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/other']);

    VulnFinding::factory()->create(['package_id' => $package->id, 'package_version' => '1.0.0']);

    expect($project->vulnFindings()->count())->toBe(0);
});
