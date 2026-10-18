<?php

use App\Models\Package;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reproduces the exact pre-fix failure scenario: under the OLD vuln_findings schema
 * (unique on project_id+ecosystem+package_name+advisory_id), two different projects
 * could each legitimately have their own row for the same public package+version+advisory.
 * The 090100 backfill resolves both rows to the SAME global package_id, so without
 * dedup the 090200 finalize migration's new unique(package_id, package_version, advisory_id)
 * fails against pre-existing duplicate data.
 *
 * There's no existing convention in this codebase for testing a specific point in the
 * migration sequence, so migrations are invoked directly (not via the `migrate` Artisan
 * command) to avoid nesting the Migrator's own per-migration transaction inside
 * RefreshDatabase's outer test transaction. We roll the finalize migration's schema
 * back to the 090000 state (package_id nullable, old project-scoped columns/unique
 * still present), insert the duplicate-scenario rows raw, then run the backfill and
 * finalize migrations forward and assert the fix.
 */
test('backfill dedups vuln_findings sharing package_id, package_version and advisory_id across projects', function (): void {
    $finalize = require database_path('migrations/2026_07_05_090200_finalize_vuln_findings_package_scoping.php');
    $finalize->down();

    // Sanity check: we're back on the pre-migration (project-scoped) schema.
    expect(Schema::hasColumn('vuln_findings', 'project_id'))->toBeTrue()
        ->and(Schema::hasColumn('vuln_findings', 'ecosystem'))->toBeTrue();

    $package = Package::factory()->create([
        'project_id' => 0,
        'ecosystem' => 'composer',
        'name' => 'vendor/pkg',
    ]);

    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();

    DB::table('vuln_findings')->insert([
        [
            'uuid' => (string) Str::uuid(),
            'project_id' => $projectA->id,
            'ecosystem' => 'composer',
            'package_name' => 'vendor/pkg',
            'package_version' => '1.0.0',
            'advisory_id' => 'GHSA-x',
            'severity' => 'high',
            'source' => 'client-audit',
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ],
        [
            'uuid' => (string) Str::uuid(),
            'project_id' => $projectB->id,
            'ecosystem' => 'composer',
            'package_name' => 'vendor/pkg',
            'package_version' => '1.0.0',
            'advisory_id' => 'GHSA-x',
            'severity' => 'high',
            'source' => 'client-audit',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ],
    ]);

    // Re-runs 090100 (backfill + dedup) then 090200 (finalize + unique constraint).
    // Must not throw despite the duplicate group above.
    $backfill = require database_path('migrations/2026_07_05_090100_backfill_package_id_on_vuln_findings.php');
    $backfill->up();

    $finalize2 = require database_path('migrations/2026_07_05_090200_finalize_vuln_findings_package_scoping.php');
    $finalize2->up();

    $survivors = DB::table('vuln_findings')
        ->where('package_id', $package->id)
        ->where('package_version', '1.0.0')
        ->where('advisory_id', 'GHSA-x')
        ->get();

    expect($survivors)->toHaveCount(1)
        ->and(Schema::hasColumn('vuln_findings', 'project_id'))->toBeFalse();

    // The dedup must have kept the earliest-seen row (projectA's).
    expect((string) $survivors->first()->first_seen_at)->toContain(now()->subDay()->format('Y-m-d'));

    // The new unique constraint must exist and be enforced.
    expect(fn () => DB::table('vuln_findings')->insert([
        'uuid' => (string) Str::uuid(),
        'package_id' => $package->id,
        'package_version' => '1.0.0',
        'advisory_id' => 'GHSA-x',
        'severity' => 'high',
        'source' => 'client-audit',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]))->toThrow(QueryException::class);
});
