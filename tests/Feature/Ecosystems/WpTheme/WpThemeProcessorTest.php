<?php

use App\Ecosystems\WpTheme\Processors\WpThemeProcessor;
use App\Enums\EcosystemEnum;
use App\Events\PackagePublishedEvent;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Support\Facades\Event;

function wpThemeProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('WpThemeProcessor inserts packages on first push', function (): void {
    $project = wpThemeProject();
    $processor = new WpThemeProcessor;

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'twentytwentyfour', 'version' => '1.2.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
    );

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(1)
        ->and(Package::where('name', 'twentytwentyfour')->exists())->toBeTrue();
});

test('WpThemeProcessor marks private packages', function (): void {
    $project = wpThemeProject();
    $processor = new WpThemeProcessor;

    Package::create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::WpTheme->value,
        'name' => 'my-theme',
    ]);

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'my-theme', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: ['my-theme'],
    );

    $perProject = Package::where('project_id', $project->id)
        ->where('name', 'my-theme')
        ->first();

    expect($perProject)->not->toBeNull()
        ->and($perProject->is_private)->toBeTrue();
});

test('WpThemeProcessor skips private package slug not found in DB', function (): void {
    $project = wpThemeProject();
    $processor = new WpThemeProcessor;

    // Slug 'ghost-theme' has no Package row — must not throw.
    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [],
        findings: [],
        advisories: [],
        privatePackages: ['ghost-theme'],
    );

    expect(Package::where('project_id', $project->id)->where('name', 'ghost-theme')->exists())->toBeFalse();
});

test('WpThemeProcessor updates already-per-project private theme in place', function (): void {
    $project = wpThemeProject();
    $processor = new WpThemeProcessor;

    $perProjectPkg = Package::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpTheme->value,
        'name' => 'my-local-theme',
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
            ['name' => 'my-local-theme', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: ['my-local-theme'],
    );

    $pkg = $perProjectPkg->fresh();

    expect($pkg->is_private)->toBeTrue()
        ->and($pkg->advisory_checked_at)->not->toBeNull();
});

test('WpThemeProcessor migrates a per-project private theme back to global when confirmed public', function (): void {
    Event::fake([PackagePublishedEvent::class]);

    $project = wpThemeProject();
    $processor = new WpThemeProcessor;

    $perProjectPkg = Package::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpTheme->value,
        'name' => 'twentytwentythree',
        'is_private' => true,
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
            ['name' => 'twentytwentythree', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: [],
        publicPackages: ['twentytwentythree'],
    );

    $global = Package::where('project_id', 0)
        ->where('ecosystem', EcosystemEnum::WpTheme->value)
        ->where('name', 'twentytwentythree')
        ->first();

    expect($global)->not->toBeNull()
        ->and($global->is_private)->toBeFalse()
        ->and(Package::where('project_id', $project->id)->where('name', 'twentytwentythree')->exists())->toBeFalse();

    Event::assertDispatched(PackagePublishedEvent::class);
});

test('WpThemeProcessor deletes orphaned global package after migrating to per-project', function (): void {
    $project = wpThemeProject();
    $processor = new WpThemeProcessor;

    $globalPkg = Package::create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::WpTheme->value,
        'name' => 'orphan-theme',
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $globalPkg->id,
        'version' => '1.0.0',
        'is_direct' => true,
        'is_dev' => false,
    ]);

    $processor->process(
        project: $project,
        payloadHash: 'hash1',
        packages: [
            ['name' => 'orphan-theme', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                'available_version' => null, 'author' => null],
        ],
        findings: [],
        advisories: [],
        privatePackages: ['orphan-theme'],
    );

    // Global package row must be gone — all project_packages now point to the per-project row.
    expect(Package::where('project_id', 0)->where('name', 'orphan-theme')->exists())->toBeFalse()
        ->and(Package::where('project_id', $project->id)->where('name', 'orphan-theme')->exists())->toBeTrue();
});
