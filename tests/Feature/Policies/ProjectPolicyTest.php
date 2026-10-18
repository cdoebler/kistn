<?php

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;

test('viewAny returns true for any user', function (): void {
    $user = User::factory()->create();

    expect((new ProjectPolicy)->viewAny($user))->toBeTrue();
});

test('create returns true for any user', function (): void {
    $user = User::factory()->create();

    expect((new ProjectPolicy)->create($user))->toBeTrue();
});

test('view returns true for project owner', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    expect((new ProjectPolicy)->view($user, $project))->toBeTrue();
});

test('view returns false for non-owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    expect((new ProjectPolicy)->view($other, $project))->toBeFalse();
});

test('update returns true for project owner', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    expect((new ProjectPolicy)->update($user, $project))->toBeTrue();
});

test('update returns false for non-owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    expect((new ProjectPolicy)->update($other, $project))->toBeFalse();
});

test('delete returns true for project owner', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    expect((new ProjectPolicy)->delete($user, $project))->toBeTrue();
});

test('delete returns false for non-owner', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    expect((new ProjectPolicy)->delete($other, $project))->toBeFalse();
});
