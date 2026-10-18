<?php

use App\Ecosystems\Npm\Parsers\NpmOutdatedParser;

test('NpmOutdatedParser returns empty array for invalid JSON', function (): void {
    $result = NpmOutdatedParser::parse('not valid json {{{');

    expect($result)->toBe([]);
});

test('NpmOutdatedParser returns empty array for non-array top-level value', function (): void {
    $result = NpmOutdatedParser::parse(json_encode('just a string'));

    expect($result)->toBe([]);
});

test('NpmOutdatedParser skips non-array package entry', function (): void {
    $json = json_encode([
        'lodash' => 'not-an-array',
    ]);

    $result = NpmOutdatedParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmOutdatedParser skips package with missing latest field', function (): void {
    $json = json_encode([
        'lodash' => ['current' => '4.17.20', 'wanted' => '4.17.21'],
    ]);

    $result = NpmOutdatedParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmOutdatedParser skips package when latest is not a string', function (): void {
    $json = json_encode([
        'lodash' => ['current' => '4.17.20', 'latest' => 123],
    ]);

    $result = NpmOutdatedParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmOutdatedParser parses valid outdated packages', function (): void {
    $json = json_encode([
        'lodash' => ['current' => '4.17.20', 'wanted' => '4.17.21', 'latest' => '4.17.21'],
        'express' => ['current' => '4.18.0', 'wanted' => '4.18.2', 'latest' => '5.0.0'],
    ]);

    $result = NpmOutdatedParser::parse($json);

    expect($result)->toBe([
        'lodash' => '4.17.21',
        'express' => '5.0.0',
    ]);
});

test('NpmOutdatedParser returns empty array for empty object', function (): void {
    $result = NpmOutdatedParser::parse(json_encode([]));

    expect($result)->toBe([]);
});

test('NpmOutdatedParser mixes valid and invalid entries returning only valid ones', function (): void {
    $json = json_encode([
        'good-pkg' => ['latest' => '2.0.0'],
        'bad-pkg' => 'not-an-array',
        'no-latest-pkg' => ['current' => '1.0.0'],
    ]);

    $result = NpmOutdatedParser::parse($json);

    expect($result)->toBe(['good-pkg' => '2.0.0']);
});
