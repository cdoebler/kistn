<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_packages', function (Blueprint $table): void {
            $table->string('notified_latest_version')->nullable()->after('version');
        });

        // Treat every currently-known latest_version as already notified — the daily
        // digests up to now already reported them (repeatedly). Without this, the first
        // delta digest after deploy would re-list every outdated package once.
        DB::table('project_packages')->update([
            'notified_latest_version' => DB::raw('(SELECT packages.latest_version FROM packages WHERE packages.id = project_packages.package_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('project_packages', function (Blueprint $table): void {
            $table->dropColumn('notified_latest_version');
        });
    }
};
