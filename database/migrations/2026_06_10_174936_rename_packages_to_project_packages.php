<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('packages', 'project_packages');

        Schema::table('project_packages', function (Blueprint $table): void {
            $table->unsignedBigInteger('package_id')->nullable()->after('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('project_packages', function (Blueprint $table): void {
            $table->dropColumn('package_id');
        });

        Schema::rename('project_packages', 'packages');
    }
};
