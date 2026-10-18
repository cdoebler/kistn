<?php

use App\Ecosystems\Npm\Jobs\NpmAuditJob;
use App\Enums\EcosystemEnum;
use App\Enums\VulnSourceEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectEcosystem;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;
use App\Notifications\AuditDiscrepancyNotification;
use App\Notifications\ProjectChangesNotification;
use App\Services\NotificationDispatcherService;
use App\Services\SiblingFindingNotifierService;
use App\Services\SiblingOutdatedNotifierService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * Puts a fake `npm` executable first on PATH so NpmAuditJob's Process calls
 * hit this script instead of the real binary / registry network.
 *
 * @return callable(): void Restores PATH and removes the fake bin dir.
 */
function fakeNpmBinary(string $auditJson, string $outdatedJson): callable
{
    $binDir = sys_get_temp_dir().'/fake-npm-bin-'.uniqid();
    mkdir($binDir, 0755, true);

    $script = <<<SH
        #!/bin/sh
        if [ "\$1" = "audit" ]; then
            cat <<'JSON'
        {$auditJson}
        JSON
            exit 1
        fi
        if [ "\$1" = "outdated" ]; then
            cat <<'JSON'
        {$outdatedJson}
        JSON
            exit 0
        fi
        exit 0
        SH;

    file_put_contents($binDir.'/npm', $script);
    chmod($binDir.'/npm', 0755);

    $originalPath = getenv('PATH');
    putenv('PATH='.$binDir.':'.$originalPath);

    return function () use ($binDir, $originalPath): void {
        putenv('PATH='.$originalPath);
        unlink($binDir.'/npm');
        rmdir($binDir);
    };
}

function npmAuditProject(array $settings = []): Project
{
    return Project::factory()->create([
        'user_id' => User::factory()->create()->id,
        'settings' => $settings,
    ]);
}

// ── shouldDispatch() ──────────────────────────────────────────────────────────

test('NpmAuditJob shouldDispatch returns false when lock file does not exist', function (): void {
    Storage::fake('local');

    $project = npmAuditProject();

    expect(NpmAuditJob::shouldDispatch($project))->toBeFalse();
});

test('NpmAuditJob shouldDispatch returns true when file exists and force is true', function (): void {
    Storage::fake('local');

    $project = npmAuditProject();
    Storage::disk('local')->put("projects/{$project->uuid}/package-lock.json.gz", 'fake-gz-content');

    expect(NpmAuditJob::shouldDispatch($project, force: true))->toBeTrue();
});

test('NpmAuditJob shouldDispatch returns false when file exists but audit_local_npm is false', function (): void {
    Storage::fake('local');

    $project = npmAuditProject(['audit_local_npm' => false]);
    Storage::disk('local')->put("projects/{$project->uuid}/package-lock.json.gz", 'fake-gz-content');

    expect(NpmAuditJob::shouldDispatch($project, force: false))->toBeFalse();
});

test('NpmAuditJob shouldDispatch returns false when audited less than 24 hours ago', function (): void {
    Storage::fake('local');

    $project = npmAuditProject(['audit_local_npm' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/package-lock.json.gz", 'fake-gz-content');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'last_audited_at' => now()->subHours(12),
    ]);

    expect(NpmAuditJob::shouldDispatch($project, force: false))->toBeFalse();
});

test('NpmAuditJob shouldDispatch returns true when never audited before', function (): void {
    Storage::fake('local');

    $project = npmAuditProject(['audit_local_npm' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/package-lock.json.gz", 'fake-gz-content');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'last_audited_at' => null,
    ]);

    expect(NpmAuditJob::shouldDispatch($project, force: false))->toBeTrue();
});

test('NpmAuditJob shouldDispatch returns true when last audit was more than 24 hours ago', function (): void {
    Storage::fake('local');

    $project = npmAuditProject(['audit_local_npm' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/package-lock.json.gz", 'fake-gz-content');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'last_audited_at' => now()->subHours(25),
    ]);

    expect(NpmAuditJob::shouldDispatch($project, force: false))->toBeTrue();
});

