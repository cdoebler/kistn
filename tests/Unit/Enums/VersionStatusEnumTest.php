<?php

use App\Enums\VersionStatusEnum;

it('classifies installed lower than latest as behind', function (): void {
    expect(VersionStatusEnum::fromVersions('1.0.0', '2.0.0'))->toBe(VersionStatusEnum::Behind);
});

it('classifies installed higher than latest as ahead', function (): void {
    expect(VersionStatusEnum::fromVersions('2.0.0', '1.5.0'))->toBe(VersionStatusEnum::Ahead);
});

it('classifies equal versions as match', function (): void {
    expect(VersionStatusEnum::fromVersions('1.0.0', '1.0.0'))->toBe(VersionStatusEnum::UpToDate);
});

it('classifies a missing latest version as unknown', function (): void {
    expect(VersionStatusEnum::fromVersions('1.0.0', null))->toBe(VersionStatusEnum::Unknown)
        ->and(VersionStatusEnum::fromVersions('1.0.0', ''))->toBe(VersionStatusEnum::Unknown);
});

it('handles pre-release ordering via version_compare', function (): void {
    expect(VersionStatusEnum::fromVersions('1.0.0-beta', '1.0.0'))->toBe(VersionStatusEnum::Behind)
        ->and(VersionStatusEnum::fromVersions('1.0.0', '1.0.0-beta'))->toBe(VersionStatusEnum::Ahead);
});

it('gives behind an up arrow and ahead a down arrow', function (): void {
    expect(VersionStatusEnum::Behind->arrow())->toBe('↑')
        ->and(VersionStatusEnum::Ahead->arrow())->toBe('↓')
        ->and(VersionStatusEnum::UpToDate->arrow())->toBe('')
        ->and(VersionStatusEnum::Unknown->arrow())->toBe('');
});

it('colors behind amber and ahead blue, others neutral', function (): void {
    expect(VersionStatusEnum::Behind->textClasses())->toContain('amber')
        ->and(VersionStatusEnum::Ahead->textClasses())->toContain('blue')
        ->and(VersionStatusEnum::UpToDate->textClasses())->toBe('')
        ->and(VersionStatusEnum::Unknown->textClasses())->toBe('');
});
