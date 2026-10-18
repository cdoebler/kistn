<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vuln_findings', function (Blueprint $table): void {
            $table->dropForeign(['project_id']);
            $table->dropUnique('vuln_findings_unique');
            $table->dropIndex(['project_id', 'ecosystem']);
            $table->dropIndex(['resolved_at']);
        });

        Schema::table('vuln_findings', function (Blueprint $table): void {
            $table->dropColumn(['project_id', 'ecosystem', 'package_name', 'resolved_at']);
            $table->foreignId('package_id')->nullable(false)->change();
            $table->unique(['package_id', 'package_version', 'advisory_id'], 'vuln_findings_unique');
        });
    }

    public function down(): void
    {
        Schema::table('vuln_findings', function (Blueprint $table): void {
            $table->dropUnique('vuln_findings_unique');
            $table->foreignId('package_id')->nullable()->change();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem');
            $table->string('package_name');
            $table->timestamp('resolved_at')->nullable();
            $table->unique(['project_id', 'ecosystem', 'package_name', 'advisory_id'], 'vuln_findings_unique');
            $table->index(['project_id', 'ecosystem']);
            $table->index('resolved_at');
        });
    }
};
