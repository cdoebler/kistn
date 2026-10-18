<?php

use App\Enums\EcosystemEnum;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $project = Project::factory()->create();
    $this->get(route('projects.edit', $project))->assertRedirect(route('login'));
});

test('project owner can view edit page', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))->assertOk();
});

test('non-owner cannot view project edit page', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other);
    $this->get(route('projects.edit', $project))->assertForbidden();
});

test('owner can update project name', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);
    $this->actingAs($user);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('name', 'New Name')
        ->call('updateName')
        ->assertHasNoErrors();

    expect($project->fresh()->name)->toBe('New Name');
});

test('project name cannot be empty', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('name', '')
        ->call('updateName')
        ->assertHasErrors(['name' => 'required']);
});

test('token list is displayed', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $project->createToken('ci-token');
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertSee('ci-token');
});

test('owner can create a token and receives plain text once', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $component = Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('tokenName', 'deploy-key')
        ->call('createToken')
        ->assertHasNoErrors();

    expect($project->tokens()->where('name', 'deploy-key')->exists())->toBeTrue();
    expect($component->get('newTokenPlainText'))->not->toBeNull();
});

test('token name is required', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('tokenName', '')
        ->call('createToken')
        ->assertHasErrors(['tokenName' => 'required']);
});

test('dismissing token clears plain text', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $component = Livewire::test('pages::projects.edit', ['project' => $project])
        ->set('tokenName', 'my-token')
        ->call('createToken');

    $component->call('dismissToken');

    expect($component->get('newTokenPlainText'))->toBeNull();
});

test('owner can revoke a token', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $token = $project->createToken('old-token');
    $tokenId = $token->accessToken->id;
    $this->actingAs($user);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->call('revokeToken', $tokenId)
        ->assertHasNoErrors();

    expect($project->tokens()->find($tokenId))->toBeNull();
});

test('cannot revoke a token belonging to another project', function () {
    $user = User::factory()->create();
    $projectA = Project::factory()->create(['user_id' => $user->id]);
    $projectB = Project::factory()->create(['user_id' => $user->id]);
    $tokenB = $projectB->createToken('other-token');
    $tokenBId = $tokenB->accessToken->id;
    $this->actingAs($user);

    Livewire::test('pages::projects.edit', ['project' => $projectA])
        ->call('revokeToken', $tokenBId);

    expect($projectB->tokens()->find($tokenBId))->not->toBeNull();
});

test('owner can delete project and is redirected', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test('pages::projects.edit', ['project' => $project])
        ->call('deleteProject')
        ->assertRedirect(route('projects.index'));

    expect($project->fresh())->toBeNull();
});

test('audit section hidden when project has no ecosystems yet', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertDontSee('Composer audit')
        ->assertDontSee('npm audit');
});

test('audit section hidden when project only has wp ecosystems', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::WpPlugin->value,
        'current_hash' => 'hash',
        'package_count' => 1,
    ]);
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertDontSee('Composer audit')
        ->assertDontSee('npm audit');
});

test('audit section shows only composer checkbox for composer-only project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'current_hash' => 'hash',
        'package_count' => 1,
    ]);
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertSee('Composer audit')
        ->assertDontSee('npm audit');
});

test('audit section shows only npm checkbox for npm-only project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'current_hash' => 'hash',
        'package_count' => 1,
    ]);
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertSee('npm audit')
        ->assertDontSee('Composer audit');
});

test('audit section shows both checkboxes when project has composer and npm', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'current_hash' => 'hash-c',
        'package_count' => 1,
    ]);
    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'current_hash' => 'hash-n',
        'package_count' => 1,
    ]);
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertSee('Composer audit')
        ->assertSee('npm audit');
});

test('edit page links to packages page', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->get(route('projects.edit', $project))
        ->assertOk()
        ->assertSee(route('projects.packages', $project));
});
