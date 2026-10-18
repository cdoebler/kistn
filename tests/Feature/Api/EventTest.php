<?php

use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use App\Models\InventoryEvent;
use App\Models\Project;
use App\Models\User;

function makeProjectWithEventToken(): array
{
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $token = $project->createToken('test')->plainTextToken;

    return [$project, $token];
}

test('events index returns paginated events for project', function () {
    [$project, $token] = makeProjectWithEventToken();

    InventoryEvent::factory(4)->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
    ]);

    $response = $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/events")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(4);
});

test('events index only returns events for the authenticated project', function () {
    [$projectA, $tokenA] = makeProjectWithEventToken();
    [$projectB] = makeProjectWithEventToken();

    InventoryEvent::factory(2)->create(['project_id' => $projectA->id]);
    InventoryEvent::factory(5)->create(['project_id' => $projectB->id]);

    $response = $this->withToken($tokenA)
        ->getJson("/api/projects/{$projectA->uuid}/events")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

test('events index filters by ecosystem', function () {
    [$project, $token] = makeProjectWithEventToken();

    InventoryEvent::factory(2)->create(['project_id' => $project->id, 'ecosystem' => EcosystemEnum::Composer->value]);
    InventoryEvent::factory(3)->create(['project_id' => $project->id, 'ecosystem' => EcosystemEnum::Npm->value]);

    $response = $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/events?ecosystem=npm")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(3);
});

test('events index returns 422 for invalid ecosystem filter', function () {
    [$project, $token] = makeProjectWithEventToken();

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/events?ecosystem=bad")
        ->assertUnprocessable();
});

test('events index returns 401 for unauthenticated request', function () {
    $project = Project::factory()->create();

    $this->getJson("/api/projects/{$project->uuid}/events")
        ->assertUnauthorized();
});

test('events index returns 403 when token belongs to different project', function () {
    [$projectA, $tokenA] = makeProjectWithEventToken();
    $projectB = Project::factory()->create();

    $this->withToken($tokenA)
        ->getJson("/api/projects/{$projectB->uuid}/events")
        ->assertForbidden();
});

test('events response includes expected fields', function () {
    [$project, $token] = makeProjectWithEventToken();

    $event = InventoryEvent::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'package_name' => 'vendor/example',
        'action' => InventoryActionEnum::Added->value,
        'old_version' => null,
        'new_version' => '1.0.0',
    ]);

    $this->withToken($token)
        ->getJson("/api/projects/{$project->uuid}/events")
        ->assertOk()
        ->assertJsonPath('data.0.id', $event->uuid)
        ->assertJsonPath('data.0.package_name', 'vendor/example')
        ->assertJsonPath('data.0.action', 'added')
        ->assertJsonPath('data.0.ecosystem', 'composer')
        ->assertJsonMissingPath('data.0.project_id');
});
