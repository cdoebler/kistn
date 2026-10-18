<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes client-reported "findings" that only depend on a vulnerable package (npm audit's
 * string `via`), stored as advisory_id "unknown". They inflated vulnerability counts in mails
 * without naming any advisory. BaseEcosystemProcessor now drops them on ingest.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $deletedFindings = DB::table('vuln_findings')
                ->whereRaw("LOWER(TRIM(advisory_id)) IN ('', 'unknown')")
                ->delete();

            $updatedItems = 0;
            $deletedItems = 0;

            DB::table('notification_digest_items')->where('type', 'vuln')->orderBy('id')
                ->each(function (object $item) use (&$updatedItems, &$deletedItems): void {
                    $findings = json_decode((string) $item->data, true);

                    if (! is_array($findings)) {
                        return;
                    }

                    $kept = array_values(array_filter(
                        $findings,
                        fn (mixed $f): bool => ! is_array($f)
                            || ! in_array(strtolower(trim((string) ($f['advisory_id'] ?? ''))), ['', 'unknown'], true),
                    ));

                    if (count($kept) === count($findings)) {
                        return;
                    }

                    if ($kept === []) {
                        DB::table('notification_digest_items')->where('id', $item->id)->delete();
                        $deletedItems++;

                        return;
                    }

                    DB::table('notification_digest_items')->where('id', $item->id)->update(['data' => json_encode($kept)]);
                    $updatedItems++;
                });

            Log::info('Migration: removed findings without advisory id', [
                'vuln_findings_deleted' => $deletedFindings,
                'digest_items_updated' => $updatedItems,
                'digest_items_deleted' => $deletedItems,
            ]);
        });
    }

    public function down(): void
    {
        // Irreversible: the deleted rows carried no advisory to restore.
    }
};
