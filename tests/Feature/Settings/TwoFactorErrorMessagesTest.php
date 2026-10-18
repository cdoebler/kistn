<?php

use App\Models\User;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

test('recovery codes load failure message is translatable', function (): void {
    App::setLocale('de');
    $user = User::factory()->create();
    $this->actingAs($user);

    // Force the decrypt() failure path by storing an un-decryptable value.
    // two_factor_secret must also be non-null: hasEnabledTwoFactorAuthentication()
    // requires it alongside two_factor_confirmed_at when Fortify's "confirm" feature is enabled.
    $user->forceFill([
        'two_factor_secret' => 'not-encrypted-garbage',
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => 'not-encrypted-garbage',
    ])->save();

    $component = Livewire::test('pages::settings.two-factor.recovery-codes');

    $component->assertHasErrors(['recoveryCodes']);
    // errors() takes no arguments (Livewire\Features\SupportValidation\TestsValidation::errors());
    // the key belongs on MessageBag::first(), not here — passing it to errors() was silently
    // ignored, so this previously matched the first error of ANY field, not specifically this one.
    expect($component->errors()->first('recoveryCodes'))->toBe('Wiederherstellungscodes konnten nicht geladen werden');
});
