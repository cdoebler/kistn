<?php

use App\Enums\EcosystemEnum;
use App\Enums\VulnSourceEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Models\VulnFinding;
use App\Notifications\AuditDiscrepancyNotification;
use App\Services\AuditDiscrepancyService;
use Illuminate\Support\Facades\Notification;

function checkerProject(): Project
{
    return Project::factory()->create(['user_id' => User::factory()->create()->id]);
}

/**
 * Creates a VulnFinding for $project, backed by a Package + ProjectPackage so it's
 * visible through Project::vulnFindings()'s package/version join. Pass $package to
 * make two calls represent the same installed package (e.g. a client-reported and a
 * server-reported finding for the same install) rather than two distinct packages.
 *
 * @param  array<string, mixed>  $overrides
 */
function checkerFinding(Project $project, EcosystemEnum $ecosystem, VulnSourceEnum $source, string $advisory, array $overrides = [], ?Package $package = null): VulnFinding
{
    $version = $overrides['package_version'] ?? '1.0.0';

    $package ??= Package::factory()->forEcosystem($ecosystem)->create();

    if (! ProjectPackage::where('project_id', $project->id)->where('package_id', $package->id)->exists()) {
        ProjectPackage::factory()->create([
            'project_id' => $project->id,
            'package_id' => $package->id,
            'version' => $version,
        ]);
    }

    return VulnFinding::factory()->create(array_merge([
        'package_id' => $package->id,
        'package_version' => $version,
        'advisory_id' => $advisory,
        'source' => $source->value,
    ], $overrides));
}

test('notifies when server finds advisory client missed', function (): void {
    Notification::fake();

    $project = checkerProject();
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ClientAudit, 'GHSA-client-only');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-server-only');

    (new AuditDiscrepancyService)->check($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit);

    Notification::assertSentTo(
        $project->user,
        AuditDiscrepancyNotification::class,
        fn (AuditDiscrepancyNotification $n): bool => in_array('GHSA-server-only', $n->onlyInServer, true)
            && in_array('GHSA-client-only', $n->onlyInClient, true)
    );
});

test('onlyInServer and onlyInClient are reindexed sequential lists, not sparse diff output', function (): void {
    Notification::fake();

    $project = checkerProject();
    // Both sides share the middle advisory, so array_diff() leaves a gap at that key on
    // both sides — proves the result is re-indexed with array_values(), not left sparse.
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ClientAudit, 'GHSA-client-1');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ClientAudit, 'GHSA-shared');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ClientAudit, 'GHSA-client-2');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-server-1');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-shared');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-server-2');

    (new AuditDiscrepancyService)->check($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit);

    Notification::assertSentTo(
        $project->user,
        AuditDiscrepancyNotification::class,
        function (AuditDiscrepancyNotification $n): bool {
            expect($n->onlyInServer)->toBe(['GHSA-server-1', 'GHSA-server-2']);
            expect($n->onlyInClient)->toBe(['GHSA-client-1', 'GHSA-client-2']);

            return true;
        }
    );
});

test('notifies when only the server side has an extra advisory', function (): void {
    Notification::fake();

    $project = checkerProject();
    // Client and server agree on everything except one server-only advisory — onlyInClient
    // stays empty while onlyInServer is not, proving the check uses OR, not AND.
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ClientAudit, 'GHSA-shared');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-shared');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-server-only');

    (new AuditDiscrepancyService)->check($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit);

    Notification::assertSentTo(
        $project->user,
        AuditDiscrepancyNotification::class,
        fn (AuditDiscrepancyNotification $n): bool => $n->onlyInServer === ['GHSA-server-only'] && $n->onlyInClient === []
    );
});

test('no notification when server and client findings match exactly', function (): void {
    Notification::fake();

    $project = checkerProject();
    // Different packages: (package_id, package_version, advisory_id) is unique, so a client
    // row and a server row for the same advisory can't share one package+version - they
    // represent two distinct installs that both happen to be flagged for the same advisory.
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ClientAudit, 'GHSA-same');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-same');

    (new AuditDiscrepancyService)->check($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit);

    Notification::assertNothingSent();
});

test('no notification when client has no findings', function (): void {
    Notification::fake();

    $project = checkerProject();
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-server-only');

    (new AuditDiscrepancyService)->check($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit);

    Notification::assertNothingSent();
});

test('ignores findings for a package version the project no longer has installed', function (): void {
    Notification::fake();

    $project = checkerProject();
    // Different packages, matching advisory: same reasoning as the exact-match test above -
    // this pair must line up (no diff) for the "no notification" outcome to be meaningful.
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ClientAudit, 'GHSA-shared');
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit, 'GHSA-shared');

    // A stray client finding for a package version the project has since upgraded past.
    // VulnFinding has no resolved_at column anymore, so a superseded version - not matching
    // the currently installed ProjectPackage.version - is how a finding becomes invisible,
    // standing in for "resolved". If the version join didn't exclude it, this would leak
    // into onlyInClient and the notification would fire.
    $stalePackage = Package::factory()->forEcosystem(EcosystemEnum::Composer)->create();
    ProjectPackage::factory()->create([
        'project_id' => $project->id,
        'package_id' => $stalePackage->id,
        'version' => '2.0.0',
    ]);
    checkerFinding($project, EcosystemEnum::Composer, VulnSourceEnum::ClientAudit, 'GHSA-old-version', ['package_version' => '0.9.0'], $stalePackage);

    (new AuditDiscrepancyService)->check($project, EcosystemEnum::Composer, VulnSourceEnum::ServerComposerAudit);

    Notification::assertNothingSent();
});
