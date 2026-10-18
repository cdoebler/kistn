<?php

use App\ValueObjects\OutdatedSummaryValueObject;

// ─── classify() ──────────────────────────────────────────────────────────────

it('classifies major version bump', function (): void {
    expect(OutdatedSummaryValueObject::classify('1.2.3', '2.0.0'))->toBe('major');
});

it('classifies minor version bump', function (): void {
    expect(OutdatedSummaryValueObject::classify('1.2.3', '1.3.0'))->toBe('minor');
});

it('classifies patch version bump', function (): void {
    expect(OutdatedSummaryValueObject::classify('1.2.3', '1.2.4'))->toBe('patch');
});

it('classifies x.y versioning as minor bump', function (): void {
    expect(OutdatedSummaryValueObject::classify('1.2', '1.3'))->toBe('minor');
});

it('classifies x.y versioning as major bump', function (): void {
    expect(OutdatedSummaryValueObject::classify('1.2', '2.0'))->toBe('major');
});

it('strips pre-release metadata before classifying', function (): void {
    expect(OutdatedSummaryValueObject::classify('1.2.3-beta.1', '1.2.4-rc.1'))->toBe('patch');
});

it('strips build metadata before classifying', function (): void {
    expect(OutdatedSummaryValueObject::classify('1.2.3+build.1', '1.3.0+build.2'))->toBe('minor');
});

it('classifies non-numeric versions as other', function (): void {
    expect(OutdatedSummaryValueObject::classify('abc', '1.0.0'))->toBe('other');
});

it('classifies empty version string as other', function (): void {
    expect(OutdatedSummaryValueObject::classify('', '1.0.0'))->toBe('other');
});

it('compares version segments numerically, not as strings', function (): void {
    // '01' !== '1' as strings but must be equal numerically — proves every segment is int-cast before comparing.
    expect(OutdatedSummaryValueObject::classify('01.02.03', '1.2.3'))->toBe('other');
});

// ─── fromPackages() ──────────────────────────────────────────────────────────

it('builds summary from packages array', function (): void {
    $packages = [
        'vendor/a' => ['version' => '1.0.0', 'latest_version' => '2.0.0'],
        'vendor/b' => ['version' => '1.2.0', 'latest_version' => '1.3.0'],
        'vendor/c' => ['version' => '1.2.3', 'latest_version' => '1.2.4'],
        'plugin/d' => ['version' => 'foo', 'latest_version' => '1.0.0'],
    ];

    $summary = OutdatedSummaryValueObject::fromPackages($packages);

    expect($summary->major)->toBe([['name' => 'vendor/a', 'current' => '1.0.0', 'latest' => '2.0.0']]);
    expect($summary->minor)->toBe([['name' => 'vendor/b', 'current' => '1.2.0', 'latest' => '1.3.0']]);
    expect($summary->patch)->toBe([['name' => 'vendor/c', 'current' => '1.2.3', 'latest' => '1.2.4']]);
    expect($summary->other)->toBe([['name' => 'plugin/d', 'current' => 'foo', 'latest' => '1.0.0']]);
});

// ─── isEmpty() ───────────────────────────────────────────────────────────────

it('isEmpty returns true when all buckets are empty', function (): void {
    $summary = new OutdatedSummaryValueObject([], [], [], []);
    expect($summary->isEmpty())->toBeTrue();
});

it('isEmpty returns false when a bucket has entries', function (): void {
    $summary = new OutdatedSummaryValueObject([['name' => 'x', 'current' => '1.0', 'latest' => '2.0']], [], [], []);
    expect($summary->isEmpty())->toBeFalse();
});

// ─── matchesSettings() ───────────────────────────────────────────────────────

it('matchesSettings returns true when major enabled and major bucket non-empty', function (): void {
    $summary = new OutdatedSummaryValueObject([['name' => 'x', 'current' => '1.0.0', 'latest' => '2.0.0']], [], [], []);
    expect($summary->matchesSettings(true, false, false))->toBeTrue();
});

it('matchesSettings returns false when major enabled but only minor bucket non-empty', function (): void {
    $summary = new OutdatedSummaryValueObject([], [['name' => 'x', 'current' => '1.0.0', 'latest' => '1.1.0']], [], []);
    expect($summary->matchesSettings(true, false, false))->toBeFalse();
});

it('matchesSettings returns true when other bucket non-empty and any toggle enabled', function (): void {
    $summary = new OutdatedSummaryValueObject([], [], [], [['name' => 'plugin', 'current' => 'foo', 'latest' => 'bar']]);
    expect($summary->matchesSettings(true, false, false))->toBeTrue();
});

it('matchesSettings returns false when other bucket non-empty but all toggles disabled', function (): void {
    $summary = new OutdatedSummaryValueObject([], [], [], [['name' => 'plugin', 'current' => 'foo', 'latest' => 'bar']]);
    expect($summary->matchesSettings(false, false, false))->toBeFalse();
});

it('matchesSettings returns true when minor enabled and minor bucket non-empty', function (): void {
    $summary = new OutdatedSummaryValueObject([], [['name' => 'x', 'current' => '1.0.0', 'latest' => '1.1.0']], [], []);
    expect($summary->matchesSettings(false, true, false))->toBeTrue();
});

it('matchesSettings returns true when patch enabled and patch bucket non-empty', function (): void {
    $summary = new OutdatedSummaryValueObject([], [], [['name' => 'x', 'current' => '1.0.0', 'latest' => '1.0.1']], []);
    expect($summary->matchesSettings(false, false, true))->toBeTrue();
});
