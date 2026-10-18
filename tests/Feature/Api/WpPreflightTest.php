<?php

use App\Enums\EcosystemEnum;
use App\Models\AdvisorySnapshot;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;

function makePreflightProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

function preflightToken(Project $project): string
{
    return $project->createToken('test')->plainTextToken;
}

test('preflight returns all slugs as stale when advisory_snapshots is empty', function () {
    $project = makePreflightProject();
    $token = preflightToken($project);

    $response = $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/preflight/wp", [
            'packages' => [
                ['ecosystem' => 'wp-plugin', 'name' => 'woocommerce',  'version' => '8.0.0'],
                ['ecosystem' => 'wp-theme',  'name' => 'twentyfour',   'version' => '1.0'],
                ['ecosystem' => 'wp-core',   'name' => 'wordpress',    'version' => '6.4.2'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonCount(3, 'stale')
        ->assertJsonCount(0, 'advisories')
        ->assertJsonFragment(['ecosystem' => 'wp-plugin', 'name' => 'woocommerce'])
        ->assertJsonFragment(['ecosystem' => 'wp-theme',  'name' => 'twentyfour'])
        ->assertJsonFragment(['ecosystem' => 'wp-core',   'name' => 'wordpress']);
});

test('preflight returns fresh cached advisories for non-expired snapshot', function () {
    $project = makePreflightProject();
    $token = preflightToken($project);
    $snapshot = AdvisorySnapshot::factory()
        ->forEcosystem(EcosystemEnum::WpPlugin, 'woocommerce')
        ->withAdvisories()
        ->create();

    $response = $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/preflight/wp", [
            'packages' => [
                ['ecosystem' => 'wp-plugin', 'name' => 'woocommerce', 'version' => '8.0.0'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonCount(0, 'stale')
        ->assertJsonCount(1, 'advisories')
        ->assertJsonPath('advisories.0.name', 'woocommerce')
        ->assertJsonPath('advisories.0.expires_at', $snapshot->expires_at->toIso8601String());
});

test('preflight returns stale for expired snapshot', function () {
    $project = makePreflightProject();
    $token = preflightToken($project);
    AdvisorySnapshot::factory()
        ->forEcosystem(EcosystemEnum::WpPlugin, 'bad-plugin')
        ->expired()
        ->create();

    $response = $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/preflight/wp", [
            'packages' => [
                ['ecosystem' => 'wp-plugin', 'name' => 'bad-plugin', 'version' => '1.0.0'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonCount(1, 'stale')
        ->assertJsonCount(0, 'advisories');
});

test('preflight returns fresh entry with empty advisories for clean slug', function () {
    $project = makePreflightProject();
    $token = preflightToken($project);
    AdvisorySnapshot::factory()
        ->forEcosystem(EcosystemEnum::WpPlugin, 'clean-plugin')
        ->create(['advisories' => []]);

    $response = $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/preflight/wp", [
            'packages' => [
                ['ecosystem' => 'wp-plugin', 'name' => 'clean-plugin', 'version' => '1.0.0'],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonCount(0, 'stale')
        ->assertJsonCount(1, 'advisories')
        ->assertJsonPath('advisories.0.advisories', []);
});

test('preflight returns 422 for invalid ecosystem', function () {
    $project = makePreflightProject();
    $token = preflightToken($project);

    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/preflight/wp", [
            'packages' => [
                ['ecosystem' => 'composer', 'name' => 'vendor/pkg', 'version' => '1.0.0'],
            ],
        ])
        ->assertStatus(422);
});

test('preflight returns 401 without token', function () {
    $project = makePreflightProject();

    $this->postJson("/api/projects/{$project->uuid}/preflight/wp", [
        'packages' => [],
    ])
        ->assertStatus(401);
});

test('preflight rejects an oversized packages array', function () {
    config(['inventory.payload_limits.packages' => 2]);

    $project = makePreflightProject();
    $token = preflightToken($project);

    $packages = array_map(fn (int $i): array => [
        'ecosystem' => 'wp-plugin', 'name' => "plugin-{$i}", 'version' => '1.0.0',
    ], range(1, 3));

    $this->withToken($token)->postJson("/api/projects/{$project->uuid}/preflight/wp", [
        'packages' => $packages,
    ])->assertUnprocessable();
});

test('preflight includes private list for packages marked is_private=true', function () {
    $project = makePreflightProject();
    $token = preflightToken($project);

    $privatePackage = Package::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'private-theme',
        'is_private' => true,
    ]);

    $publicPackage = Package::factory()->create([
        'project_id' => 0,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'name' => 'akismet',
        'is_private' => false,
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $privatePackage->id,
    ]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $publicPackage->id,
    ]);

    $response = $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/preflight/wp", [
            'packages' => [
                ['ecosystem' => 'wp-plugin', 'name' => 'private-theme', 'version' => '1.0.0'],
                ['ecosystem' => 'wp-plugin', 'name' => 'akismet', 'version' => '5.3.1'],
            ],
        ]);

    $response->assertOk()
        ->assertJsonCount(1, 'private')
        ->assertJsonPath('private.0', 'private-theme');
});