test('NpmAuditJob shouldDispatch returns true when no ecosystem record exists', function (): void {
    Storage::fake('local');

    $project = npmAuditProject(['audit_local_npm' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/package-lock.json.gz", 'fake-gz-content');

    // No ProjectEcosystem record — eco is null, so null?->last_audited_at is null → should dispatch
    expect(NpmAuditJob::shouldDispatch($project, force: false))->toBeTrue();
});

// ── handle() ─────────────────────────────────────────────────────────────────

test('NpmAuditJob handle runs against minimal package-lock without throwing', function (): void {
    Storage::fake('local');

    $project = npmAuditProject(['audit_local_npm' => true]);

    $packageLock = json_encode([
        'name' => 'test',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'requires' => true,
        'packages' => [
            '' => [
                'name' => 'test',
                'version' => '1.0.0',
            ],
        ],
    ]);

    Storage::disk('local')->put(
        "projects/{$project->uuid}/package-lock.json.gz",
        gzencode($packageLock),
    );

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'last_audited_at' => null,
    ]);

    // Should complete without exception regardless of whether npm is available
    $job = new NpmAuditJob($project);
    $job->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

    // The ecosystem record should have last_audited_at set after the job ran
    $eco = ProjectEcosystem::where('project_id', $project->id)
        ->where('ecosystem', EcosystemEnum::Npm->value)
        ->first();

    expect($eco?->last_audited_at)->not->toBeNull();
});

test('NpmAuditJob handle detects a real vulnerability and sends a notification', function (): void {
    // ponytail: hits the real npm registry audit endpoint — pinned to an old, permanently-vulnerable
    // version (minimist 1.2.5, GHSA-xvch-5gv4-984h prototype pollution) so this stays stable.
    Storage::fake('local');
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['audit_local_npm' => true],
    ]);

    $packageLock = json_encode([
        'name' => 'test',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'requires' => true,
        'packages' => [
            '' => [
                'name' => 'test',
                'version' => '1.0.0',
                'dependencies' => ['minimist' => '1.2.5'],
            ],
            'node_modules/minimist' => [
                'version' => '1.2.5',
                'resolved' => 'https://registry.npmjs.org/minimist/-/minimist-1.2.5.tgz',
                'integrity' => 'sha512-FM9nNUYrRBAELZQT3xeZQ7fmMOBg6nWNmJKTcgsJeaLstP/UODVpGsr5OhXhhXg6f+qtJ8uiZ+PUxkDWcgIXLw==',
                'license' => 'MIT',
            ],
        ],
    ]);

    Storage::disk('local')->put(
        "projects/{$project->uuid}/package-lock.json.gz",
        gzencode($packageLock),
    );

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Npm->value,
        'last_audited_at' => null,
    ]);

    // syncFindings() only keeps findings it can resolve to a Package catalog row.
    Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'minimist']);

    (new NpmAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

    expect(VulnFinding::query()
        ->whereHas('package', fn ($q) => $q->where('name', 'minimist'))
        ->where('source', VulnSourceEnum::ServerNpmAudit->value)
        ->exists())->toBeTrue();

    Notification::assertSentTo($user, ProjectChangesNotification::class, function (ProjectChangesNotification $notification): bool {
        $findings = $notification->payload->findingsByEcosystem[EcosystemEnum::Npm->value] ?? [];

        return collect($findings)->contains(fn (array $f): bool => $f['package_name'] === 'minimist');
    });
})->group('network');

