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
        Schema::table('packages', static function (Blueprint $table) {
            $table->string('source_url', 2048)->nullable()->after('latest_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', static function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
