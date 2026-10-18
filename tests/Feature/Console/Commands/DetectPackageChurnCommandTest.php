<?php

use App\Console\Commands\DetectPackageChurnCommand;
use App\Enums\EcosystemEnum;
use App\Enums\InventoryActionEnum;
use App\Models\InventoryEvent;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

test('flags a project whose added-package count exceeds the threshold in 24h', function (): void {
    config(['inventory.churn_threshold' => 5]);

    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    InventoryEvent::insert(array_map(fn (int $i): array => [
        'uuid' => (string) Str::uuid(),
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'package_name' => "vendor/pkg-{$i}",
        'action' => InventoryActionEnum::Added->value,
        'old_version' => null,
        'new_version' => '1.0.0',
        'occurred_at' => now()->subHours(1),
    ], range(1, 6)));

    $this->artisan(DetectPackageChurnCommand::class)->assertSuccessful();

    $project->refresh();

    expect($project->churn_flagged_at)->not->toBeNull()
        ->and($project->churn_flagged_count)->toBe(1);
});

test('does not flag a project under the threshold', function (): void {
    config(['inventory.churn_threshold' => 5]);

    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    InventoryEvent::insert(array_map(fn (int $i): array => [
        'uuid' => (string) Str::uuid(),
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'package_name' => "vendor/pkg-{$i}",
        'action' => InventoryActionEnum::Added->value,
        'old_version' => null,
        'new_version' => '1.0.0',
        'occurred_at' => now()->subHours(1),
    ], range(1, 3)));

    $this->artisan(DetectPackageChurnCommand::class)->assertSuccessful();

    $project->refresh();

    expect($project->churn_flagged_at)->toBeNull();
});

test('ignores added events older than 24 hours', function (): void {
    config(['inventory.churn_threshold' => 5]);

    $project = Project::factory()->create(['user_id' => User::factory()->create()->id]);

    InventoryEvent::insert(array_map(fn (int $i): array => [
        'uuid' => (string) Str::uuid(),
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'package_name' => "vendor/pkg-{$i}",
        'action' => InventoryActionEnum::Added->value,
        'old_version' => null,
        'new_version' => '1.0.0',
        'occurred_at' => now()->subHours(30),
    ], range(1, 6)));

    $this->artisan(DetectPackageChurnCommand::class)->assertSuccessful();

    $project->refresh();

    expect($project->churn_flagged_at)->toBeNull();
});
