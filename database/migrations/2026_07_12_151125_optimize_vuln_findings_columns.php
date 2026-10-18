<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `severity` (App\Enums\SeverityEnum) and `source` (App\Enums\VulnSourceEnum) are
 * strictly app-cast — never free text. vuln_findings is append-only, so shrinking
 * these two low-cardinality columns compounds over time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vuln_findings', static function (Blueprint $blueprint): void {
            $blueprint->enum('severity', ['low', 'medium', 'high', 'critical'])->change();
            $blueprint->enum('source', [
                'client-audit',
                'server-osv',
                'server-wpscan',
                'server-github',
                'server-composer-audit',
                'server-npm-audit',
            ])->change();
        });
    }

    public function down(): void
    {
        Schema::table('vuln_findings', static function (Blueprint $blueprint): void {
            $blueprint->string('severity')->change();
            $blueprint->string('source')->change();
        });
    }
};
