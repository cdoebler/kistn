<?php

use App\Ecosystems\Composer\Jobs\ComposerAuditJob;
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
 * Puts a fake `composer` executable first on PATH so ComposerAuditJob's Process
 * calls hit this script instead of the real binary / Packagist network.
 *
 * @return callable(): void Restores PATH and removes the fake bin dir.
 */
function fakeComposerBinary(string $auditJson, int $auditExitCode, string $outdatedJson, int $outdatedExitCode): callable
{
    $binDir = sys_get_temp_dir().'/fake-composer-bin-'.uniqid();
    mkdir($binDir, 0755, true);

    $script = <<<SH
        #!/bin/sh
        # The job must isolate composer from the host's global config/auth/plugins.
        # Physical paths: on macOS the temp dir lives behind the /var -> /private/var symlink.
        if [ "\$(cd "\$COMPOSER_HOME" && pwd -P)" != "\$(pwd -P)" ]; then
            echo "COMPOSER_HOME must be the job's temp dir" >&2
            exit 99
        fi
        if [ "\$1" = "audit" ]; then
            cat <<'JSON'
        {$auditJson}
        JSON
            exit {$auditExitCode}
        fi
        if [ "\$1" = "outdated" ]; then
            cat <<'JSON'
        {$outdatedJson}
        JSON
            exit {$outdatedExitCode}
        fi
        exit 0
        SH;

    file_put_contents($binDir.'/composer', $script);
    chmod($binDir.'/composer', 0755);

    $originalPath = getenv('PATH');
    putenv('PATH='.$binDir.':'.$originalPath);

    return function () use ($binDir, $originalPath): void {
        putenv('PATH='.$originalPath);
        unlink($binDir.'/composer');
        rmdir($binDir);
    };
}

function composerAuditProject(array $settings = []): Project
{
    return Project::factory()->create([
        'user_id' => User::factory()->create()->id,
        'settings' => $settings,
    ]);
}

// ── shouldDispatch() ──────────────────────────────────────────────────────────

test('ComposerAuditJob shouldDispatch returns false when lock file does not exist', function (): void {
    Storage::fake('local');

    $project = composerAuditProject();

    expect(ComposerAuditJob::shouldDispatch($project))->toBeFalse();
});

test('ComposerAuditJob shouldDispatch returns true when file exists and force is true', function (): void {
    Storage::fake('local');

    $project = composerAuditProject();
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake-gz-content');

    expect(ComposerAuditJob::shouldDispatch($project, force: true))->toBeTrue();
});

test('ComposerAuditJob shouldDispatch returns false when file exists but audit_local_composer is false', function (): void {
    Storage::fake('local');

    $project = composerAuditProject(['audit_local_composer' => false]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake-gz-content');

    expect(ComposerAuditJob::shouldDispatch($project, force: false))->toBeFalse();
});

test('ComposerAuditJob shouldDispatch returns false when audited less than 24 hours ago', function (): void {
    Storage::fake('local');

    $project = composerAuditProject(['audit_local_composer' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake-gz-content');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => now()->subHours(12),
    ]);

    expect(ComposerAuditJob::shouldDispatch($project, force: false))->toBeFalse();
});

test('ComposerAuditJob shouldDispatch returns true when never audited before', function (): void {
    Storage::fake('local');

    $project = composerAuditProject(['audit_local_composer' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake-gz-content');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => null,
    ]);

    expect(ComposerAuditJob::shouldDispatch($project, force: false))->toBeTrue();
});

test('ComposerAuditJob shouldDispatch returns true when last audit was more than 24 hours ago', function (): void {
    Storage::fake('local');

    $project = composerAuditProject(['audit_local_composer' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake-gz-content');

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => now()->subHours(25),
    ]);

    expect(ComposerAuditJob::shouldDispatch($project, force: false))->toBeTrue();
});

test('ComposerAuditJob shouldDispatch returns true when no ecosystem record exists', function (): void {
    Storage::fake('local');

    $project = composerAuditProject(['audit_local_composer' => true]);
    Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", 'fake-gz-content');

    // No ProjectEcosystem record — eco is null, so null?->last_audited_at is null → should dispatch
    expect(ComposerAuditJob::shouldDispatch($project, force: false))->toBeTrue();
});

// ── handle() ─────────────────────────────────────────────────────────────────

test('ComposerAuditJob handle runs against minimal composer lock without throwing', function (): void {
    Storage::fake('local');

    $project = composerAuditProject(['audit_local_composer' => true]);

    $lockContent = json_encode([
        'packages' => [],
        '_readme' => [],
        'content-hash' => 'abc123',
        'packages-dev' => [],
        'aliases' => [],
        'minimum-stability' => 'stable',
        'stability-flags' => [],
        'prefer-stable' => false,
        'prefer-lowest' => false,
        'platform' => [],
        'platform-dev' => [],
        'plugin-api-version' => '2.3.0',
    ]);

    Storage::disk('local')->put(
        "projects/{$project->uuid}/composer.lock.gz",
        gzencode($lockContent),
    );

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => null,
    ]);

    // Should complete without exception regardless of whether composer is available
    $job = new ComposerAuditJob($project);
    $job->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

    // The ecosystem record should have last_audited_at set after the job ran
    $eco = ProjectEcosystem::where('project_id', $project->id)
        ->where('ecosystem', EcosystemEnum::Composer->value)
        ->first();

    expect($eco?->last_audited_at)->not->toBeNull();
});

