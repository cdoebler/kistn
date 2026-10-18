<?php

use App\Models\User;
use App\Notifications\EmptyDigestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;

test('EmptyDigestNotification is sent via mail channel only', function (): void {
    $user = User::factory()->create();

    expect((new EmptyDigestNotification)->via($user))->toBe(['mail']);
});

test('EmptyDigestNotification does not implement ShouldQueue', function (): void {
    expect(new EmptyDigestNotification)->not->toBeInstanceOf(ShouldQueue::class);
});

test('EmptyDigestNotification subject and body communicate no changes', function (): void {
    $user = User::factory()->create();

    $mail = (new EmptyDigestNotification)->toMail($user);
    $body = (string) $mail->render();

    expect($mail->subject)->toContain('Daily digest')
        ->and($body)->toContain('No changes to report today');
});

test('EmptyDigestNotification can be sent to a user', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $user->notify(new EmptyDigestNotification);

    Notification::assertSentTo($user, EmptyDigestNotification::class);
});

test('EmptyDigestNotification mail renders in German when locale is de', function (): void {
    App::setLocale('de');
    $user = User::factory()->create();

    $mail = (new EmptyDigestNotification)->toMail($user);
    $body = (string) $mail->render();

    expect($mail->subject)->toContain('Tägliche Zusammenfassung')
        ->and($body)->toContain('Tägliche Zusammenfassung');
});
