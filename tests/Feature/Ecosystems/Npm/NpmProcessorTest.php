<?php

use App\Ecosystems\Npm\Processors\NpmProcessor;
use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;

function npmProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('NpmProcessor inserts packages on first push', function (): void {
    $project = npmProject();
    $processor = new NpmProcessor;

    $result = $processor->process(
        project: $project,
        payloadHash: 'abc123',
        packages: [
            ['name' => '@scope/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect($result->ecosystem)->toBe(EcosystemEnum::Npm)
        ->and(ProjectPackage::where('project_id', $project->id)->count())->toBe(1)
        ->and(Package::where('name', '@scope/foo')->exists())->toBeTrue();
});

test('NpmProcessor skips processing when hash matches', function (): void {
    $project = npmProject();
    $processor = new NpmProcessor;

    $packages = [
        ['name' => 'lodash', 'version' => '4.17.21', 'is_direct' => true, 'is_dev' => false,
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

test('NpmProcessor returns new findings for new advisory', function (): void {
    $project = npmProject();
    $processor = new NpmProcessor;

    $result = $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'express', 'version' => '4.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [
            ['package_name' => 'express', 'package_version' => '4.0.0', 'advisory_id' => 'GHSA-npm-1', 'severity' => 'high'],
        ],
        advisories: [],
        privatePackages: [],
    );

    expect($result->newFindings)->toHaveCount(1)
        ->and($result->newFindings[0]['advisory_id'])->toBe('GHSA-npm-1');
});
