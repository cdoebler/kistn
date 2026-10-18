<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_ecosystems', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem');
            $table->string('current_hash')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_pushed_at')->nullable();
            $table->unsignedInteger('package_count')->default(0);
            $table->boolean('is_stale')->default(false);
            $table->timestamps();

            $table->unique(['project_id', 'ecosystem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_ecosystems');
    }
};
