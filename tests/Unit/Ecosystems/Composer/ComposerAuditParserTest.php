<?php

use App\Ecosystems\Composer\Parsers\ComposerAuditParser;

it('parses composer audit advisories into finding rows', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/foo' => [[
                'advisoryId' => 'GHSA-1234-aaaa-bbbb',
                'packageName' => 'vendor/foo',
                'affectedVersions' => '>=1.0,<1.2',
                'severity' => 'high',
            ]],
        ],
    ]);

    $findings = ComposerAuditParser::parse($json, ['vendor/foo' => '1.1.5']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['advisory_id'])->toBe('GHSA-1234-aaaa-bbbb')
        ->and($findings[0]['package_name'])->toBe('vendor/foo')
        ->and($findings[0]['package_version'])->toBe('1.1.5')
        ->and($findings[0]['severity'])->toBe('high');
});

it('returns empty array for no advisories', function (): void {
    expect(ComposerAuditParser::parse(json_encode(['advisories' => []])))->toBeEmpty();
});

it('returns empty array for invalid JSON', function (): void {
    expect(ComposerAuditParser::parse('not-json'))->toBeEmpty();
});

test('ComposerAuditParser resolves package_version from the installed-versions map', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => [
                ['advisoryId' => 'GHSA-aaaa', 'packageName' => 'vendor/pkg', 'affectedVersions' => '>=1.0,<1.2', 'severity' => 'high'],
            ],
        ],
    ]);

    $findings = ComposerAuditParser::parse($json, ['vendor/pkg' => '1.1.5']);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['package_version'])->toBe('1.1.5');
});

test('ComposerAuditParser falls back to unknown when package is not in the installed-versions map', function (): void {
    $json = json_encode([
        'advisories' => [
            'vendor/pkg' => [
                ['advisoryId' => 'GHSA-aaaa', 'packageName' => 'vendor/pkg', 'affectedVersions' => '>=1.0,<1.2', 'severity' => 'high'],
            ],
        ],
    ]);

    $findings = ComposerAuditParser::parse($json, []);

    expect($findings[0]['package_version'])->toBe('unknown');
});
