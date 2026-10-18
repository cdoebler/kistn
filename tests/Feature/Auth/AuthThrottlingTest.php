<?php

use App\Models\User;

test('login attempts are throttled per email and ip', function (): void {
    config(['fortify.rate_limits.login' => 1]);

    $credentials = ['email' => 'nobody@example.com', 'password' => 'wrong-password'];

    $this->post('/login', $credentials)->assertStatus(302);
    $this->post('/login', $credentials)->assertStatus(429);
});

test('password reset link requests are throttled', function (): void {
    config(['fortify.rate_limits.password_email' => 1]);

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])->assertStatus(302);
    $this->post('/forgot-password', ['email' => $user->email])->assertStatus(429);
});

test('password reset submissions are throttled', function (): void {
    config(['fortify.rate_limits.password_reset' => 1]);

    $user = User::factory()->create();

    $payload = [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'new-password-1234',
        'password_confirmation' => 'new-password-1234',
    ];

    $this->post('/reset-password', $payload);
    $this->post('/reset-password', $payload)->assertStatus(429);
});
