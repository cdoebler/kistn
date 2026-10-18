<?php

use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use App\Enums\SeverityEnum;
use App\Models\InventoryEvent;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;
use Livewire\Livewire;

/**
 * Seeds a project with one outdated + vulnerable package and an inventory event.
 */
function seedProjectWithExposure(User $user, string $name, string $packageName = 'vendor/pkg'): Project
{
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => $name]);

    ProjectEcosystem::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'project_id' => $project->id,
        'package_count' => 10,
        'last_pushed_at' => now()->subHour(),
    ]);

    $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)
        ->withLatestVersion('2.0.0')
        ->create(['name' => $packageName]);

    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $package->id,
        'version' => '1.0.0',
    ]);

    VulnFinding::factory()->create([
        'package_id' => $package->id,
        'package_version' => '1.0.0',
        'severity' => SeverityEnum::Critical->value,
    ]);

    InventoryEvent::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'package_name' => 'vendor/pkg',
        'action' => InventoryActionEnum::Added->value,
    ]);

    return $project;
}

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk();
});

test('empty state is shown when the user has no projects', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertOk()->assertSee('No projects yet');
});

test('stat tiles aggregate the user\'s projects', function () {
    $user = User::factory()->create();
    seedProjectWithExposure($user, 'My App');
    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard')->instance();

    expect($component->projects->count())->toBe(1)
        ->and($component->packageCount)->toBe(10)
        ->and($component->outdatedCount)->toBe(1)
        ->and($component->vulnCount)->toBe(1)
        ->and($component->vulnBySeverity->get('critical'))->toBe(1);
});

test('needs attention and recent activity link to the project', function () {
    $user = User::factory()->create();
    $project = seedProjectWithExposure($user, 'My App');
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertOk()
        ->assertSee('My App')
        ->assertSee('vendor/pkg')
        ->assertSee('Critical')
        ->assertSee(route('projects.packages', $project), escape: false);
});

test('dashboard is scoped to the authenticated user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    seedProjectWithExposure($userA, 'Project A', 'vendor/pkg-a');
    seedProjectWithExposure($userB, 'Project B', 'vendor/pkg-b');

    $this->actingAs($userA);

    $component = Livewire::test('pages::dashboard');
    $component->assertSee('Project A')->assertDontSee('Project B');

    expect($component->instance()->vulnCount)->toBe(1)
        ->and($component->instance()->outdatedCount)->toBe(1);
});

test('stale projects are flagged past the threshold', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Neglected']);
    ProjectEcosystem::factory()->forEcosystem(EcosystemEnum::Composer)->create([
        'project_id' => $project->id,
        'last_pushed_at' => now()->subDays(30),
    ]);
    $this->actingAs($user);

    $component = Livewire::test('pages::dashboard')->instance();

    expect($component->staleProjects)->toHaveCount(1);
});

test('user can create a project via the modal', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::dashboard')
        ->set('name', 'Fresh Project')
        ->call('createProject')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Project::where('name', 'Fresh Project')->where('user_id', $user->id)->exists())->toBeTrue();
});

test('project name is required', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::dashboard')
        ->set('name', '')
        ->call('createProject')
        ->assertHasErrors(['name' => 'required']);
});
