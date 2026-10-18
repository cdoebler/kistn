<?php

use App\Models\Package;
use App\Models\ProjectPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('users table has settings and last_digest_sent_at columns', function (): void {
    expect(Schema::hasColumn('users', 'settings'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'last_digest_sent_at'))->toBeTrue();
});

test('projects table has last_notified_at column', function (): void {
    expect(Schema::hasColumn('projects', 'last_notified_at'))->toBeTrue();
});

test('notification_digest_items table exists with correct columns', function (): void {
    expect(Schema::hasTable('notification_digest_items'))->toBeTrue()
        ->and(Schema::hasColumns('notification_digest_items', ['id', 'user_id', 'project_id', 'type', 'ecosystem', 'data', 'created_at']))->toBeTrue();
});

test('notified_latest_version migration backfills from the package latest_version', function (): void {
    $migration = require database_path('migrations/2026_09_15_081310_add_notified_latest_version_to_project_packages_table.php');
    $migration->down();

    $package = Package::factory()->create(['latest_version' => '4.1.0']);
    $withoutLatest = Package::factory()->create(['latest_version' => null]);
    $projectPackage = ProjectPackage::factory()->create(['package_id' => $package->id, 'version' => '3.3.11']);
    $untouched = ProjectPackage::factory()->create(['package_id' => $withoutLatest->id]);

    $migration->up();

    expect(DB::table('project_packages')->where('id', $projectPackage->id)->value('notified_latest_version'))->toBe('4.1.0')
        ->and(DB::table('project_packages')->where('id', $untouched->id)->value('notified_latest_version'))->toBeNull();
});
