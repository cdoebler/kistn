<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `ecosystem` is a fixed, strictly app-cast set (App\Enums\EcosystemEnum) on every table
 * below — never free text. varchar(255) → enum(...) shrinks stored/index/temp-table size
 * from up to 255 bytes to 1 byte per row without changing the value domain.
 */
return new class extends Migration
{
    private const array VALUES = ['composer', 'npm', 'wp-core', 'wp-plugin', 'wp-theme'];

    private const array TABLES = [
        'packages',
        'project_ecosystems',
        'inventory_events',
        'advisory_caches',
        'advisory_snapshots',
        'notification_digest_items',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->enum('ecosystem', self::VALUES)->change();
            });
        }

        Schema::table('api_interactions', static function (Blueprint $blueprint): void {
            $blueprint->enum('ecosystem', self::VALUES)->nullable()->change();
        });
    }

    public function down(): void
    {
        foreach ([...self::TABLES, 'api_interactions'] as $table) {
            Schema::table($table, static function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('ecosystem')->nullable($table === 'api_interactions')->change();
            });
        }
    }
};
