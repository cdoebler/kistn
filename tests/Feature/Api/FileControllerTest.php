<?php

use App\Ecosystems\Composer\Jobs\ComposerAuditJob;
use App\Ecosystems\Npm\Jobs\NpmAuditJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function fileProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

function fileToken(Project $project): string
{
    return $project->createToken('test')->plainTextToken;
}

test('files endpoint requires auth', function (): void {
    $project = fileProject();

    $this->postJson("/api/projects/{$project->uuid}/files", [])
        ->assertUnauthorized();
});

test('files endpoint returns 403 when token belongs to different project', function (): void {
    $projectA = fileProject();
    $tokenA = fileToken($projectA);
    $projectB = fileProject();

    $this->withToken($tokenA)
        ->postJson("/api/projects/{$projectB->uuid}/files", [])
        ->assertForbidden();
});

test('files endpoint returns 422 when no files are provided', function (): void {
    Storage::fake('local');
    $project = fileProject();
    $token = fileToken($project);

    $this->withToken($token)
        ->postJson("/api/projects/{$project->uuid}/files", [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.files.0', 'At least one file must be provided.');
});

test('files endpoint stores composer_lock at correct path', function (): void {
    Queue::fake();
    Storage::fake('local');

    $project = fileProject();
    $token = fileToken($project);

    $file = UploadedFile::fake()->create('composer.lock.gz', 10);

    $this->withToken($token)
        ->post(
            "/api/projects/{$project->uuid}/files",
            ['composer_lock' => $file],
            ['Accept' => 'application/json'],
        )
        ->assertAccepted();

    Storage::disk('local')->assertExists("projects/{$project->uuid}/composer.lock.gz");
});

test('files endpoint returns 202 with known file field', function (): void {
    Queue::fake();
    Storage::fake('local');

    $project = fileProject();
    $token = fileToken($project);

    $file = UploadedFile::fake()->create('package-lock.json.gz', 10);

    $this->withToken($token)
        ->post(
            "/api/projects/{$project->uuid}/files",
            ['package_lock' => $file],
            ['Accept' => 'application/json'],
        )
        ->assertAccepted();

    Storage::disk('local')->assertExists("projects/{$project->uuid}/package-lock.json.gz");
});

test('files endpoint skips unknown file field and still returns 202', function (): void {
    Queue::fake();
    Storage::fake('local');

    $project = fileProject();
    $token = fileToken($project);

    // 'unknown_field' is not in FILE_MAP — it gets skipped with a warning log
    $this->withToken($token)
        ->post(
            "/api/projects/{$project->uuid}/files",
            [
                'composer_lock' => UploadedFile::fake()->create('composer.lock.gz', 5),
                'unknown_field' => UploadedFile::fake()->create('something.gz', 5),
            ],
            ['Accept' => 'application/json'],
        )
        ->assertAccepted();

    Storage::disk('local')->assertExists("projects/{$project->uuid}/composer.lock.gz");
    Storage::disk('local')->assertMissing("projects/{$project->uuid}/something.gz");
});

test('files endpoint dispatches ComposerAuditJob when shouldDispatch returns true', function (): void {
    Queue::fake();
    Storage::fake('local');

    $project = fileProject();
    $project->settings = ['audit_local_composer' => true];
    $project->save();

    $token = fileToken($project);

    // Pre-store the lock file so ComposerAuditJob::shouldDispatch passes the file-existence check
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", gzencode('{}'));

    $this->withToken($token)
        ->post(
            "/api/projects/{$project->uuid}/files",
            ['composer_lock' => UploadedFile::fake()->create('composer.lock.gz', 5)],
            ['Accept' => 'application/json'],
        )
        ->assertAccepted();

    Queue::assertPushed(ComposerAuditJob::class, fn ($job) => $job->project->is($project));
});

test('files endpoint does not dispatch audit jobs when shouldDispatch returns false', function (): void {
    Queue::fake();
    Storage::fake('local');

    $project = fileProject();
    // audit_local_composer not enabled → shouldDispatch returns false
    $token = fileToken($project);

    $this->withToken($token)
        ->post(
            "/api/projects/{$project->uuid}/files",
            ['composer_json' => UploadedFile::fake()->create('composer.json.gz', 5)],
            ['Accept' => 'application/json'],
        )
        ->assertAccepted();

    Queue::assertNotPushed(ComposerAuditJob::class);
    Queue::assertNotPushed(NpmAuditJob::class);
});

test('files endpoint keeps the untouched original under org and strips scripts from the working copy', function (): void {
    Queue::fake();
    Storage::fake('local');

    $project = fileProject();
    $token = fileToken($project);

    $manifest = json_encode([
        'name' => 'vendor/app',
        'scripts' => ['post-install-cmd' => 'rm -rf /'],
    ]);
    $file = UploadedFile::fake()->createWithContent('package.json.gz', gzencode($manifest));

    $this->withToken($token)
        ->post(
            "/api/projects/{$project->uuid}/files",
            ['package_json' => $file],
            ['Accept' => 'application/json'],
        )
        ->assertAccepted();

    $disk = Storage::disk('local');

    $org = gzdecode((string) $disk->get("projects/{$project->uuid}/org/package.json.gz"));
    expect(json_decode($org, true))->toHaveKey('scripts');

    $working = gzdecode((string) $disk->get("projects/{$project->uuid}/package.json.gz"));
    expect(json_decode($working, true))
        ->not->toHaveKey('scripts')
        ->toHaveKey('name', 'vendor/app');
});

test('files endpoint stores lock files unchanged as their own working copy', function (): void {
    Queue::fake();
    Storage::fake('local');

    $project = fileProject();
    $token = fileToken($project);

    $lock = gzencode('{"packages":[]}');
    $file = UploadedFile::fake()->createWithContent('composer.lock.gz', $lock);

    $this->withToken($token)
        ->post(
            "/api/projects/{$project->uuid}/files",
            ['composer_lock' => $file],
            ['Accept' => 'application/json'],
        )
        ->assertAccepted();

    $disk = Storage::disk('local');

    expect($disk->get("projects/{$project->uuid}/composer.lock.gz"))->toBe($lock);
    expect($disk->get("projects/{$project->uuid}/org/composer.lock.gz"))->toBe($lock);
});

test('files endpoint stores all known file fields', function (): void {
    Queue::fake();
    Storage::fake('local');

    $project = fileProject();
    $token = fileToken($project);

    $this->withToken($token)
        ->post(
            "/api/projects/{$project->uuid}/files",
            [
                'installed_json' => UploadedFile::fake()->create('installed.json.gz', 5),
                'composer_lock' => UploadedFile::fake()->create('composer.lock.gz', 5),
                'composer_json' => UploadedFile::fake()->create('composer.json.gz', 5),
                'package_lock' => UploadedFile::fake()->create('package-lock.json.gz', 5),
                'package_json' => UploadedFile::fake()->create('package.json.gz', 5),
            ],
            ['Accept' => 'application/json'],
        )
        ->assertAccepted();

    Storage::disk('local')->assertExists("projects/{$project->uuid}/installed.json.gz");
    Storage::disk('local')->assertExists("projects/{$project->uuid}/composer.lock.gz");
    Storage::disk('local')->assertExists("projects/{$project->uuid}/composer.json.gz");
    Storage::disk('local')->assertExists("projects/{$project->uuid}/package-lock.json.gz");
    Storage::disk('local')->assertExists("projects/{$project->uuid}/package.json.gz");
});
