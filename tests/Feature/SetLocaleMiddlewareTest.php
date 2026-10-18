<?php

test('negotiates German from Accept-Language header for guests', function (): void {
    $this->withHeaders(['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8'])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('Anmelden');
});

test('negotiates de from Accept-Language header (locale resolution only, no dependency on translated content)', function (): void {
    $this->withHeaders(['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.8'])
        ->get(route('login'));

    expect(app()->getLocale())->toBe('de');
});

test('defaults to English when Accept-Language is missing', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Log in');
});

test('defaults to English for an unsupported Accept-Language', function (): void {
    $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])
        ->get(route('login'))
        ->assertOk()
        ->assertSee('Log in');
});

use App\Models\User;

test('authenticated user without override negotiates from Accept-Language like a guest', function (): void {
    $user = User::factory()->create(['settings' => []]);

    $this->actingAs($user)
        ->withHeaders(['Accept-Language' => 'de'])
        ->get(route('projects.index'))
        ->assertOk();

    expect(app()->getLocale())->toBe('de');
});

test('authenticated user override wins over Accept-Language header', function (): void {
    $user = User::factory()->create(['settings' => ['locale' => 'de']]);

    $this->actingAs($user)
        ->withHeaders(['Accept-Language' => 'en'])
        ->get(route('projects.index'))
        ->assertOk();

    expect(app()->getLocale())->toBe('de');
});
