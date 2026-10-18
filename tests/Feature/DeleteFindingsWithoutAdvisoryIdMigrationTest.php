<?php

use App\Models\NotificationDigestItem;
use App\Models\VulnFinding;

test('migration deletes findings and pending digest entries without an advisory id', function (): void {
    $real = VulnFinding::factory()->create(['advisory_id' => 'GHSA-vfj7-8cjw-p6xm']);
    VulnFinding::factory()->create(['advisory_id' => 'unknown']);
    VulnFinding::factory()->create(['advisory_id' => 'UNKNOWN']);

    $braces = ['package_name' => 'braces', 'package_version' => '3.0.3', 'advisory_id' => 'GHSA-vfj7-8cjw-p6xm', 'severity' => 'high'];
    $fastGlob = ['package_name' => 'fast-glob', 'package_version' => '3.3.1', 'advisory_id' => 'unknown', 'severity' => 'high'];

    $mixed = NotificationDigestItem::factory()->create(['type' => 'vuln', 'data' => [$fastGlob, $braces]]);
    $onlyUnknown = NotificationDigestItem::factory()->create(['type' => 'vuln', 'data' => [$fastGlob]]);
    $untouched = NotificationDigestItem::factory()->create(['type' => 'vuln', 'data' => [$braces]]);
    $outdated = NotificationDigestItem::factory()->create(['type' => 'outdated', 'data' => [['name' => 'x', 'advisory_id' => 'unknown']]]);

    (require database_path('migrations/2026_10_03_090834_delete_findings_without_advisory_id.php'))->up();

    expect(VulnFinding::pluck('id')->all())->toBe([$real->id])
        ->and($mixed->fresh()->data)->toBe([$braces])
        ->and($onlyUnknown->fresh())->toBeNull()
        ->and($untouched->fresh()->data)->toBe([$braces])
        ->and($outdated->fresh()->data)->toBe([['name' => 'x', 'advisory_id' => 'unknown']]);
});
