<?php

use App\Enums\LocaleEnum;
use App\Models\User;
use Illuminate\Contracts\Translation\HasLocalePreference;

it('returns null locale override when settings has no locale key', function (): void {
    $user = User::factory()->make(['settings' => []]);

    expect($user->localeOverride())->toBeNull();
});

it('returns the LocaleEnum for a valid stored locale', function (): void {
    $user = User::factory()->make(['settings' => ['locale' => 'de']]);

    expect($user->localeOverride())->toBe(LocaleEnum::German);
});

it('returns null for a stale/invalid stored locale', function (): void {
    $user = User::factory()->make(['settings' => ['locale' => 'fr']]);

    expect($user->localeOverride())->toBeNull();
});

it('preferredLocale falls back to config app.locale when no override is set', function (): void {
    $user = User::factory()->make(['settings' => []]);

    expect($user->preferredLocale())->toBe(config('app.locale'));
});

it('preferredLocale returns the override value when set', function (): void {
    $user = User::factory()->make(['settings' => ['locale' => 'de']]);

    expect($user->preferredLocale())->toBe('de');
});

it('implements HasLocalePreference', function (): void {
    expect(new User)->toBeInstanceOf(HasLocalePreference::class);
});
