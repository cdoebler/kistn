<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('inventory push is rate limited per token', function (): void {
    config(['inventory.rate_limits.inventory' => 2]);

    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $token = $project->createToken('test')->plainTextToken;

    $payload = ['ecosystems' => ['composer' => ['packages' => [], 'findings' => []]]];

    $this->withToken($token)->postJson("/api/projects/{$project->uuid}/inventory", $payload)->assertAccepted();
    $this->withToken($token)->postJson("/api/projects/{$project->uuid}/inventory", $payload)->assertAccepted();
    $this->withToken($token)->postJson("/api/projects/{$project->uuid}/inventory", $payload)
        ->assertStatus(429);
});

test('preflight requests are rate limited per token', function (): void {
    config(['inventory.rate_limits.preflight' => 1]);

    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $token = $project->createToken('test')->plainTextToken;

    $payload = ['packages' => [['ecosystem' => 'wp-plugin', 'name' => 'sample-plugin', 'version' => '1.0.0']]];

    $this->withToken($token)->postJson("/api/projects/{$project->uuid}/preflight/wp", $payload)->assertOk();
    $this->withToken($token)->postJson("/api/projects/{$project->uuid}/preflight/wp", $payload)
        ->assertStatus(429);
});

test('rate limiting is isolated per project token', function (): void {
    config(['inventory.rate_limits.inventory' => 1]);

    $projectA = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $tokenA = $projectA->createToken('test')->plainTextToken;

    $projectB = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $tokenB = $projectB->createToken('test')->plainTextToken;

    $payload = ['ecosystems' => ['composer' => ['packages' => [], 'findings' => []]]];

    // Exhaust project A's limit.
    $this->withToken($tokenA)->postJson("/api/projects/{$projectA->uuid}/inventory", $payload)->assertAccepted();
    $this->withToken($tokenA)->postJson("/api/projects/{$projectA->uuid}/inventory", $payload)->assertStatus(429);

    // ponytail: Sanctum's guard caches the resolved user for the life of the app container,
    // which Pest reuses across requests within one test — forget it so switching tokens
    // mid-test actually re-authenticates as project B instead of returning stale project A.
    $this->app['auth']->forgetGuards();

    // Project B is unaffected.
    $this->withToken($tokenB)->postJson("/api/projects/{$projectB->uuid}/inventory", $payload)->assertAccepted();
});

test('file upload is rate limited per token', function (): void {
    Queue::fake();
    Storage::fake('local');

    config(['inventory.rate_limits.files' => 1]);

    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $token = $project->createToken('test')->plainTextToken;

    $file = fn () => ['composer_lock' => UploadedFile::fake()->create('composer.lock.gz', 5)];

    $this->withToken($token)
        ->post("/api/projects/{$project->uuid}/files", $file(), ['Accept' => 'application/json'])
        ->assertAccepted();

    $this->withToken($token)
        ->post("/api/projects/{$project->uuid}/files", $file(), ['Accept' => 'application/json'])
        ->assertStatus(429);
});
