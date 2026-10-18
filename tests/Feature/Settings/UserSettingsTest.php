<?php

use App\Enums\DigestContentEnum;
use App\Enums\NotificationModeEnum;
use App\Models\User;

test('notificationMode returns Immediate by default', function (): void {
    $user = User::factory()->create(['settings' => null]);
    expect($user->notificationMode())->toBe(NotificationModeEnum::Immediate);
});

test('notificationMode reads from settings', function (): void {
    $user = User::factory()->create(['settings' => ['notification_mode' => 'daily_digest']]);
    expect($user->notificationMode())->toBe(NotificationModeEnum::DailyDigest);
});

test('digestTime returns 08:00 by default', function (): void {
    $user = User::factory()->create(['settings' => null]);
    expect($user->digestTime())->toBe('08:00');
});

test('timezone returns UTC by default when unset', function (): void {
    $user = User::factory()->create(['settings' => null]);
    expect($user->timezone())->toBe('UTC');
});

test('digestContent returns Delta by default', function (): void {
    $user = User::factory()->create(['settings' => null]);
    expect($user->digestContent())->toBe(DigestContentEnum::Delta);
});

test('notifyWhenEmpty returns false by default', function (): void {
    $user = User::factory()->create(['settings' => null]);
    expect($user->notifyWhenEmpty())->toBeFalse();
});

test('notifyWhenEmpty reads from settings', function (): void {
    $user = User::factory()->create(['settings' => ['notify_when_empty' => true]]);
    expect($user->notifyWhenEmpty())->toBeTrue();
});

test('notifyWhenEmpty casts a truthy non-boolean setting to true', function (): void {
    $user = User::factory()->create(['settings' => ['notify_when_empty' => 1]]);
    expect($user->notifyWhenEmpty())->toBeTrue();
});
