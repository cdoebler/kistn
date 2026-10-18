<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->timestamp('churn_flagged_at')->nullable()->after('last_notified_at');
            $table->unsignedInteger('churn_flagged_count')->default(0)->after('churn_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['churn_flagged_at', 'churn_flagged_count']);
        });
    }
};
