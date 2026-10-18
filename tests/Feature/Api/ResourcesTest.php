<?php

use App\Models\Project;

// EnsureTokenBelongsToProject middleware cross-project rejection

test('token from project A is rejected for project B route', function (): void {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    $tokenA = $projectA->createToken('test')->plainTextToken;

    $this->withToken($tokenA)
        ->getJson("/api/projects/{$projectB->uuid}/hashes")
        ->assertForbidden();
});

test('token from project A is accepted for project A route', function (): void {
    $project = Project::factory()->create();
    $token = $project->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/hashes")
        ->assertOk();
});

// StoreProjectInventoryRequest — unknown ecosystem key

test('inventory endpoint rejects unknown ecosystem key with error on that key', function (): void {
    $project = Project::factory()->create();
    $token = $project->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/inventory", [
            'ecosystems' => [
                'fakeecosystem' => ['packages' => [], 'findings' => []],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonFragment(['ecosystems.fakeecosystem' => ['Unknown ecosystem: fakeecosystem.']]);
});