test('NpmAuditJob handle detects a vulnerability offline via a fake npm binary and sends a notification', function (): void {
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode([
        'vulnerabilities' => [
            'offline-pkg' => [
                'severity' => 'high',
                'via' => [
                    ['url' => 'https://github.com/advisories/GHSA-offl-ine0-0000'],
                ],
            ],
        ],
    ]);
    $outdatedJson = json_encode([
        'offline-pkg' => ['current' => '1.0.0', 'latest' => '2.0.0'],
    ]);
    $restorePath = fakeNpmBinary($auditJson, $outdatedJson);

    try {
        $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'settings' => ['audit_local_npm' => true],
        ]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/package-lock.json.gz",
            gzencode(json_encode([
                'name' => 'test',
                'version' => '1.0.0',
                'lockfileVersion' => 3,
                'packages' => [
                    '' => ['name' => 'test', 'version' => '1.0.0'],
                    'node_modules/offline-pkg' => ['version' => '1.0.0'],
                ],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Npm->value,
            'last_audited_at' => null,
        ]);

        // syncFindings() only keeps findings it can resolve to a Package catalog row.
        Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'offline-pkg']);

        (new NpmAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        expect(VulnFinding::query()
            ->whereHas('package', fn ($q) => $q->where('name', 'offline-pkg'))
            ->where('advisory_id', 'GHSA-offl-ine0-0000')
            ->where('source', VulnSourceEnum::ServerNpmAudit->value)
            ->exists())->toBeTrue();

        expect(VulnFinding::query()
            ->whereHas('package', fn ($q) => $q->where('name', 'offline-pkg'))
            ->where('package_version', '1.0.0')
            ->exists())->toBeTrue();

        Notification::assertSentTo($user, ProjectChangesNotification::class, function (ProjectChangesNotification $notification): bool {
            $findings = $notification->payload->findingsByEcosystem[EcosystemEnum::Npm->value] ?? [];

            return collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-offl-ine0-0000');
        });
    } finally {
        $restorePath();
    }
});

test('NpmAuditJob notifies for outdated packages alone, with no vulnerability findings', function (): void {
    Storage::fake('local');
    Notification::fake();

    // No vulnerabilities at all — proves the dispatch guard is an OR (either signal fires
    // it), not an AND (which would require findings AND outdated packages simultaneously).
    $auditJson = json_encode(['vulnerabilities' => []]);
    $outdatedJson = json_encode([
        'outdated-only' => ['current' => '1.0.0', 'latest' => '2.0.0'],
    ]);
    $restorePath = fakeNpmBinary($auditJson, $outdatedJson);

    try {
        $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'settings' => ['audit_local_npm' => true],
        ]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/package-lock.json.gz",
            gzencode(json_encode([
                'name' => 'test',
                'version' => '1.0.0',
                'lockfileVersion' => 3,
                'packages' => [
                    '' => ['name' => 'test', 'version' => '1.0.0'],
                    'node_modules/outdated-only' => ['version' => '1.0.0'],
                ],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Npm->value,
            'last_audited_at' => null,
        ]);

        $package = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'outdated-only']);
        ProjectPackage::factory()->create([
            'project_id' => $project->id,
            'package_id' => $package->id,
            'version' => '1.0.0',
        ]);

        (new NpmAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        expect(VulnFinding::where('source', VulnSourceEnum::ServerNpmAudit->value)->exists())->toBeFalse();

        Notification::assertSentTo($user, ProjectChangesNotification::class, fn (ProjectChangesNotification $notification): bool => ! $notification->payload->isEmpty());
    } finally {
        $restorePath();
    }
});