test('ComposerAuditJob handle detects a real vulnerability and sends a notification', function (): void {
    // ponytail: hits Packagist's real advisory API — pinned to an old, permanently-vulnerable
    // version so the assertion stays stable regardless of what's currently in the advisory DB.
    Storage::fake('local');
    Notification::fake();

    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'settings' => ['audit_local_composer' => true],
    ]);

    $lockContent = json_encode([
        'packages' => [
            ['name' => 'guzzlehttp/guzzle', 'version' => '6.3.0'],
        ],
        '_readme' => [],
        'content-hash' => 'abc123',
        'packages-dev' => [],
        'aliases' => [],
        'minimum-stability' => 'stable',
        'stability-flags' => [],
        'prefer-stable' => false,
        'prefer-lowest' => false,
        'platform' => [],
        'platform-dev' => [],
        'plugin-api-version' => '2.6.0',
    ]);

    Storage::disk('local')->put(
        "projects/{$project->uuid}/composer.lock.gz",
        gzencode($lockContent),
    );

    ProjectEcosystem::factory()->create([
        'project_id' => $project->id,
        'ecosystem' => EcosystemEnum::Composer->value,
        'last_audited_at' => null,
    ]);

    // syncFindings() only keeps findings it can resolve to a Package catalog row.
    Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'guzzlehttp/guzzle']);

    (new ComposerAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

    expect(VulnFinding::query()
        ->whereHas('package', fn ($q) => $q->where('name', 'guzzlehttp/guzzle'))
        ->where('source', VulnSourceEnum::ServerComposerAudit->value)
        ->exists())->toBeTrue();

    Notification::assertSentTo($user, ProjectChangesNotification::class, function (ProjectChangesNotification $notification): bool {
        $findings = $notification->payload->findingsByEcosystem[EcosystemEnum::Composer->value] ?? [];

        return collect($findings)->contains(fn (array $f): bool => $f['package_name'] === 'guzzlehttp/guzzle');
    });
})->group('network');

