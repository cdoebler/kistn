<?php

use App\Ecosystems\Composer\Parsers\ComposerOutdatedParser;

test('ComposerOutdatedParser returns empty array for invalid JSON', function (): void {
    $result = ComposerOutdatedParser::parse('not valid json {{{');

    expect($result)->toBe([]);
});

test('ComposerOutdatedParser returns empty array for non-array top-level value', function (): void {
    $result = ComposerOutdatedParser::parse(json_encode('just a string'));

    expect($result)->toBe([]);
});

test('ComposerOutdatedParser returns empty array when neither installed nor locked key exists', function (): void {
    $result = ComposerOutdatedParser::parse(json_encode(['packages' => []]));

    expect($result)->toBe([]);
});

test('ComposerOutdatedParser returns empty array when packages list is not an array', function (): void {
    $result = ComposerOutdatedParser::parse(json_encode(['installed' => 'invalid']));

    expect($result)->toBe([]);
});

test('ComposerOutdatedParser skips non-array package entry', function (): void {
    $json = json_encode([
        'installed' => ['not-an-array'],
    ]);

    $result = ComposerOutdatedParser::parse($json);

    expect($result)->toBe([]);
});

test('ComposerOutdatedParser skips package with missing name or latest', function (): void {
    $json = json_encode([
        'installed' => [
            ['name' => 'vendor/foo'],  // missing latest
        ],
    ]);

    $result = ComposerOutdatedParser::parse($json);

    expect($result)->toBe([]);
});

test('ComposerOutdatedParser skips package with non-string name', function (): void {
    $json = json_encode([
        'installed' => [
            ['name' => 123, 'latest' => '2.0.0'],
        ],
    ]);

    $result = ComposerOutdatedParser::parse($json);

    expect($result)->toBe([]);
});

test('ComposerOutdatedParser parses installed key correctly', function (): void {
    $json = json_encode([
        'installed' => [
            ['name' => 'vendor/foo', 'latest' => '2.0.0', 'version' => '1.0.0'],
            ['name' => 'vendor/bar', 'latest' => '3.1.0', 'version' => '2.5.0'],
        ],
    ]);

    $result = ComposerOutdatedParser::parse($json);

    expect($result)->toBe([
        'vendor/foo' => '2.0.0',
        'vendor/bar' => '3.1.0',
    ]);
});

test('ComposerOutdatedParser parses locked key when installed is absent', function (): void {
    $json = json_encode([
        'locked' => [
            ['name' => 'vendor/baz', 'latest' => '4.0.0', 'version' => '3.0.0'],
        ],
    ]);

    $result = ComposerOutdatedParser::parse($json);

    expect($result)->toBe([
        'vendor/baz' => '4.0.0',
    ]);
});

test('ComposerOutdatedParser prefers installed over locked key', function (): void {
    $json = json_encode([
        'installed' => [
            ['name' => 'vendor/foo', 'latest' => '2.0.0', 'version' => '1.0.0'],
        ],
        'locked' => [
            ['name' => 'vendor/bar', 'latest' => '5.0.0', 'version' => '4.0.0'],
        ],
    ]);

    $result = ComposerOutdatedParser::parse($json);

    expect($result)->toBe(['vendor/foo' => '2.0.0'])
        ->and($result)->not->toHaveKey('vendor/bar');
});