test('NpmAuditJob does not bump packages.updated_at when latest_version is unchanged', function (): void {
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode(['vulnerabilities' => []]);
    $outdatedJson = json_encode([
        'already-outdated' => ['current' => '1.0.0', 'latest' => '2.0.0'],
    ]);
    $restorePath = fakeNpmBinary($auditJson, $outdatedJson);

    try {
        $project = npmAuditProject(['audit_local_npm' => true]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/package-lock.json.gz",
            gzencode(json_encode([
                'name' => 'test',
                'version' => '1.0.0',
                'lockfileVersion' => 3,
                'packages' => [
                    '' => ['name' => 'test', 'version' => '1.0.0'],
                    'node_modules/already-outdated' => ['version' => '1.0.0'],
                ],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Npm->value,
            'last_audited_at' => null,
        ]);

        $staleUpdatedAt = now()->subDays(10)->startOfSecond();
        $package = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create([
            'name' => 'already-outdated',
            'latest_version' => '2.0.0', // already matches what the fake `npm outdated` reports above
            'updated_at' => $staleUpdatedAt,
        ]);
        ProjectPackage::factory()->create([
            'project_id' => $project->id,
            'package_id' => $package->id,
            'version' => '1.0.0',
        ]);

        $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);
        ProjectPackage::factory()->create([
            'project_id' => $sibling->id,
            'package_id' => $package->id,
            'version' => '1.5.0',
        ]);

        (new NpmAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        expect($package->fresh()->updated_at->eq($staleUpdatedAt))->toBeTrue();

        // latest_version didn't actually change this run (it already matched), so the
        // sibling notifier must not fire even though the sibling is itself outdated —
        // proactive notification is only for genuine changes, not every audit run.
        Notification::assertNotSentTo($siblingUser, ProjectChangesNotification::class);
    } finally {
        $restorePath();
    }
});

test('NpmAuditJob reports only not-yet-notified outdated packages in delta mode', function (): void {
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode(['vulnerabilities' => []]);
    $outdatedJson = json_encode([
        'already-notified' => ['current' => '1.0.0', 'latest' => '2.0.0'],
        'newly-outdated' => ['current' => '1.0.0', 'latest' => '3.0.0'],
    ]);
    $restorePath = fakeNpmBinary($auditJson, $outdatedJson);

    try {
        $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate', 'digest_content' => 'delta']]);
        $project = Project::factory()->create(['user_id' => $user->id, 'settings' => ['audit_local_npm' => true]]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/package-lock.json.gz",
            gzencode(json_encode([
                'name' => 'test',
                'version' => '1.0.0',
                'lockfileVersion' => 3,
                'packages' => [
                    '' => ['name' => 'test', 'version' => '1.0.0'],
                    'node_modules/already-notified' => ['version' => '1.0.0'],
                    'node_modules/newly-outdated' => ['version' => '1.0.0'],
                ],
            ])),
        );

        foreach (['already-notified' => '2.0.0', 'newly-outdated' => null] as $name => $known) {
            $package = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => $name, 'latest_version' => $known]);
            ProjectPackage::factory()->create([
                'project_id' => $project->id,
                'package_id' => $package->id,
                'version' => '1.0.0',
                'notified_latest_version' => $known,
            ]);
        }

        (new NpmAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        Notification::assertSentTo($user, ProjectChangesNotification::class, fn (ProjectChangesNotification $notification): bool => $notification->payload->outdatedByEcosystem['npm']->major === [
            ['name' => 'newly-outdated', 'current' => '1.0.0', 'latest' => '3.0.0'],
        ]);
    } finally {
        $restorePath();
    }
});

test('NpmAuditJob reports a discrepancy when the client reported an advisory the server audit did not find', function (): void {
    Storage::fake('local');
    Notification::fake();

    $restorePath = fakeNpmBinary(json_encode(['vulnerabilities' => []]), json_encode([]));

    try {
        $project = npmAuditProject(['audit_local_npm' => true]);
        Storage::disk('local')->put(
            "projects/{$project->uuid}/package-lock.json.gz",
            gzencode(json_encode(['lockfileVersion' => 3, 'packages' => ['node_modules/client-pkg' => ['version' => '1.0.0']]])),
        );

        $package = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'client-pkg']);
        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => '1.0.0']);
        VulnFinding::factory()->create([
            'package_id' => $package->id,
            'package_version' => '1.0.0',
            'advisory_id' => 'GHSA-client-only',
            'source' => VulnSourceEnum::ClientAudit->value,
        ]);

        (new NpmAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        Notification::assertSentTo($project->user, AuditDiscrepancyNotification::class, fn (AuditDiscrepancyNotification $n): bool => $n->onlyInClient === ['GHSA-client-only']);
    } finally {
        $restorePath();
    }
});

test('NpmAuditJob removes its temp working directory after the run', function (): void {
    Storage::fake('local');
    Notification::fake();

    $restorePath = fakeNpmBinary(json_encode(['vulnerabilities' => []]), json_encode([]));
    // Fresh per run: a fixed value collides with a dir left behind by an earlier run
    // (e.g. a mutation-testing mutant that removes the cleanup call).
    $uuid = (string) Str::uuid();

    try {
        $project = npmAuditProject(['audit_local_npm' => true]);
        Storage::disk('local')->put("projects/{$project->uuid}/package-lock.json.gz", gzencode(json_encode(['lockfileVersion' => 3, 'packages' => []])));
        Storage::disk('local')->put("projects/{$project->uuid}/package.json.gz", gzencode('{}'));

        Str::createUuidsUsing(fn (): UuidInterface => Uuid::fromString($uuid));

        try {
            (new NpmAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));
        } finally {
            Str::createUuidsNormally();
        }

        expect(file_exists(sys_get_temp_dir().'/audit-npm-'.$uuid))->toBeFalse();
    } finally {
        $restorePath();
    }
});

