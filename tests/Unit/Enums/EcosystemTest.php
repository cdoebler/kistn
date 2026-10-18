<?php

use App\Enums\EcosystemEnum;

it('returns packagist URL for composer packages', function (): void {
    expect(EcosystemEnum::Composer->registryUrl('vendor/package'))
        ->toBe('https://packagist.org/packages/vendor/package');
});

it('returns npm URL for npm packages', function (): void {
    expect(EcosystemEnum::Npm->registryUrl('lodash'))
        ->toBe('https://www.npmjs.com/package/lodash');
});

it('returns wordpress plugin URL', function (): void {
    expect(EcosystemEnum::WpPlugin->registryUrl('akismet'))
        ->toBe('https://wordpress.org/plugins/akismet');
});

it('returns wordpress theme URL', function (): void {
    expect(EcosystemEnum::WpTheme->registryUrl('twentytwentyfour'))
        ->toBe('https://wordpress.org/themes/twentytwentyfour');
});
