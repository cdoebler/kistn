<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $distinctPackages = DB::table('project_packages')
                ->select('ecosystem', 'name')
                ->distinct()
                ->get();

            foreach ($distinctPackages as $pkg) {
                // Pick most recently updated non-null latest_version across all projects
                $latestVersion = DB::table('project_packages')
                    ->where('ecosystem', $pkg->ecosystem)
                    ->where('name', $pkg->name)
                    ->whereNotNull('latest_version')
                    ->latest('updated_at')
                    ->value('latest_version');

                $packageId = DB::table('packages')->insertGetId([
                    'ecosystem' => $pkg->ecosystem,
                    'name' => $pkg->name,
                    'latest_version' => $latestVersion,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('project_packages')
                    ->where('ecosystem', $pkg->ecosystem)
                    ->where('name', $pkg->name)
                    ->update(['package_id' => $packageId]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('project_packages')->update(['package_id' => null]);
        DB::table('packages')->truncate();
    }
};
