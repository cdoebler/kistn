<?php

use App\Enums\LocaleEnum;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->set('timezone', 'Europe/Berlin')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
    expect($user->timezone())->toBe('Europe/Berlin');
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->set('timezone', 'UTC')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('deleting an account removes owned projects, their tokens, and stored files', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $token = $project->createToken('test');
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'contents');

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    expect($project->fresh())->toBeNull();
    expect(PersonalAccessToken::find($token->accessToken->id))->toBeNull();
    Storage::disk('local')->assertMissing("projects/{$project->uuid}/composer.lock.gz");
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});

test('locale override can be set and persists', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('timezone', 'UTC')
        ->set('locale', 'de')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->localeOverride())->toBe(LocaleEnum::German);
});

test('locale can be reset to automatic', function () {
    $user = User::factory()->create(['settings' => ['locale' => 'de']]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', $user->email)
        ->set('timezone', 'UTC')
        ->set('locale', '')
        ->call('updateProfileInformation');

    expect($user->refresh()->localeOverride())->toBeNull();
});
