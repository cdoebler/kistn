<?php

use App\Jobs\ProcessProjectInventoryJob;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\User;
use App\Models\VulnFinding;
use App\Notifications\ProjectChangesNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

function inventoryProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

function inventoryToken(Project $project): string
{
    return $project->createToken('test')->plainTextToken;
}

test('hashes endpoint returns null for ecosystems never pushed', function (): void {
    $project = inventoryProject();
    $token = inventoryToken($project);

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/hashes")
        ->assertOk()
        ->assertJsonPath('composer', null)
        ->assertJsonPath('npm', null);
});

test('hashes endpoint returns stored hash for a pushed ecosystem', function (): void {
    $project = inventoryProject();
    $token = inventoryToken($project);

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => 'composer',
        'current_hash' => 'abc123',
    ]);

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/hashes")
        ->assertOk()
        ->assertJsonPath('composer', 'abc123')
        ->assertJsonPath('npm', null);
});

test('inventory endpoint dispatches ProcessProjectInventory job', function (): void {
    Queue::fake();
    $project = inventoryProject();
    $token = inventoryToken($project);

    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/inventory", [
            'ecosystems' => [
                'composer' => [
                    'packages' => [
                        ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false],
                    ],
                    'findings' => [],
                ],
            ],
        ])
        ->assertAccepted();

    Queue::assertPushed(ProcessProjectInventoryJob::class);
});

test('inventory endpoint requires auth', function (): void {
    $project = inventoryProject();

    $this->postJson("/api/projects/{$project->uuid}/inventory", ['ecosystems' => []])
        ->assertUnauthorized();
});

test('old per-ecosystem hash route does not exist', function (): void {
    $project = inventoryProject();
    $token = inventoryToken($project);

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/ecosystems/composer/hash")
        ->assertNotFound();
});

test('inventory push rejects an oversized packages array', function (): void {
    config(['inventory.payload_limits.packages' => 2]);

    $project = inventoryProject();
    $token = inventoryToken($project);

    $packages = array_map(fn (int $i): array => [
        'name' => "vendor/pkg-{$i}", 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false,
        'is_active' => null, 'is_child' => null, 'depth' => 0, 'source_url' => null,
        'available_version' => null, 'author' => null,
    ], range(1, 3));

    $this->withToken($token)->postJson("/api/projects/{$project->uuid}/inventory", [
        'ecosystems' => ['composer' => ['packages' => $packages, 'findings' => [], 'private_packages' => [], 'advisories' => []]],
    ])->assertUnprocessable();
});

// ── Full chain: push → job → processor → dispatcher → notification ──────────
// QUEUE_CONNECTION=sync in phpunit.xml, so without Queue::fake() the job body
// actually runs inline — these hit the real endpoint end to end.

test('inventory push with a vuln finding is stored and triggers a notification', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create(['user_id' => $user->id]);
    $token = inventoryToken($project);

    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/inventory", [
            'ecosystems' => [
                'composer' => [
                    'packages' => [
                        ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false],
                    ],
                    'findings' => [
                        ['package_name' => 'vendor/foo', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-abc', 'severity' => 'high'],
                    ],
                ],
            ],
        ])
        ->assertAccepted();

    expect(VulnFinding::query()
        ->whereHas('package', fn ($q) => $q->where('name', 'vendor/foo'))
        ->where('advisory_id', 'GHSA-abc')
        ->exists())->toBeTrue();

    Notification::assertSentTo($user, ProjectChangesNotification::class, function (ProjectChangesNotification $notification): bool {
        $findings = $notification->payload->findingsByEcosystem['composer'] ?? [];

        return collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-abc');
    });
});

test('second push with unchanged packages but a new finding still stores and notifies', function (): void {
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create(['user_id' => $user->id]);
    $token = inventoryToken($project);

    $packages = [
        ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false],
    ];

    // First push: packages only, no findings yet.
    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/inventory", [
            'ecosystems' => ['composer' => ['packages' => $packages, 'findings' => []]],
        ])
        ->assertAccepted();

    Notification::assertNothingSent();

    // Second push: identical package list (same content hash), but a new advisory appeared.
    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/inventory", [
            'ecosystems' => ['composer' => ['packages' => $packages, 'findings' => [
                ['package_name' => 'vendor/foo', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-new', 'severity' => 'critical'],
            ]]],
        ])
        ->assertAccepted();

    expect(VulnFinding::query()
        ->whereHas('package', fn ($q) => $q->where('name', 'vendor/foo'))
        ->where('advisory_id', 'GHSA-new')
        ->exists())->toBeTrue();

    Notification::assertSentTo($user, ProjectChangesNotification::class, function (ProjectChangesNotification $notification): bool {
        $findings = $notification->payload->findingsByEcosystem['composer'] ?? [];

        return collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-new');
    });
});
