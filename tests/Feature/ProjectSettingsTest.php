<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns false for audit_local_composer when settings are null', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id, 'settings' => null]);
    expect($project->auditLocalComposer())->toBeFalse();
});

it('returns false for audit_local_npm when settings are null', function (): void {
    $project = Project::factory()->create(['user_id' => User::factory()->create()->id, 'settings' => null]);
    expect($project->auditLocalNpm())->toBeFalse();
});

it('returns true for audit_local_composer when setting is enabled', function (): void {
    $project = Project::factory()->create([
        'user_id' => User::factory()->create()->id,
        'settings' => ['audit_local_composer' => true],
    ]);
    expect($project->auditLocalComposer())->toBeTrue();
});

it('returns true for audit_local_npm when setting is enabled', function (): void {
    $project = Project::factory()->create([
        'user_id' => User::factory()->create()->id,
        'settings' => ['audit_local_npm' => true],
    ]);
    expect($project->auditLocalNpm())->toBeTrue();
});
