<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `action` is App\Enums\InventoryActionEnum, strictly cast — never free text.
 * inventory_events is append-only and grows unboundedly, so shrinking this
 * high-cardinality-row / low-cardinality-value column matters most here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_events', static function (Blueprint $blueprint): void {
            $blueprint->enum('action', ['added', 'updated', 'removed'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_events', static function (Blueprint $blueprint): void {
            $blueprint->string('action')->change();
        });
    }
};
