<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $findings = DB::table('vuln_findings')->select('id', 'project_id', 'ecosystem', 'package_name')->get();

            foreach ($findings as $finding) {
                $package = DB::table('packages')
                    ->where('ecosystem', $finding->ecosystem)
                    ->where('name', $finding->package_name)
                    ->where('project_id', 0)
                    ->first();

                $package ??= DB::table('packages')
                    ->where('ecosystem', $finding->ecosystem)
                    ->where('name', $finding->package_name)
                    ->where('project_id', $finding->project_id)
                    ->first();

                if ($package !== null) {
                    DB::table('vuln_findings')->where('id', $finding->id)->update(['package_id' => $package->id]);
                }
            }

            $unmatched = DB::table('vuln_findings')->whereNull('package_id')->count();

            if ($unmatched > 0) {
                throw new RuntimeException(
                    "Backfill left {$unmatched} vuln_findings row(s) with a NULL package_id. ".
                    'The next migration adds a NOT NULL constraint on package_id and cannot proceed until these rows are resolved.'
                );
            }

            // Under the OLD schema, vuln_findings was scoped by project_id, so two different
            // projects could each have their own row for the same public package+version+advisory.
            // Now that package_id resolves to the same global Package for both, those rows collide
            // under the NEW unique key (package_id, package_version, advisory_id) added by the next
            // migration. Keep one row per group (earliest first_seen_at, tie-broken by lowest id).
            $duplicateGroups = DB::table('vuln_findings')
                ->select('package_id', 'package_version', 'advisory_id')
                ->groupBy('package_id', 'package_version', 'advisory_id')
                ->havingRaw('count(*) > 1')
                ->get();

            foreach ($duplicateGroups as $group) {
                $idsToDelete = DB::table('vuln_findings')
                    ->where('package_id', $group->package_id)
                    ->where('package_version', $group->package_version)
                    ->where('advisory_id', $group->advisory_id)
                    ->oldest('first_seen_at')
                    ->orderBy('id')
                    ->pluck('id')
                    ->slice(1);

                if ($idsToDelete->isNotEmpty()) {
                    DB::table('vuln_findings')->whereIn('id', $idsToDelete)->delete();
                }
            }
        });
    }

    public function down(): void
    {
        // No-op — package_id column itself is dropped by the migration that added it, on rollback of that migration.
    }
};
