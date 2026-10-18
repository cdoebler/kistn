<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;

test('project owner can log in and see installed packages on the packages page', function (): void {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'Browser Test Project']);

    $pkg = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/browser-pkg']);
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $pkg->id,
        'version' => '1.0.0',
    ]);

    $page = visit('/login');

    $page->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertPathIs('/dashboard')
        ->assertNoJavascriptErrors();

    $page = visit(route('projects.packages', $project));

    $page->assertSee('Browser Test Project')
        ->assertSee('vendor/browser-pkg')
        ->assertDontSee('No packages yet')
        ->assertNoJavascriptErrors();
});
