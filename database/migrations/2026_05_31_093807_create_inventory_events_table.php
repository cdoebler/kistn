<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem');
            $table->string('package_name');
            $table->string('action');
            $table->string('old_version')->nullable();
            $table->string('new_version')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['project_id', 'ecosystem']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_events');
    }
};
