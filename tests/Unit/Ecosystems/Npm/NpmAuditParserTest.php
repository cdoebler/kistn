<?php

use App\Ecosystems\Npm\Parsers\NpmAuditParser;

it('parses npm audit vulnerabilities into finding rows', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => [
                'name' => 'lodash',
                'severity' => 'high',
                'via' => [['url' => 'https://github.com/advisories/GHSA-1234-xxxx-xxxx']],
            ],
        ],
    ]);

    $findings = NpmAuditParser::parse($json);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['package_name'])->toBe('lodash')
        ->and($findings[0]['advisory_id'])->toBe('GHSA-1234-xxxx-xxxx')
        ->and($findings[0]['severity'])->toBe('high')
        ->and($findings[0]['package_version'])->toBe('*');
});

it('resolves package_version from the installed-versions map', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'minimist' => [
                'severity' => 'high',
                'via' => [['url' => 'https://github.com/advisories/GHSA-xvch-5gv4-984h']],
            ],
        ],
    ]);

    $findings = NpmAuditParser::parse($json, ['minimist' => '1.2.5']);

    expect($findings[0]['package_version'])->toBe('1.2.5');
});

it('maps moderate to medium', function (): void {
    $json = json_encode(['vulnerabilities' => ['pkg' => ['name' => 'pkg', 'severity' => 'moderate', 'via' => [['url' => 'https://github.com/advisories/GHSA-aaaa-bbbb-cccc']]]]]);
    expect(NpmAuditParser::parse($json)[0]['severity'])->toBe('medium');
});

it('maps info to low', function (): void {
    $json = json_encode(['vulnerabilities' => ['pkg' => ['name' => 'pkg', 'severity' => 'info', 'via' => [['url' => 'https://github.com/advisories/GHSA-zzzz-zzzz-zzzz']]]]]);
    expect(NpmAuditParser::parse($json)[0]['severity'])->toBe('low');
});

it('returns empty array for no vulnerabilities', function (): void {
    expect(NpmAuditParser::parse(json_encode(['vulnerabilities' => []])))->toBeEmpty();
});

it('returns empty array for invalid JSON', function (): void {
    expect(NpmAuditParser::parse('not-json'))->toBeEmpty();
});
