<?php

use App\Ecosystems\Wp\Clients\WpOrgClient;
use Illuminate\Support\Facades\Http;

test('latestPluginVersion returns version string when API responds with 200', function (): void {
    Http::fake([
        'https://api.wordpress.org/plugins/info/1.2/*' => Http::response(['version' => '5.3.0'], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestPluginVersion('akismet'))->toBe('5.3.0');
});

test('latestPluginVersion returns null when API responds with 404', function (): void {
    Http::fake([
        'https://api.wordpress.org/plugins/info/1.2/*' => Http::response([], 404),
    ]);

    $client = new WpOrgClient;

    expect($client->latestPluginVersion('akismet'))->toBeNull();
});

test('latestPluginVersion returns null when API responds with non-array JSON', function (): void {
    Http::fake([
        'https://api.wordpress.org/plugins/info/1.2/*' => Http::response('"not-an-array"', 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestPluginVersion('akismet'))->toBeNull();
});

test('latestPluginVersion returns null when HTTP throws an exception', function (): void {
    Http::fake([
        'https://api.wordpress.org/plugins/info/1.2/*' => function (): never {
            throw new RuntimeException('network error');
        },
    ]);

    $client = new WpOrgClient;

    expect($client->latestPluginVersion('akismet'))->toBeNull();
});

test('latestThemeVersion returns version string when API responds with 200', function (): void {
    Http::fake([
        'https://api.wordpress.org/themes/info/1.2/*' => Http::response(['version' => '2.1.0'], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestThemeVersion('astra'))->toBe('2.1.0');
});

test('latestThemeVersion returns null when API responds with non-success', function (): void {
    Http::fake([
        'https://api.wordpress.org/themes/info/1.2/*' => Http::response([], 500),
    ]);

    $client = new WpOrgClient;

    expect($client->latestThemeVersion('astra'))->toBeNull();
});

test('latestCoreVersion returns version from the first non-development offer', function (): void {
    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/' => Http::response([
            'offers' => [
                ['response' => 'upgrade', 'current' => '6.7.1', 'version' => '6.7.1'],
            ],
        ], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestCoreVersion())->toBe('6.7.1');
});

test('latestCoreVersion returns null when API responds with non-success', function (): void {
    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/' => Http::response([], 503),
    ]);

    $client = new WpOrgClient;

    expect($client->latestCoreVersion())->toBeNull();
});

test('latestCoreVersion returns null when offers array is empty', function (): void {
    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/' => Http::response(['offers' => []], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestCoreVersion())->toBeNull();
});

test('latestCoreVersion returns null when all offers have response=development', function (): void {
    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/' => Http::response([
            'offers' => [
                ['response' => 'development', 'current' => '6.8-alpha', 'version' => '6.8-alpha'],
            ],
        ], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestCoreVersion())->toBeNull();
});

test('latestCoreVersion returns null when HTTP throws an exception', function (): void {
    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/' => function (): never {
            throw new RuntimeException('network error');
        },
    ]);

    $client = new WpOrgClient;

    expect($client->latestCoreVersion())->toBeNull();
});

test('latestCoreVersion uses the current field from the offer', function (): void {
    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/' => Http::response([
            'offers' => [
                ['response' => 'upgrade', 'current' => '6.7.1'],
            ],
        ], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestCoreVersion())->toBe('6.7.1');
});

test('latestCoreVersion falls back to version field when current is absent', function (): void {
    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/' => Http::response([
            'offers' => [
                ['response' => 'upgrade', 'version' => '6.6.0'],
            ],
        ], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestCoreVersion())->toBe('6.6.0');
});

test('pluginAddedDate returns the added date when API responds with 200', function (): void {
    Http::fake([
        'https://api.wordpress.org/plugins/info/1.2/*' => Http::response(['added' => '2026-07-08'], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->pluginAddedDate('acme-plugin'))->toBe('2026-07-08');
});

test('pluginAddedDate returns null when API responds with 404', function (): void {
    Http::fake([
        'https://api.wordpress.org/plugins/info/1.2/*' => Http::response([], 404),
    ]);

    $client = new WpOrgClient;

    expect($client->pluginAddedDate('acme-plugin'))->toBeNull();
});

test('themeAddedDate returns the added date when API responds with 200', function (): void {
    Http::fake([
        'https://api.wordpress.org/themes/info/1.2/*' => Http::response(['added' => '2026-01-01'], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->themeAddedDate('astra'))->toBe('2026-01-01');
});

test('themeAddedDate returns null when HTTP throws an exception', function (): void {
    Http::fake([
        'https://api.wordpress.org/themes/info/1.2/*' => function (): never {
            throw new RuntimeException('network error');
        },
    ]);

    $client = new WpOrgClient;

    expect($client->themeAddedDate('astra'))->toBeNull();
});

test('latestCoreVersion skips development offers and returns first stable one', function (): void {
    Http::fake([
        'https://api.wordpress.org/core/version-check/1.7/' => Http::response([
            'offers' => [
                ['response' => 'development', 'current' => '6.8-alpha', 'version' => '6.8-alpha'],
                ['response' => 'upgrade', 'current' => '6.7.1', 'version' => '6.7.1'],
            ],
        ], 200),
    ]);

    $client = new WpOrgClient;

    expect($client->latestCoreVersion())->toBe('6.7.1');
});