test('ComposerAuditJob handle detects a vulnerability offline via a fake composer binary and sends a notification', function (): void {
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode([
        'advisories' => [
            'vendor/offline-pkg' => [
                ['advisoryId' => 'GHSA-offl-ine0-0000', 'packageName' => 'vendor/offline-pkg', 'affectedVersions' => '<2.0.0', 'severity' => 'high'],
            ],
        ],
    ]);
    $outdatedJson = json_encode([
        'locked' => [
            ['name' => 'vendor/offline-pkg', 'version' => '1.0.0', 'latest' => '2.0.0'],
        ],
    ]);
    $restorePath = fakeComposerBinary($auditJson, auditExitCode: 1, outdatedJson: $outdatedJson, outdatedExitCode: 0);

    try {
        $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'settings' => ['audit_local_composer' => true],
        ]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/composer.lock.gz",
            gzencode(json_encode([
                'packages' => [
                    ['name' => 'vendor/offline-pkg', 'version' => '1.0.0'],
                ],
                'packages-dev' => [],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Composer->value,
            'last_audited_at' => null,
        ]);

        // syncFindings() only keeps findings it can resolve to a Package catalog row.
        Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/offline-pkg']);

        (new ComposerAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        expect(VulnFinding::query()
            ->whereHas('package', fn ($q) => $q->where('name', 'vendor/offline-pkg'))
            ->where('advisory_id', 'GHSA-offl-ine0-0000')
            ->where('source', VulnSourceEnum::ServerComposerAudit->value)
            ->exists())->toBeTrue();

        expect(VulnFinding::query()
            ->whereHas('package', fn ($q) => $q->where('name', 'vendor/offline-pkg'))
            ->where('package_version', '1.0.0')
            ->exists())->toBeTrue();

        Notification::assertSentTo($user, ProjectChangesNotification::class, function (ProjectChangesNotification $notification): bool {
            $findings = $notification->payload->findingsByEcosystem[EcosystemEnum::Composer->value] ?? [];

            return collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-offl-ine0-0000');
        });
    } finally {
        $restorePath();
    }
});

test('ComposerAuditJob notifies for outdated packages alone, with no vulnerability findings', function (): void {
    Storage::fake('local');
    Notification::fake();

    // No advisories at all — proves the dispatch guard is an OR (either signal fires it),
    // not an AND (which would require findings AND outdated packages simultaneously).
    $auditJson = json_encode(['advisories' => []]);
    $outdatedJson = json_encode([
        'locked' => [
            ['name' => 'vendor/outdated-only', 'version' => '1.0.0', 'latest' => '2.0.0'],
        ],
    ]);
    $restorePath = fakeComposerBinary($auditJson, auditExitCode: 0, outdatedJson: $outdatedJson, outdatedExitCode: 0);

    try {
        $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $project = Project::factory()->create([
            'user_id' => $user->id,
            'settings' => ['audit_local_composer' => true],
        ]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/composer.lock.gz",
            gzencode(json_encode([
                'packages' => [
                    ['name' => 'vendor/outdated-only', 'version' => '1.0.0'],
                ],
                'packages-dev' => [],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Composer->value,
            'last_audited_at' => null,
        ]);

        Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/outdated-only']);
        ProjectPackage::factory()->create([
            'project_id' => $project->id,
            'package_id' => Package::where('name', 'vendor/outdated-only')->sole()->id,
            'version' => '1.0.0',
        ]);

        (new ComposerAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        expect(VulnFinding::where('source', VulnSourceEnum::ServerComposerAudit->value)->exists())->toBeFalse();

        Notification::assertSentTo($user, ProjectChangesNotification::class, fn (ProjectChangesNotification $notification): bool => ! $notification->payload->isEmpty());
    } finally {
        $restorePath();
    }
});

test('ComposerAuditJob does not bump packages.updated_at when latest_version is unchanged', function (): void {
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode(['advisories' => []]);
    $outdatedJson = json_encode([
        'locked' => [
            ['name' => 'vendor/already-outdated', 'version' => '1.0.0', 'latest' => '2.0.0'],
        ],
    ]);
    $restorePath = fakeComposerBinary($auditJson, auditExitCode: 0, outdatedJson: $outdatedJson, outdatedExitCode: 0);

    try {
        $project = composerAuditProject(['audit_local_composer' => true]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/composer.lock.gz",
            gzencode(json_encode([
                'packages' => [
                    ['name' => 'vendor/already-outdated', 'version' => '1.0.0'],
                ],
                'packages-dev' => [],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Composer->value,
            'last_audited_at' => null,
        ]);

        $staleUpdatedAt = now()->subDays(10)->startOfSecond();
        $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create([
            'name' => 'vendor/already-outdated',
            'latest_version' => '2.0.0', // already matches what the fake `composer outdated` reports below
            'updated_at' => $staleUpdatedAt,
        ]);
        ProjectPackage::factory()->create([
            'project_id' => $project->id,
            'package_id' => $package->id,
            'version' => '1.0.0',
        ]);

        (new ComposerAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        expect($package->fresh()->updated_at->eq($staleUpdatedAt))->toBeTrue();
    } finally {
        $restorePath();
    }
});

test('ComposerAuditJob reports only not-yet-notified outdated packages in delta mode', function (): void {
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode(['advisories' => []]);
    $outdatedJson = json_encode([
        'locked' => [
            ['name' => 'vendor/already-notified', 'version' => '1.0.0', 'latest' => '2.0.0'],
            ['name' => 'vendor/newly-outdated', 'version' => '1.0.0', 'latest' => '3.0.0'],
        ],
    ]);
    $restorePath = fakeComposerBinary($auditJson, auditExitCode: 0, outdatedJson: $outdatedJson, outdatedExitCode: 0);

    try {
        $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate', 'digest_content' => 'delta']]);
        $project = Project::factory()->create(['user_id' => $user->id, 'settings' => ['audit_local_composer' => true]]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/composer.lock.gz",
            gzencode(json_encode([
                'packages' => [
                    ['name' => 'vendor/already-notified', 'version' => '1.0.0'],
                    ['name' => 'vendor/newly-outdated', 'version' => '1.0.0'],
                ],
                'packages-dev' => [],
            ])),
        );

        foreach (['vendor/already-notified' => '2.0.0', 'vendor/newly-outdated' => null] as $name => $known) {
            $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => $name, 'latest_version' => $known]);
            ProjectPackage::factory()->create([
                'project_id' => $project->id,
                'package_id' => $package->id,
                'version' => '1.0.0',
                'notified_latest_version' => $known,
            ]);
        }

        (new ComposerAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        Notification::assertSentTo($user, ProjectChangesNotification::class, fn (ProjectChangesNotification $notification): bool => $notification->payload->outdatedByEcosystem['composer']->major === [
            ['name' => 'vendor/newly-outdated', 'current' => '1.0.0', 'latest' => '3.0.0'],
        ]);
    } finally {
        $restorePath();
    }
});

test('ComposerAuditJob reports a discrepancy when the client reported an advisory the server audit did not find', function (): void {
    Storage::fake('local');
    Notification::fake();

    $restorePath = fakeComposerBinary(json_encode(['advisories' => []]), auditExitCode: 0, outdatedJson: json_encode(['locked' => []]), outdatedExitCode: 0);

    try {
        $project = composerAuditProject(['audit_local_composer' => true]);
        Storage::disk('local')->put(
            "projects/{$project->uuid}/composer.lock.gz",
            gzencode(json_encode(['packages' => [['name' => 'vendor/client-pkg', 'version' => '1.0.0']], 'packages-dev' => []])),
        );

        $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/client-pkg']);
        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => '1.0.0']);
        VulnFinding::factory()->create([
            'package_id' => $package->id,
            'package_version' => '1.0.0',
            'advisory_id' => 'GHSA-client-only',
            'source' => VulnSourceEnum::ClientAudit->value,
        ]);

        (new ComposerAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));

        Notification::assertSentTo($project->user, AuditDiscrepancyNotification::class, fn (AuditDiscrepancyNotification $n): bool => $n->onlyInClient === ['GHSA-client-only']);
    } finally {
        $restorePath();
    }
});

test('ComposerAuditJob removes its temp working directory after the run', function (): void {
    Storage::fake('local');
    Notification::fake();

    $restorePath = fakeComposerBinary(json_encode(['advisories' => []]), auditExitCode: 0, outdatedJson: json_encode(['locked' => []]), outdatedExitCode: 0);
    // Fresh per run: a fixed value collides with a dir left behind by an earlier run
    // (e.g. a mutation-testing mutant that removes the cleanup call).
    $uuid = (string) Str::uuid();

    try {
        $project = composerAuditProject(['audit_local_composer' => true]);
        Storage::disk('local')->put("projects/{$project->uuid}/composer.lock.gz", gzencode(json_encode(['packages' => [], 'packages-dev' => []])));

        Str::createUuidsUsing(fn (): UuidInterface => Uuid::fromString($uuid));

        try {
            (new ComposerAuditJob($project))->handle(resolve(NotificationDispatcherService::class), resolve(SiblingFindingNotifierService::class), resolve(SiblingOutdatedNotifierService::class));
        } finally {
            Str::createUuidsNormally();
        }

        expect(file_exists(sys_get_temp_dir().'/audit-composer-'.$uuid))->toBeFalse();
    } finally {
        $restorePath();
    }
});

test('ComposerAuditJob syncFindings inserts new findings and keeps reconfirmed ones without duplicating', function (): void {
    $project = composerAuditProject();
    Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/a']);
    Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/b']);
    Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/c']);

    $job = new ComposerAuditJob($project);
    $syncFindings = new ReflectionMethod($job, 'syncFindings');

    $first = $syncFindings->invoke($job, [
        ['package_name' => 'vendor/a', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-aaa', 'severity' => 'high'],
        ['package_name' => 'vendor/b', 'package_version' => '2.0.0', 'advisory_id' => 'GHSA-bbb', 'severity' => 'low'],
    ]);

    expect($first)->toHaveCount(2);
    expect(VulnFinding::query()->where('source', VulnSourceEnum::ServerComposerAudit->value)->count())->toBe(2);

    $reconfirmed = VulnFinding::query()->where('advisory_id', 'GHSA-aaa')->sole();
    $firstSeenAt = $reconfirmed->last_seen_at;
    $this->travel(1)->hours();

    // Second sync: vendor/a still reported (not "new" again, last_seen_at bumped), vendor/c is new.
    $second = $syncFindings->invoke($job, [
        ['package_name' => 'vendor/a', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-aaa', 'severity' => 'high'],
        ['package_name' => 'vendor/c', 'package_version' => '3.0.0', 'advisory_id' => 'GHSA-ccc', 'severity' => 'critical'],
    ]);

    expect($second)->toHaveCount(1)
        ->and($second[0]['advisory_id'])->toBe('GHSA-ccc');

    expect(VulnFinding::query()->where('source', VulnSourceEnum::ServerComposerAudit->value)->count())->toBe(3);

    expect($reconfirmed->fresh()?->last_seen_at?->gt($firstSeenAt))->toBeTrue();
});

test('ComposerAuditJob notifies a sibling project when the shared latest_version changes', function (): void {
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode(['advisories' => []]);
    $outdatedJson = json_encode([
        'locked' => [
            ['name' => 'vendor/shared', 'version' => '1.0.0', 'latest' => '2.0.0'],
        ],
    ]);
    $restorePath = fakeComposerBinary($auditJson, auditExitCode: 0, outdatedJson: $outdatedJson, outdatedExitCode: 0);

    try {
        $actingUser = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $actingUser->id,
            'settings' => ['audit_local_composer' => true],
        ]);

        $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/composer.lock.gz",
            gzencode(json_encode([
                'packages' => [
                    ['name' => 'vendor/shared', 'version' => '1.0.0'],
                ],
                'packages-dev' => [],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Composer->value,
            'last_audited_at' => null,
        ]);

        $package = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/shared', 'latest_version' => '1.0.0']);
        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $package->id, 'version' => '1.0.0']);
        // Deliberately different from the acting project's version: proves SiblingOutdatedNotifierService
        // recomputes outdatedPackages() per-sibling instead of reusing the acting project's own result.
        ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $package->id, 'version' => '0.9.0']);

        (new ComposerAuditJob($project))->handle(
            resolve(NotificationDispatcherService::class),
            resolve(SiblingFindingNotifierService::class),
            resolve(SiblingOutdatedNotifierService::class),
        );

        expect($package->fresh()->latest_version)->toBe('2.0.0');

        Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
            $summary = $n->payload->outdatedByEcosystem['composer'] ?? null;

            if ($summary === null) {
                return false;
            }

            $all = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

            // 'current' must be the sibling's own 0.9.0, not the acting project's 1.0.0 — confirms
            // this is a per-sibling computation, not the acting project's outdated list reused.
            return collect($all)->contains(fn (array $p): bool => $p['name'] === 'vendor/shared' && $p['current'] === '0.9.0' && $p['latest'] === '2.0.0');
        });
    } finally {
        $restorePath();
    }
});

