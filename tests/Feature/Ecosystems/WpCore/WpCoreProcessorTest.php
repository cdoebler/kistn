<?php

use App\Ecosystems\WpCore\Processors\WpCoreProcessor;
use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;

function wpCoreProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('WpCoreProcessor inserts packages on first push', function (): void {
    $project = wpCoreProject();
    $processor = new WpCoreProcessor;

    $result = $processor->process(
        project: $project,
        payloadHash: 'abc123',
        packages: [
            ['name' => 'wordpress', 'version' => '6.5.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(1)
        ->and(Package::where('name', 'wordpress')->exists())->toBeTrue();
});

test('WpCoreProcessor returns ecosystem WpCore', function (): void {
    $project = wpCoreProject();
    $processor = new WpCoreProcessor;

    $result = $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'wordpress', 'version' => '6.5.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect($result->ecosystem)->toBe(EcosystemEnum::WpCore);
});

test('WpCoreProcessor registryUrl returns wordpress.org releases page', function (): void {
    expect(WpCoreProcessor::registryUrl('wordpress'))->toBe('https://wordpress.org/download/releases/');
});

test('WpCoreProcessor marks a newly created package public', function (): void {
    $project = wpCoreProject();
    $processor = new WpCoreProcessor;

    $processor->process(
        project: $project,
        payloadHash: 'abc123',
        packages: [
            ['name' => 'wordpress', 'version' => '6.5.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    $package = Package::where('name', 'wordpress')->firstOrFail();

    expect($package->is_private)->toBeFalse()
        ->and($package->advisory_checked_at)->not->toBeNull();
});

test('WpCoreProcessor keeps confirming an existing package public on subsequent pushes', function (): void {
    $project = wpCoreProject();
    $processor = new WpCoreProcessor;

    $processor->process(
        project: $project,
        payloadHash: 'abc123',
        packages: [
            ['name' => 'wordpress', 'version' => '6.5.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    Package::where('name', 'wordpress')->update(['is_private' => null, 'advisory_checked_at' => null]);

    $processor->process(
        project: $project,
        payloadHash: 'def456',
        packages: [
            ['name' => 'wordpress', 'version' => '6.5.1', 'is_direct' => true, 'is_dev' => false,
                'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    $package = Package::where('name', 'wordpress')->firstOrFail();

    expect($package->is_private)->toBeFalse()
        ->and($package->advisory_checked_at)->not->toBeNull();
});
