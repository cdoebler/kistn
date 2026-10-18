<?php

use App\Models\User;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;

test('validation messages render in German with a translated attribute name', function (): void {
    App::setLocale('de');

    $validator = Validator::make(['email' => 'not-an-email'], ['email' => ['required', 'email']]);

    expect($validator->errors()->first('email'))->toBe('Das Feld E-Mail-Adresse muss eine gültige E-Mail-Adresse sein.');
});

test('failed login shows German credentials message', function (): void {
    User::factory()->create(['email' => 'user@example.com', 'password' => bcrypt('correct-password')]);

    // SetLocaleMiddleware negotiates locale fresh per request, so App::setLocale() here
    // would just get overwritten — send the header that actually drives negotiation instead.
    $this->withHeaders(['Accept-Language' => 'de'])
        ->post(route('login.store'), ['email' => 'user@example.com', 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email']);

    expect(session('errors')->first('email'))->toBe('Diese Zugangsdaten stimmen nicht mit unseren Unterlagen überein.');
});
