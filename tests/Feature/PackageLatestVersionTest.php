<?php

use App\Models\Package;

it('stores and retrieves latest_version', function (): void {
    $package = Package::factory()->create(['latest_version' => null]);

    expect($package->latest_version)->toBeNull();

    $package->update(['latest_version' => '2.5.0']);

    expect($package->fresh()->latest_version)->toBe('2.5.0');
});
