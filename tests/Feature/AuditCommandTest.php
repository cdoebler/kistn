<?php

use App\Ecosystems\Composer\Jobs\ComposerAuditJob;
use App\Ecosystems\Npm\Jobs\NpmAuditJob;
use App\Enums\EcosystemEnum;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function auditCmdProject(array $settings = []): Project
{
    return Project::factory()->create([
        'user_id' => User::factory()->create()->id,
        'settings' => $settings,
    ]);
}

test('kistn:audit requires --all or a project uuid', function () {
    $this->artisan('kistn:audit')
        ->assertFailed();
});

test('kistn:audit --all dispatches audit for eligible stale project with composer lock', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_composer' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => null,
    ]);

    $this->artisan('kistn:audit --all')->assertSuccessful();

    Queue::assertPushed(ComposerAuditJob::class, fn ($job) => $job->project->id === $project->id);
});

test('kistn:audit --all skips project when last_audited_at is fresh', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_composer' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => now()->subHours(2),
    ]);

    $this->artisan('kistn:audit --all')->assertSuccessful();

    Queue::assertNotPushed(ComposerAuditJob::class);
});

test('kistn:audit --all skips project when audit_local_composer is false', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_composer' => false]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake');

    $this->artisan('kistn:audit --all')->assertSuccessful();

    Queue::assertNotPushed(ComposerAuditJob::class);
});

test('kistn:audit --all skips when no lock file stored', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_composer' => true]);
    // No file stored

    $this->artisan('kistn:audit --all')->assertSuccessful();

    Queue::assertNotPushed(ComposerAuditJob::class);
});

test('kistn:audit {uuid} dispatches for specific project', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_composer' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => null,
    ]);

    $this->artisan("kistn:audit {$project->uuid}")->assertSuccessful();

    Queue::assertPushed(ComposerAuditJob::class);
});

test('kistn:audit {uuid} fails for unknown uuid', function () {
    $this->artisan('kistn:audit 00000000-0000-0000-0000-000000000000')
        ->assertFailed();
});

test('kistn:audit --all --force bypasses audit_local_composer flag', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_composer' => false]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake');

    $this->artisan('kistn:audit --all --force')->assertSuccessful();

    Queue::assertPushed(ComposerAuditJob::class);
});

test('kistn:audit --all --force bypasses staleness check', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_composer' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => now()->subHours(1),
    ]);

    $this->artisan('kistn:audit --all --force')->assertSuccessful();

    Queue::assertPushed(ComposerAuditJob::class);
});

test('kistn:audit --all --force still requires stored lock file', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_composer' => false]);
    // No lock file

    $this->artisan('kistn:audit --all --force')->assertSuccessful();

    Queue::assertNotPushed(ComposerAuditJob::class);
});

test('kistn:audit --all dispatches audit for project with npm lock', function () {
    Queue::fake();
    Storage::fake('local');

    $project = auditCmdProject(['audit_local_npm' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/package-lock.json.gz", 'fake');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'last_audited_at' => null,
    ]);

    $this->artisan('kistn:audit --all')->assertSuccessful();

    Queue::assertPushed(NpmAuditJob::class, fn ($job) => $job->project->id === $project->id);
});

test('kistn:audit --all is scheduled daily', function () {
    $schedule = resolve(Schedule::class);
    $found = collect($schedule->events())->contains(
        fn ($e) => str_contains((string) $e->command, 'kistn:audit'),
    );
    expect($found)->toBeTrue();
});