test('NpmAuditJob resolveInstalledVersions prefers the shallowest copy when a package appears at multiple depths', function (): void {
    $project = npmAuditProject();
    $job = new NpmAuditJob($project);

    $resolveInstalledVersions = new ReflectionMethod($job, 'resolveInstalledVersions');

    $packageLock = json_encode([
        'name' => 'test',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => ['name' => 'test', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.21'],
            'node_modules/some-dep/node_modules/lodash' => ['version' => '3.10.1'],
        ],
    ]);

    $versions = $resolveInstalledVersions->invoke($job, $packageLock);

    expect($versions['lodash'])->toBe('4.17.21');
});

test('NpmAuditJob syncFindings inserts new findings and keeps reconfirmed ones without duplicating', function (): void {
    $project = npmAuditProject();
    Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'pkg-a']);
    Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'pkg-b']);
    Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'pkg-c']);

    $job = new NpmAuditJob($project);
    $syncFindings = new ReflectionMethod($job, 'syncFindings');

    $first = $syncFindings->invoke($job, [
        ['package_name' => 'pkg-a', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-aaa', 'severity' => 'high'],
        ['package_name' => 'pkg-b', 'package_version' => '2.0.0', 'advisory_id' => 'GHSA-bbb', 'severity' => 'low'],
    ]);

    expect($first)->toHaveCount(2);
    expect(VulnFinding::query()->where('source', VulnSourceEnum::ServerNpmAudit->value)->count())->toBe(2);

    $reconfirmed = VulnFinding::query()->where('advisory_id', 'GHSA-aaa')->sole();
    $firstSeenAt = $reconfirmed->last_seen_at;
    $this->travel(1)->hours();

    $second = $syncFindings->invoke($job, [
        ['package_name' => 'pkg-a', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-aaa', 'severity' => 'high'],
        ['package_name' => 'pkg-c', 'package_version' => '3.0.0', 'advisory_id' => 'GHSA-ccc', 'severity' => 'critical'],
    ]);

    expect($second)->toHaveCount(1)
        ->and($second[0]['advisory_id'])->toBe('GHSA-ccc');

    expect(VulnFinding::query()->where('source', VulnSourceEnum::ServerNpmAudit->value)->count())->toBe(3);

    expect($reconfirmed->fresh()?->last_seen_at?->gt($firstSeenAt))->toBeTrue();
});

test('NpmAuditJob notifies a sibling project when the shared latest_version changes', function (): void {
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode(['vulnerabilities' => []]);
    $outdatedJson = json_encode([
        'shared-pkg' => ['current' => '1.0.0', 'latest' => '2.0.0'],
    ]);
    $restorePath = fakeNpmBinary($auditJson, $outdatedJson);

    try {
        $actingUser = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $actingUser->id,
            'settings' => ['audit_local_npm' => true],
        ]);

        $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/package-lock.json.gz",
            gzencode(json_encode([
                'name' => 'test',
                'version' => '1.0.0',
                'lockfileVersion' => 3,
                'packages' => [
                    '' => ['name' => 'test', 'version' => '1.0.0'],
                    'node_modules/shared-pkg' => ['version' => '1.0.0'],
                ],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Npm->value,
            'last_audited_at' => null,
        ]);

        $package = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'shared-pkg', 'latest_version' => '1.0.0']);
        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => '1.0.0']);
        ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '0.9.0']);

        (new NpmAuditJob($project))->handle(
            resolve(NotificationDispatcherService::class),
            resolve(SiblingFindingNotifierService::class),
            resolve(SiblingOutdatedNotifierService::class),
        );

        expect($package->fresh()->latest_version)->toBe('2.0.0');

        Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
            $summary = $n->payload->outdatedByEcosystem['npm'] ?? null;

            if ($summary === null) {
                return false;
            }

            $all = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

            return collect($all)->contains(fn (array $p): bool => $p['name'] === 'shared-pkg' && $p['current'] === '0.9.0' && $p['latest'] === '2.0.0');
        });
    } finally {
        $restorePath();
    }
});

