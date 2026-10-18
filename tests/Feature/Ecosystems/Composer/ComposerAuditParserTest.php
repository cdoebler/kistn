<?php

use App\Ecosystems\Composer\Parsers\ComposerAuditParser;

test('ComposerAuditParser returns empty array for invalid JSON', function (): void {
    $result = ComposerAuditParser::parse('not valid json {{{');

    expect($result)->toBe([]);
});

test('ComposerAuditParser returns empty array when advisories key is missing', function (): void {
    $result = ComposerAuditParser::parse(json_encode(['packages' => []]));

    expect($result)->toBe([]);
});

test('ComposerAuditParser returns empty array when advisories is not an array', function (): void {
    $result = ComposerAuditParser::parse(json_encode(['advisories' => 'invalid']));

    expect($result)->toBe([]);
});

test('ComposerAuditParser returns empty array for non-array top-level value', function (): void {
    $result = ComposerAuditParser::parse(json_encode('just a string'));

    expect($result)->toBe([]);
});

test('ComposerAuditParser skips non-array package advisory group', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => 'not-an-array',
        ],
    ]);

    $result = ComposerAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('ComposerAuditParser skips non-array individual advisory', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => ['not-an-array-advisory'],
        ],
    ]);

    $result = ComposerAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('ComposerAuditParser skips advisory with missing required fields', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => [
                ['advisoryId' => 'GHSA-abc'],  // missing packageName, affectedVersions, severity
            ],
        ],
    ]);

    $result = ComposerAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('ComposerAuditParser skips advisory with non-string field values', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => [
                [
                    'advisoryId' => 'GHSA-abc',
                    'packageName' => ['vendor/pkg'],  // array instead of string
                    'affectedVersions' => '>=1.0,<2.0',
                    'severity' => 'high',
                ],
            ],
        ],
    ]);

    $result = ComposerAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('ComposerAuditParser resolves package_version from the installed-versions map', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => [
                [
                    'advisoryId' => 'GHSA-xxxx-yyyy-zzzz',
                    'packageName' => 'vendor/pkg',
                    'affectedVersions' => '>=1.0,<1.2.3',
                    'severity' => 'high',
                ],
            ],
        ],
    ]);

    $result = ComposerAuditParser::parse($json, ['vendor/pkg' => '1.1.0']);

    expect($result)->toHaveCount(1)
        ->and($result[0]['package_version'])->toBe('1.1.0')
        ->and($result[0]['package_name'])->toBe('vendor/pkg')
        ->and($result[0]['advisory_id'])->toBe('GHSA-xxxx-yyyy-zzzz')
        ->and($result[0]['severity'])->toBe('high');
});

test('ComposerAuditParser falls back to unknown when installed-versions map has no entry', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => [
                [
                    'advisoryId' => 'GHSA-xxxx-yyyy-zzzz',
                    'packageName' => 'vendor/pkg',
                    'affectedVersions' => '>=1.0',
                    'severity' => 'medium',
                ],
            ],
        ],
    ]);

    $result = ComposerAuditParser::parse($json);

    expect($result)->toHaveCount(1)
        ->and($result[0]['package_version'])->toBe('unknown');
});

test('ComposerAuditParser parses multiple valid advisories across packages', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/foo' => [
                [
                    'advisoryId' => 'GHSA-aaaa-bbbb-cccc',
                    'packageName' => 'vendor/foo',
                    'affectedVersions' => '>=2.0,<2.1',
                    'severity' => 'critical',
                ],
            ],
            'vendor/bar' => [
                [
                    'advisoryId' => 'GHSA-1111-2222-3333',
                    'packageName' => 'vendor/bar',
                    'affectedVersions' => '<3.0',
                    'severity' => 'low',
                ],
            ],
        ],
    ]);

    $result = ComposerAuditParser::parse($json, ['vendor/foo' => '2.0.5', 'vendor/bar' => '2.9.0']);

    expect($result)->toHaveCount(2)
        ->and($result[0]['advisory_id'])->toBe('GHSA-aaaa-bbbb-cccc')
        ->and($result[0]['package_version'])->toBe('2.0.5')
        ->and($result[1]['advisory_id'])->toBe('GHSA-1111-2222-3333')
        ->and($result[1]['package_version'])->toBe('2.9.0');
});
