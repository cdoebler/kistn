<?php

use App\Ecosystems\Composer\Parsers\ComposerOutdatedParser;

it('parses composer outdated JSON into name => latest_version map', function (): void {
    $json = json_encode([
        'installed' => [
            ['name' => 'vendor/foo', 'version' => '1.0.0', 'latest' => '2.0.0'],
            ['name' => 'vendor/bar', 'version' => '3.0.0', 'latest' => '3.1.0'],
        ],
    ]);

    expect(ComposerOutdatedParser::parse($json))->toBe(['vendor/foo' => '2.0.0', 'vendor/bar' => '3.1.0']);
});

it('returns empty array for empty installed list', function (): void {
    expect(ComposerOutdatedParser::parse(json_encode(['installed' => []])))->toBeEmpty();
});

it('returns empty array for invalid JSON', function (): void {
    expect(ComposerOutdatedParser::parse('not-json'))->toBeEmpty();
});

it('parses the locked key, used when composer outdated runs with --all', function (): void {
    $json = json_encode([
        'locked' => [
            ['name' => 'vendor/foo', 'version' => '1.0.0', 'latest' => '2.0.0'],
        ],
    ]);

    expect(ComposerOutdatedParser::parse($json))->toBe(['vendor/foo' => '2.0.0']);
});

it('prefers the installed key over locked when both are present', function (): void {
    $json = json_encode([
        'installed' => [['name' => 'vendor/foo', 'version' => '1.0.0', 'latest' => '2.0.0']],
        'locked' => [['name' => 'vendor/bar', 'version' => '1.0.0', 'latest' => '3.0.0']],
    ]);

    expect(ComposerOutdatedParser::parse($json))->toBe(['vendor/foo' => '2.0.0']);
});
