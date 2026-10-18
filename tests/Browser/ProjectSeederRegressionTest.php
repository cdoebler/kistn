<?php

use App\Models\Project;
use App\Models\User;
use Database\Seeders\ProjectSeeder;

test('seeded demo project shows packages on the packages page, not the empty state', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    (new ProjectSeeder)->run();

    $this->actingAs($user);

    $page = visit(route('projects.packages', $project));

    $page->assertDontSee('No packages yet')
        ->assertNoJavascriptErrors();
});
