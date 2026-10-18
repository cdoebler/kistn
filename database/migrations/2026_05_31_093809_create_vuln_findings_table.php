<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vuln_findings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem');
            $table->string('package_name');
            $table->string('package_version');
            $table->string('advisory_id');
            $table->string('severity');
            $table->string('source');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();

            $table->unique(['project_id', 'ecosystem', 'package_name', 'advisory_id'], 'vuln_findings_unique');
            $table->index(['project_id', 'ecosystem']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vuln_findings');
    }
};