test('NpmAuditJob fires both sibling notifiers independently in the same run', function (): void {
    // NpmAuditJob::handle() calls SiblingFindingNotifierService and SiblingOutdatedNotifierService
    // separately off one runAudit() call. Each builds and dispatches its own
    // ProjectNotificationPayloadValueObject (NotificationDispatcherService::dispatch() is called once
    // per notifier, not once combined) — so a sibling affected by both a new finding and a changed
    // latest_version receives TWO separate ProjectChangesNotification deliveries, not one merged one.
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode([
        'vulnerabilities' => [
            'dual-finding' => [
                'severity' => 'high',
                'via' => [
                    ['url' => 'https://github.com/advisories/GHSA-dual-0001'],
                ],
            ],
        ],
    ]);
    $outdatedJson = json_encode([
        'dual-outdated' => ['current' => '1.0.0', 'latest' => '2.0.0'],
    ]);
    $restorePath = fakeNpmBinary($auditJson, $outdatedJson);

    try {
        $actingUser = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $actingUser->id,
            'settings' => ['audit_local_npm' => true],
        ]);

        $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/package-lock.json.gz",
            gzencode(json_encode([
                'name' => 'test',
                'version' => '1.0.0',
                'lockfileVersion' => 3,
                'packages' => [
                    '' => ['name' => 'test', 'version' => '1.0.0'],
                    'node_modules/dual-finding' => ['version' => '1.0.0'],
                    'node_modules/dual-outdated' => ['version' => '1.0.0'],
                ],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Npm->value,
            'last_audited_at' => null,
        ]);

        // syncFindings() only keeps findings it can resolve to a Package catalog row.
        $findingPackage = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'dual-finding']);
        $outdatedPackage = Package::factory()->forEcosystem(EcosystemEnum::Npm)->create(['name' => 'dual-outdated', 'latest_version' => '1.0.0']);

        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $findingPackage->id, 'version' => '1.0.0']);
        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $outdatedPackage->id, 'version' => '1.0.0']);

        // The finding notifier matches siblings on the exact reported package_version (the acting
        // project's own installed version — see NpmAuditParser), so the sibling must be on the
        // same version to receive it. The outdated notifier has no such constraint: it's used here
        // with a different version to keep proving per-sibling outdatedPackages() computation.
        ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $findingPackage->id, 'version' => '1.0.0']);
        ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $outdatedPackage->id, 'version' => '0.9.0']);

        (new NpmAuditJob($project))->handle(
            resolve(NotificationDispatcherService::class),
            resolve(SiblingFindingNotifierService::class),
            resolve(SiblingOutdatedNotifierService::class),
        );

        expect(VulnFinding::query()
            ->whereHas('package', fn ($q) => $q->where('name', 'dual-finding'))
            ->where('advisory_id', 'GHSA-dual-0001')
            ->where('source', VulnSourceEnum::ServerNpmAudit->value)
            ->exists())->toBeTrue();
        expect($outdatedPackage->fresh()->latest_version)->toBe('2.0.0');

        // Two independent notifications, not one combined payload.
        Notification::assertSentToTimes($siblingUser, ProjectChangesNotification::class, 2);

        Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
            $findings = $n->payload->findingsByEcosystem['npm'] ?? [];

            return $n->payload->outdatedByEcosystem === []
                && collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-dual-0001');
        });

        Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
            $summary = $n->payload->outdatedByEcosystem['npm'] ?? null;

            if ($summary === null) {
                return false;
            }

            $all = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

            return $n->payload->findingsByEcosystem === []
                && collect($all)->contains(fn (array $p): bool => $p['name'] === 'dual-outdated' && $p['current'] === '0.9.0' && $p['latest'] === '2.0.0');
        });
    } finally {
        $restorePath();
    }
});
