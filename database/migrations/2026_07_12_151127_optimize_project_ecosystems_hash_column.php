<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `current_hash` is always sha256(...) (see ProcessProjectInventoryJob::hash) — a fixed
 * 64-char hex digest, never anything else. char(64) drops the varchar length prefix and
 * matches the true fixed width instead of reserving up to 255 bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_ecosystems', static function (Blueprint $blueprint): void {
            $blueprint->char('current_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_ecosystems', static function (Blueprint $blueprint): void {
            $blueprint->string('current_hash')->nullable()->change();
        });
    }
};
