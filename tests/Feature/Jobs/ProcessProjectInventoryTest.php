<?php

use App\Ecosystems\Wp\Jobs\WpOutdatedJob;
use App\Jobs\FinalizeProjectWpNotificationJob;
use App\Jobs\ProcessProjectInventoryJob;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;
use App\Notifications\ProjectChangesNotification;
use App\Services\NotificationDispatcherService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

function projInventoryProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

test('ProcessProjectInventory inserts packages for composer ecosystem', function (): void {
    $project = projInventoryProject();

    (new ProcessProjectInventoryJob($project, [
        'composer' => [
            'packages' => [
                ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                    'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                    'available_version' => null, 'author' => null],
            ],
            'findings' => [],
            'private_packages' => [],
            'advisories' => [],
        ],
    ]))->handle(resolve(NotificationDispatcherService::class));

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(1)
        ->and(Package::where('name', 'vendor/foo')->exists())->toBeTrue();
});

test('ProcessProjectInventory dispatches WP chain for wp-plugin ecosystem', function (): void {
    Bus::fake([WpOutdatedJob::class, FinalizeProjectWpNotificationJob::class]);

    $project = projInventoryProject();

    (new ProcessProjectInventoryJob($project, [
        'wp-plugin' => [
            'packages' => [
                ['name' => 'akismet', 'version' => '5.0.0', 'is_direct' => true, 'is_dev' => false,
                    'is_active' => true, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                    'available_version' => null, 'author' => null],
            ],
            'findings' => [],
            'private_packages' => [],
            'advisories' => [],
        ],
    ]))->handle(resolve(NotificationDispatcherService::class));

    Bus::assertChained([WpOutdatedJob::class, FinalizeProjectWpNotificationJob::class]);
});

test('ProcessProjectInventory calls NotificationDispatcherService for non-WP ecosystems', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['notify_immediately' => true],
    ]);

    (new ProcessProjectInventoryJob($project, [
        'composer' => [
            'packages' => [
                ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                    'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                    'available_version' => '2.0.0', 'author' => null],
            ],
            'findings' => [],
            'private_packages' => [],
            'advisories' => [],
        ],
    ]))->handle(resolve(NotificationDispatcherService::class));

    Notification::assertSentTo($user, ProjectChangesNotification::class);
});

test('ProcessProjectInventory notifies for a vuln finding on the first push', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    $ecosystems = [
        'composer' => [
            'packages' => [
                ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                    'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                    'available_version' => null, 'author' => null],
            ],
            'findings' => [
                ['package_name' => 'vendor/foo', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-abc', 'severity' => 'high'],
            ],
            'private_packages' => [],
            'advisories' => [],
        ],
    ];

    (new ProcessProjectInventoryJob($project, $ecosystems))->handle(resolve(NotificationDispatcherService::class));

    Notification::assertSentTo($user, ProjectChangesNotification::class, function (ProjectChangesNotification $notification): bool {
        $findings = $notification->payload->findingsByEcosystem['composer'] ?? [];

        return collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-abc');
    });
});

test('ProcessProjectInventory notifies when a second push has the same packages but a new finding', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    $packages = [
        ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
            'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
            'available_version' => null, 'author' => null],
    ];

    (new ProcessProjectInventoryJob($project, [
        'composer' => ['packages' => $packages, 'findings' => [], 'private_packages' => [], 'advisories' => []],
    ]))->handle(resolve(NotificationDispatcherService::class));

    Notification::assertNothingSent();

    (new ProcessProjectInventoryJob($project, [
        'composer' => ['packages' => $packages, 'findings' => [
            ['package_name' => 'vendor/foo', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-new', 'severity' => 'critical'],
        ], 'private_packages' => [], 'advisories' => []],
    ]))->handle(resolve(NotificationDispatcherService::class));

    expect($project->vulnFindings()->where('advisory_id', 'GHSA-new')->exists())->toBeTrue();

    Notification::assertSentTo($user, ProjectChangesNotification::class, function (ProjectChangesNotification $notification): bool {
        $findings = $notification->payload->findingsByEcosystem['composer'] ?? [];

        return collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-new');
    });
});

test('ProcessProjectInventory drops a finding whose package cannot be resolved', function (): void {
    $project = projInventoryProject();

    (new ProcessProjectInventoryJob($project, [
        'composer' => [
            'packages' => [
                ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                    'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                    'available_version' => null, 'author' => null],
            ],
            'findings' => [
                ['package_name' => 'vendor/unknown', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-orphan', 'severity' => 'high'],
            ],
            'private_packages' => [],
            'advisories' => [],
        ],
    ]))->handle(resolve(NotificationDispatcherService::class));

    expect(VulnFinding::where('advisory_id', 'GHSA-orphan')->exists())->toBeFalse();
});

test('ProcessProjectInventory drops findings without a real advisory id', function (string $advisoryId): void {
    $project = projInventoryProject();

    (new ProcessProjectInventoryJob($project, [
        'npm' => [
            'packages' => [
                ['name' => 'fast-glob', 'version' => '3.3.1', 'is_direct' => false, 'is_dev' => true,
                    'is_active' => null, 'is_child' => null, 'depth' => 2, 'source_url' => null,
                    'available_version' => null, 'author' => null],
                ['name' => 'braces', 'version' => '3.0.3', 'is_direct' => false, 'is_dev' => true,
                    'is_active' => null, 'is_child' => null, 'depth' => 4, 'source_url' => null,
                    'available_version' => null, 'author' => null],
            ],
            'findings' => [
                ['package_name' => 'fast-glob', 'package_version' => '3.3.1', 'advisory_id' => $advisoryId, 'severity' => 'high'],
                ['package_name' => 'braces', 'package_version' => '3.0.3', 'advisory_id' => 'GHSA-vfj7-8cjw-p6xm', 'severity' => 'high'],
            ],
            'private_packages' => [],
            'advisories' => [],
        ],
    ]))->handle(resolve(NotificationDispatcherService::class));

    expect(VulnFinding::pluck('advisory_id')->all())->toBe(['GHSA-vfj7-8cjw-p6xm']);
})->with(['unknown', 'UNKNOWN', ' ']);

test('ProcessProjectInventory silently skips unknown ecosystem key', function (): void {
    $project = projInventoryProject();

    // Should not throw — unknown key is logged and skipped
    (new ProcessProjectInventoryJob($project, [
        'fakeecosystem' => [
            'packages' => [
                ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
                    'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
                    'available_version' => null, 'author' => null],
            ],
            'findings' => [],
            'private_packages' => [],
            'advisories' => [],
        ],
    ]))->handle(resolve(NotificationDispatcherService::class));

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBe(0);
});
