<?php

use App\Enums\DigestContentEnum;
use App\Enums\NotificationModeEnum;
use App\Models\User;
use Livewire\Livewire;

test('notifications settings page renders for authenticated user', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('notifications.edit'))->assertOk();
});

test('notifications settings page redirects guests', function (): void {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});

test('updateNotificationSettings saves notification mode', function (): void {
    $user = User::factory()->create(['settings' => ['notification_mode' => 'immediate']]);
    $this->actingAs($user);

    Livewire::test('pages::settings.notifications')
        ->set('notificationMode', NotificationModeEnum::DailyDigest->value)
        ->set('digestTime', '09:00')
        ->set('digestContent', DigestContentEnum::FullState->value)
        ->call('updateNotificationSettings');

    $user->refresh();
    expect($user->notificationMode())->toBe(NotificationModeEnum::DailyDigest)
        ->and($user->digestTime())->toBe('09:00')
        ->and($user->digestContent())->toBe(DigestContentEnum::FullState);
});

test('updateNotificationSettings validates notification mode', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.notifications')
        ->set('notificationMode', 'invalid_mode')
        ->call('updateNotificationSettings')
        ->assertHasErrors(['notificationMode']);
});

test('updateNotificationSettings saves notify when empty toggle', function (): void {
    $user = User::factory()->create(['settings' => [
        'notification_mode' => NotificationModeEnum::DailyDigest->value,
        'digest_time' => '08:00',
        'digest_content' => DigestContentEnum::Delta->value,
        'notify_when_empty' => false,
    ]]);
    $this->actingAs($user);

    Livewire::test('pages::settings.notifications')
        ->set('notificationMode', NotificationModeEnum::DailyDigest->value)
        ->set('digestTime', '08:00')
        ->set('digestContent', DigestContentEnum::Delta->value)
        ->set('notifyWhenEmpty', true)
        ->call('updateNotificationSettings');

    expect($user->fresh()->notifyWhenEmpty())->toBeTrue();
});
