<?php

use App\Ecosystems\Npm\Parsers\NpmOutdatedParser;

it('parses npm outdated JSON into name => latest_version map', function (): void {
    $json = json_encode([
        'lodash' => ['current' => '4.17.11', 'wanted' => '4.17.21', 'latest' => '4.17.21'],
        'express' => ['wanted' => '4.18.0', 'latest' => '4.19.0'],
    ]);

    expect(NpmOutdatedParser::parse($json))->toBe(['lodash' => '4.17.21', 'express' => '4.19.0']);
});

it('returns empty array for nothing outdated', function (): void {
    expect(NpmOutdatedParser::parse(json_encode([])))->toBeEmpty();
});

it('returns empty array for invalid JSON', function (): void {
    expect(NpmOutdatedParser::parse('not-json'))->toBeEmpty();
});
