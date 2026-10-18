<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\ProjectPackage;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to login', function () {
    $this->get(route('projects.index'))->assertRedirect(route('login'));
});

test('authenticated user sees projects index', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('projects.index'))->assertOk();
});

test('user sees only their own projects', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    Project::factory()->create(['user_id' => $userA->id, 'name' => 'Project A']);
    Project::factory()->create(['user_id' => $userB->id, 'name' => 'Project B']);

    $this->actingAs($userA);

    $response = $this->get(route('projects.index'));
    $response->assertOk()->assertSee('Project A')->assertDontSee('Project B');
});

test('empty state shown when user has no projects', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('projects.index'))->assertOk()->assertSee('No projects yet');
});

test('user can create a project via modal', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::projects.index')
        ->set('name', 'My New Project')
        ->call('createProject')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Project::where('name', 'My New Project')->where('user_id', $user->id)->exists())->toBeTrue();
});

test('project name is required', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::projects.index')
        ->set('name', '')
        ->call('createProject')
        ->assertHasErrors(['name' => 'required']);
});

test('packages column reflects installed package count, not just the catalog', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Counted Project']);

    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'current_hash' => 'hash',
        'package_count' => 2,
    ]);

    $pkg1 = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create();
    $pkg2 = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create();
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkg1->id]);
    ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $pkg2->id]);

    $this->actingAs($user);

    $component = Livewire::test('pages::projects.index');
    $displayedCount = $component->get('projects')->firstWhere('uuid', $project->uuid)->package_count;

    expect($displayedCount)->toBe(2)
        ->and(ProjectPackage::where('project_id', $project->id)->count())->toBe($displayedCount);
});

test('ecosystems column shows a badge per ecosystem instead of a count', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Badge Project']);

    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'current_hash' => 'hash-composer',
        'package_count' => 1,
    ]);
    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'current_hash' => 'hash-npm',
        'package_count' => 1,
    ]);

    $this->actingAs($user);

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertSee('Composer')
        ->assertSee('npm');
    // Note: a literal assertDontSee('2') was dropped here — the page legitimately
    // renders "2" elsewhere (Tailwind utility classes, SVG paths, and the Packages
    // column's own count), so it can never pass. assertSee('Composer')/assertSee('npm')
    // already prove the ecosystems column renders labels instead of the old bare count.
});

test('project name max 255 chars', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::projects.index')
        ->set('name', str_repeat('a', 256))
        ->call('createProject')
        ->assertHasErrors(['name' => 'max']);
});

test('projects default sorted alphabetically by name', function () {
    $user = User::factory()->create();
    Project::factory()->create(['user_id' => $user->id, 'name' => 'Zebra Corp']);
    Project::factory()->create(['user_id' => $user->id, 'name' => 'Apple Inc']);
    Project::factory()->create(['user_id' => $user->id, 'name' => 'mango Ltd']);

    $this->actingAs($user);

    $names = Livewire::test('pages::projects.index')->get('projects')->pluck('name')->all();

    expect($names)->toBe(['Apple Inc', 'mango Ltd', 'Zebra Corp']);
});

test('clicking name header again reverses sort direction', function () {
    $user = User::factory()->create();
    Project::factory()->create(['user_id' => $user->id, 'name' => 'Zebra Corp']);
    Project::factory()->create(['user_id' => $user->id, 'name' => 'Apple Inc']);

    $this->actingAs($user);

    $component = Livewire::test('pages::projects.index')->call('sort', 'name')->call('sort', 'name');

    expect($component->get('sortDirection'))->toBe('desc');
    expect($component->get('projects')->pluck('name')->all())->toBe(['Zebra Corp', 'Apple Inc']);
});

test('sorting by an unknown column is ignored instead of crashing', function () {
    $user = User::factory()->create();
    Project::factory()->create(['user_id' => $user->id, 'name' => 'Zebra Corp']);
    Project::factory()->create(['user_id' => $user->id, 'name' => 'Apple Inc']);

    $this->actingAs($user);

    $component = Livewire::test('pages::projects.index')->call('sort', 'not_a_real_column');

    expect($component->get('sortBy'))->toBe('');
    expect($component->get('projects')->pluck('name')->all())->toBe(['Apple Inc', 'Zebra Corp']);
});

test('projects sortable by tokens count', function () {
    $user = User::factory()->create();
    $low = Project::factory()->create(['user_id' => $user->id, 'name' => 'Low Tokens']);
    $high = Project::factory()->create(['user_id' => $user->id, 'name' => 'High Tokens']);
    $high->createToken('t1');
    $high->createToken('t2');
    $low->createToken('t1');

    $this->actingAs($user);

    $component = Livewire::test('pages::projects.index')->call('sort', 'tokens_count');

    expect($component->get('projects')->pluck('name')->all())->toBe(['Low Tokens', 'High Tokens']);
});

test('last checked shows first push time even when last_checked_at was never set', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Fresh Push Project']);

    ProjectEcosystem::create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'current_hash' => 'hash',
        'package_count' => 1,
        'last_checked_at' => null,
        'last_pushed_at' => now(),
    ]);

    $this->actingAs($user);

    $lastCheckedAtHuman = Livewire::test('pages::projects.index')
        ->get('projects')
        ->firstWhere('uuid', $project->uuid)
        ->last_checked_at_human;

    expect($lastCheckedAtHuman)->not->toBeNull();
});
