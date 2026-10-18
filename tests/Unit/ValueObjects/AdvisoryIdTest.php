<?php

use App\ValueObjects\AdvisoryIdValueObject;

it('resolves GHSA advisory to GitHub Advisories URL', function (): void {
    expect((new AdvisoryIdValueObject('GHSA-1234-5678-9abc'))->url())
        ->toBe('https://github.com/advisories/GHSA-1234-5678-9abc');
});

it('resolves CVE advisory to cve.org URL', function (): void {
    expect((new AdvisoryIdValueObject('CVE-2021-23337'))->url())
        ->toBe('https://www.cve.org/CVERecord?id=CVE-2021-23337');
});

it('resolves PKSA advisory to Packagist security advisories URL', function (): void {
    expect((new AdvisoryIdValueObject('PKSA-qxvb-2bpp-dnk6'))->url())
        ->toBe('https://packagist.org/security-advisories/PKSA-qxvb-2bpp-dnk6');
});

it('returns null for unknown advisory format', function (): void {
    expect((new AdvisoryIdValueObject('UNKNOWN-123'))->url())->toBeNull();
});
