<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // 0 = global/public (shared); real project id = private/per-project.
            // No FK: 0 is not a valid project id; MySQL FK cannot reference a sentinel value.
            $table->unsignedBigInteger('project_id')->default(0)->after('id');
            $table->boolean('is_private')->nullable()->default(null)->after('author');
            $table->timestamp('wpscan_checked_at')->nullable()->default(null)->after('is_private');

            $table->dropUnique('packages_ecosystem_name_unique');
            $table->unique(['project_id', 'ecosystem', 'name'], 'packages_project_ecosystem_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique('packages_project_ecosystem_name_unique');
            $table->unique(['ecosystem', 'name'], 'packages_ecosystem_name_unique');
            $table->dropColumn(['project_id', 'is_private', 'wpscan_checked_at']);
        });
    }
};
