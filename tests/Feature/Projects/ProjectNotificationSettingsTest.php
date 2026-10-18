<?php

use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

test('notification settings card is visible on edit page', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertSee('Notifications');
});

test('owner can save notify immediately setting', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'settings' => null]);
    $this->actingAs($user);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('notifyImmediately', true)
        ->call('updateNotificationSettings')
        ->assertHasNoErrors();

    $fresh = $project->fresh();
    expect($fresh->notifyImmediately())->toBeTrue();
});

test('notify immediately defaults to false when unset', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'settings' => null]);
    $this->actingAs($user);

    $component = Livewire::test('pages::projects.edit', ['project' => $project]);

    expect($component->get('notifyImmediately'))->toBeFalse();
});
