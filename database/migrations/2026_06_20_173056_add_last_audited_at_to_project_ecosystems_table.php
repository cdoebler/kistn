<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_ecosystems', function (Blueprint $table): void {
            $table->timestamp('last_audited_at')->nullable()->after('last_pushed_at');
        });
    }

    public function down(): void
    {
        Schema::table('project_ecosystems', function (Blueprint $table): void {
            $table->dropColumn('last_audited_at');
        });
    }
};
