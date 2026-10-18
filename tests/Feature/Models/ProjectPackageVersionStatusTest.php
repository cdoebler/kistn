<?php

use App\Enums\VersionStatusEnum;
use App\Models\Package;
use App\Models\ProjectPackage;

it('derives version_status from installed version and package latest_version', function (string $installed, ?string $latest, VersionStatusEnum $expected): void {
    $package = Package::factory()->create(['latest_version' => $latest]);
    $projectPackage = ProjectPackage::factory()->create([
        'package_id' => $package->id,
        'version' => $installed,
    ]);

    expect($projectPackage->version_status)->toBe($expected);
})->with([
    'behind' => ['1.0.0', '2.0.0', VersionStatusEnum::Behind],
    'ahead' => ['2.0.0', '1.5.0', VersionStatusEnum::Ahead],
    'match' => ['1.0.0', '1.0.0', VersionStatusEnum::UpToDate],
    'unknown latest' => ['1.0.0', null, VersionStatusEnum::Unknown],
]);

it('labels only the actionable and informational states', function (): void {
    expect(VersionStatusEnum::Behind->label())->not->toBeNull()
        ->and(VersionStatusEnum::Ahead->label())->not->toBeNull()
        ->and(VersionStatusEnum::UpToDate->label())->toBeNull()
        ->and(VersionStatusEnum::Unknown->label())->toBeNull();
});
