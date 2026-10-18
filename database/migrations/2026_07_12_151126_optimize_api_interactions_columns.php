<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api_interactions is an append-only call log (one row per API request) — the
 * highest-volume table in the schema over time, so column size here compounds most.
 * `status` only ever holds an HTTP status code (3 digits). `endpoint` only ever holds
 * a "METHOD /api/projects/{project}/..." literal from routes/api.php; the longest
 * current route is ~40 chars, so 64 leaves headroom without keeping the full 255.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_interactions', static function (Blueprint $blueprint): void {
            $blueprint->string('status', 3)->change();
            $blueprint->string('endpoint', 64)->change();
        });
    }

    public function down(): void
    {
        Schema::table('api_interactions', static function (Blueprint $blueprint): void {
            $blueprint->string('status')->change();
            $blueprint->string('endpoint')->change();
        });
    }
};
