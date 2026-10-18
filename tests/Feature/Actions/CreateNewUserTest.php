<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('creates user with valid input', function (): void {
    $action = new CreateNewUser;

    $user = $action->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->email)->toBe('jane@example.com');

    $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
});

test('throws ValidationException when name is missing', function (): void {
    $action = new CreateNewUser;

    expect(fn () => $action->create([
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(ValidationException::class);
});

test('throws ValidationException when email is missing', function (): void {
    $action = new CreateNewUser;

    expect(fn () => $action->create([
        'name' => 'Jane Doe',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(ValidationException::class);
});

test('throws ValidationException when email is invalid', function (): void {
    $action = new CreateNewUser;

    expect(fn () => $action->create([
        'name' => 'Jane Doe',
        'email' => 'not-an-email',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(ValidationException::class);
});

test('throws ValidationException when email is already taken', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    $action = new CreateNewUser;

    expect(fn () => $action->create([
        'name' => 'Jane Doe',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(ValidationException::class);
});

test('throws ValidationException when password is missing', function (): void {
    $action = new CreateNewUser;

    expect(fn () => $action->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]))->toThrow(ValidationException::class);
});
