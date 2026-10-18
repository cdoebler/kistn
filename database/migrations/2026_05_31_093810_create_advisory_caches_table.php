<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisory_caches', function (Blueprint $table): void {
            $table->id();
            $table->string('ecosystem');
            $table->string('name');
            $table->string('version');
            $table->json('advisories');
            $table->timestamp('checked_at');
            $table->timestamp('ttl_expires_at');

            $table->unique(['ecosystem', 'name', 'version']);
            $table->index('ttl_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_caches');
    }
};
