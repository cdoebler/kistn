<?php

use App\Models\Project;
use App\Models\User;

test('notifyImmediately returns false by default', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    expect($project->notifyImmediately())->toBeFalse();
});

test('notifyImmediately returns true when setting is enabled', function (): void {
    $project = Project::factory()->create([
        'user_id' => User::factory()->create()->id,
        'settings' => ['notify_immediately' => true],
    ]);
    expect($project->notifyImmediately())->toBeTrue();
});

test('last_notified_at is castable to datetime', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);
    $project->update(['last_notified_at' => now()]);
    expect($project->fresh()->last_notified_at)->not->toBeNull();
});
