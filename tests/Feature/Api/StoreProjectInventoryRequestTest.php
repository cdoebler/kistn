<?php

use App\Models\Project;
use App\Models\User;

function projectWithToken(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $token = $project->createToken('test')->plainTextToken;

    return [$project, $token];
}

test('StoreProjectInventoryRequest rejects missing ecosystems key', function (): void {
    [$project, $token] = projectWithToken();

    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/inventory", [])
        ->assertUnprocessable();
});

test('StoreProjectInventoryRequest rejects unknown ecosystem key', function (): void {
    [$project, $token] = projectWithToken();

    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/inventory", [
            'ecosystems' => ['unknown-eco' => ['packages' => [], 'findings' => []]],
        ])
        ->assertUnprocessable();
});

test('StoreProjectInventoryRequest accepts valid multi-ecosystem payload', function (): void {
    [$project, $token] = projectWithToken();

    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/inventory", [
            'ecosystems' => [
                'composer' => [
                    'packages' => [
                        ['name' => 'vendor/foo', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false],
                    ],
                    'findings' => [],
                ],
                'npm' => [
                    'packages' => [],
                    'findings' => [],
                ],
            ],
        ])
        ->assertAccepted();
});
