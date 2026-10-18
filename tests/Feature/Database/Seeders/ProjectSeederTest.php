<?php

use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\ProjectPackage;
use App\Models\User;
use Database\Seeders\ProjectSeeder;
use Livewire\Livewire;

test('seeded packages are linked to the project via project_packages, not just the catalog', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    (new ProjectSeeder)->run();

    expect(ProjectPackage::where('project_id', $project->id)->count())->toBeGreaterThan(0);
});

test('project_ecosystems package_count matches the number of linked project_packages', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    (new ProjectSeeder)->run();

    ProjectEcosystem::where('project_id', $project->id)->get()->each(function (ProjectEcosystem $state) use ($project): void {
        $linked = ProjectPackage::query()
            ->where('project_id', $project->id)
            ->whereHas('package', fn ($q) => $q->where('ecosystem', $state->ecosystem->value))
            ->count();

        expect($state->package_count)->toBe($linked);
    });
});

test('seeded project packages are visible on the packages page', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    (new ProjectSeeder)->run();

    $this->actingAs($user);

    Livewire::test('pages::projects.packages', ['project' => $project])
        ->assertDontSee('No packages yet');
});