test('ComposerAuditJob fires both sibling notifiers independently in the same run', function (): void {
    // ComposerAuditJob::handle() calls SiblingFindingNotifierService and SiblingOutdatedNotifierService
    // separately off one runAudit() call. Each builds and dispatches its own
    // ProjectNotificationPayloadValueObject (NotificationDispatcherService::dispatch() is called once
    // per notifier, not once combined) — so a sibling affected by both a new finding and a changed
    // latest_version receives TWO separate ProjectChangesNotification deliveries, not one merged one.
    Storage::fake('local');
    Notification::fake();

    $auditJson = json_encode([
        'advisories' => [
            'vendor/dual-finding' => [
                ['advisoryId' => 'GHSA-dual-0001', 'packageName' => 'vendor/dual-finding', 'affectedVersions' => '<2.0.0', 'severity' => 'high'],
            ],
        ],
    ]);
    $outdatedJson = json_encode([
        'locked' => [
            ['name' => 'vendor/dual-outdated', 'version' => '1.0.0', 'latest' => '2.0.0'],
        ],
    ]);
    $restorePath = fakeComposerBinary($auditJson, auditExitCode: 1, outdatedJson: $outdatedJson, outdatedExitCode: 0);

    try {
        $actingUser = User::factory()->create();
        $project = Project::factory()->create([
            'user_id' => $actingUser->id,
            'settings' => ['audit_local_composer' => true],
        ]);

        $siblingUser = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
        $sibling = Project::factory()->create(['user_id' => $siblingUser->id, 'settings' => ['notify_immediately' => true]]);

        Storage::disk('local')->put(
            "projects/{$project->uuid}/composer.lock.gz",
            gzencode(json_encode([
                'packages' => [
                    ['name' => 'vendor/dual-finding', 'version' => '1.0.0'],
                    ['name' => 'vendor/dual-outdated', 'version' => '1.0.0'],
                ],
                'packages-dev' => [],
            ])),
        );

        ProjectEcosystem::factory()->create([
            'project_id' => $project->id,
            'ecosystem' => EcosystemEnum::Composer->value,
            'last_audited_at' => null,
        ]);

        // syncFindings() only keeps findings it can resolve to a Package catalog row.
        $findingPackage = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/dual-finding']);
        $outdatedPackage = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create(['name' => 'vendor/dual-outdated', 'latest_version' => '1.0.0']);

        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $findingPackage->id, 'version' => '1.0.0']);
        ProjectPackage::factory()->create(['project_id' => $project->id, 'package_id' => $outdatedPackage->id, 'version' => '1.0.0']);

        // The finding notifier matches siblings on the exact reported package_version (the acting
        // project's own installed version — see ComposerAuditParser), so the sibling must be on the
        // same version to receive it. The outdated notifier has no such constraint: it's used here
        // with a different version to keep proving per-sibling outdatedPackages() computation.
        ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $findingPackage->id, 'version' => '1.0.0']);
        ProjectPackage::factory()->create(['project_id' => $sibling->id, 'package_id' => $outdatedPackage->id, 'version' => '0.9.0']);

        (new ComposerAuditJob($project))->handle(
            resolve(NotificationDispatcherService::class),
            resolve(SiblingFindingNotifierService::class),
            resolve(SiblingOutdatedNotifierService::class),
        );

        expect(VulnFinding::query()
            ->whereHas('package', fn ($q) => $q->where('name', 'vendor/dual-finding'))
            ->where('advisory_id', 'GHSA-dual-0001')
            ->where('source', VulnSourceEnum::ServerComposerAudit->value)
            ->exists())->toBeTrue();
        expect($outdatedPackage->fresh()->latest_version)->toBe('2.0.0');

        // Two independent notifications, not one combined payload.
        Notification::assertSentToTimes($siblingUser, ProjectChangesNotification::class, 2);

        Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
            $findings = $n->payload->findingsByEcosystem['composer'] ?? [];

            return $n->payload->outdatedByEcosystem === []
                && collect($findings)->contains(fn (array $f): bool => $f['advisory_id'] === 'GHSA-dual-0001');
        });

        Notification::assertSentTo($siblingUser, ProjectChangesNotification::class, function (ProjectChangesNotification $n): bool {
            $summary = $n->payload->outdatedByEcosystem['composer'] ?? null;

            if ($summary === null) {
                return false;
            }

            $all = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

            return $n->payload->findingsByEcosystem === []
                && collect($all)->contains(fn (array $p): bool => $p['name'] === 'vendor/dual-outdated' && $p['current'] === '0.9.0' && $p['latest'] === '2.0.0');
        });
    } finally {
        $restorePath();
    }
});
