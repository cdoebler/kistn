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
            // Make package_id NOT NULL (changeColumn handles SQLite and MySQL compatibility)
            $table->unsignedBigInteger('package_id')->nullable(false)->change();

            // Drop old composite unique constraint (required for SQLite before dropping columns)
            try {
                $table->dropUnique('packages_project_id_ecosystem_name_unique');
            } catch (Exception) {
                // Ignore if doesn't exist
            }

            // The (project_id, ecosystem) index is used by the project_id FK —
            // drop the FK first, then the index, then re-add the FK.
            // SQLite requires dropForeign to specify columns, not name
            if (DB::getDriverName() === 'mysql') {
                $table->dropForeign('packages_project_id_foreign');
            } else {
                try {
                    $table->dropForeign(['project_id']);
                } catch (Exception) {
                    // Ignore if doesn't exist
                }
            }

            try {
                $table->dropIndex('packages_project_id_ecosystem_index');
            } catch (Exception) {
                // Ignore if doesn't exist
            }

            // Drop redundant columns now on the shared packages table
            $table->dropColumn(['ecosystem', 'name', 'latest_version']);

            // Re-add project_id FK (MySQL will auto-create a backing index)
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            // FK from project_packages to shared packages catalog
            $table->foreign('package_id')->references('id')->on('packages')->cascadeOnDelete();

            // New unique constraint
            $table->unique(['project_id', 'package_id']);
        });
    }

    public function down(): void
    {
        Schema::table('project_packages', function (Blueprint $table): void {
            $table->dropForeign(['package_id']);
            $table->dropUnique(['project_id', 'package_id']);

            $table->string('ecosystem')->after('package_id');
            $table->string('name')->after('ecosystem');
            $table->string('latest_version')->nullable()->after('version');

            $table->unique(['project_id', 'ecosystem', 'name']);
            $table->index(['project_id', 'ecosystem']);

            // Make package_id nullable again
            $table->unsignedBigInteger('package_id')->nullable(true)->change();
        });
    }
};
