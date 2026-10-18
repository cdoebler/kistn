<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem');
            $table->string('name');
            $table->string('version');
            $table->boolean('is_direct')->default(false);
            $table->unsignedTinyInteger('depth')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'ecosystem', 'name']);
            $table->index(['project_id', 'ecosystem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
