<?php

use App\Ecosystems\Npm\Parsers\NpmAuditParser;

test('NpmAuditParser returns empty array for invalid JSON', function (): void {
    $result = NpmAuditParser::parse('not valid json {{{');

    expect($result)->toBe([]);
});

test('NpmAuditParser returns empty array when vulnerabilities key is missing', function (): void {
    $result = NpmAuditParser::parse(json_encode(['metadata' => []]));

    expect($result)->toBe([]);
});

test('NpmAuditParser returns empty array when vulnerabilities is not an array', function (): void {
    $result = NpmAuditParser::parse(json_encode(['vulnerabilities' => 'invalid']));

    expect($result)->toBe([]);
});

test('NpmAuditParser returns empty array for non-array top-level value', function (): void {
    $result = NpmAuditParser::parse(json_encode('just a string'));

    expect($result)->toBe([]);
});

test('NpmAuditParser skips non-array vulnerability item', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => 'not-an-array',
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmAuditParser skips vulnerability with missing severity', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => [
                'via' => [['url' => 'https://github.com/advisories/GHSA-abc1-def2-ghi3']],
                // severity missing
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmAuditParser skips vulnerability with missing via', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => [
                'severity' => 'high',
                // via missing
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmAuditParser skips vulnerability when via is not an array', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => [
                'severity' => 'high',
                'via' => 'not-an-array',
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmAuditParser skips via item without url', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => [
                'severity' => 'high',
                'via' => [
                    ['title' => 'Some advisory'],  // url missing
                ],
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmAuditParser skips via item when url has no GHSA ID', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => [
                'severity' => 'high',
                'via' => [
                    ['url' => 'https://example.com/security/advisory/12345'],
                ],
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toBe([]);
});

test('NpmAuditParser parses valid vulnerability with GHSA URL', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => [
                'severity' => 'high',
                'via' => [
                    ['url' => 'https://github.com/advisories/GHSA-35jh-r3h4-6jhm'],
                ],
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toHaveCount(1)
        ->and($result[0]['package_name'])->toBe('lodash')
        ->and($result[0]['advisory_id'])->toBe('GHSA-35jh-r3h4-6jhm')
        ->and($result[0]['severity'])->toBe('high')
        ->and($result[0]['package_version'])->toBe('*');
});

test('NpmAuditParser maps moderate severity to medium', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'express' => [
                'severity' => 'moderate',
                'via' => [
                    ['url' => 'https://github.com/advisories/GHSA-aaaa-bbbb-cccc'],
                ],
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toHaveCount(1)
        ->and($result[0]['severity'])->toBe('medium');
});

test('NpmAuditParser maps info severity to low', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'some-pkg' => [
                'severity' => 'info',
                'via' => [
                    ['url' => 'https://github.com/advisories/GHSA-zzzz-yyyy-xxxx'],
                ],
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toHaveCount(1)
        ->and($result[0]['severity'])->toBe('low');
});

test('NpmAuditParser skips non-array via items but continues with valid ones', function (): void {
    $json = json_encode([
        'vulnerabilities' => [
            'lodash' => [
                'severity' => 'critical',
                'via' => [
                    'string-not-array',
                    ['url' => 'https://github.com/advisories/GHSA-1234-5678-abcd'],
                ],
            ],
        ],
    ]);

    $result = NpmAuditParser::parse($json);

    expect($result)->toHaveCount(1)
        ->and($result[0]['advisory_id'])->toBe('GHSA-1234-5678-abcd');
});
