<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vuln_findings', function (Blueprint $table): void {
            $table->foreignId('package_id')->nullable()->after('project_id')->constrained('packages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vuln_findings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('package_id');
        });
    }
};
