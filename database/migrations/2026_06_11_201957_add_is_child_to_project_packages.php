<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_packages', static function (Blueprint $table): void {
            $table->boolean('is_child')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('project_packages', static function (Blueprint $table): void {
            $table->dropColumn('is_child');
        });
    }
};
