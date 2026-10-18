<?php

use App\Enums\LocaleEnum;

it('has en and de cases', function (): void {
    expect(LocaleEnum::English->value)->toBe('en')
        ->and(LocaleEnum::German->value)->toBe('de');
});

it('labels each locale with its native name', function (): void {
    expect(LocaleEnum::English->label())->toBe('English')
        ->and(LocaleEnum::German->label())->toBe('Deutsch');
});

it('returns null from tryFrom for an unsupported locale', function (): void {
    expect(LocaleEnum::tryFrom('fr'))->toBeNull();
});
