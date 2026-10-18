<?php

use App\Enums\EcosystemEnum;
use App\Models\AdvisoryCache;

test('can create an AdvisoryCache record via factory', function (): void {
    $cache = AdvisoryCache::factory()->create();

    expect($cache->id)->not->toBeNull()
        ->and($cache->ecosystem)->toBeInstanceOf(EcosystemEnum::class)
        ->and($cache->name)->not->toBeEmpty()
        ->and($cache->version)->not->toBeEmpty();
});

test('isExpired returns true when ttl_expires_at is in the past', function (): void {
    $cache = AdvisoryCache::factory()->expired()->create();

    expect($cache->isExpired())->toBeTrue();
});

test('isExpired returns false when ttl_expires_at is in the future', function (): void {
    $cache = AdvisoryCache::factory()->create([
        'ttl_expires_at' => now()->addHours(24),
    ]);

    expect($cache->isExpired())->toBeFalse();
});

test('ecosystem cast returns EcosystemEnum instance', function (): void {
    $cache = AdvisoryCache::factory()->create([
        'ecosystem' => EcosystemEnum::WpPlugin->value,
    ]);

    $fresh = AdvisoryCache::find($cache->id);

    expect($fresh->ecosystem)->toBeInstanceOf(EcosystemEnum::class)
        ->and($fresh->ecosystem)->toBe(EcosystemEnum::WpPlugin);
});

test('advisories cast stores and returns array', function (): void {
    $advisories = [
        ['id' => 'GHSA-1234-5678-9012', 'severity' => 'high', 'summary' => 'Test vulnerability'],
    ];

    $cache = AdvisoryCache::factory()->create(['advisories' => $advisories]);

    $fresh = AdvisoryCache::find($cache->id);

    expect($fresh->advisories)->toBeArray()
        ->and($fresh->advisories[0]['id'])->toBe('GHSA-1234-5678-9012');
});

test('withAdvisories factory state populates advisories', function (): void {
    $cache = AdvisoryCache::factory()->withAdvisories()->create();

    expect($cache->advisories)->toBeArray()->not->toBeEmpty();
});
