<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('ecosystem');
            $table->string('name');
            $table->json('advisories');
            $table->timestamp('checked_at');
            $table->timestamp('expires_at');

            $table->unique(['ecosystem', 'name']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_snapshots');
    }
};
