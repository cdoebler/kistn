<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PackagePublishedNotification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;

test('a notification sent to a user with a German locale override is recorded with the German locale, with no app locale set beforehand', function (): void {
    Notification::fake();

    App::setLocale('en'); // simulate "no request context has run" — the default app locale
    $user = User::factory()->create(['settings' => ['locale' => 'de']]);
    $project = Project::factory()->create(['user_id' => $user->id]);
    $package = Package::factory()->create();

    $notification = new PackagePublishedNotification($project, EcosystemEnum::WpPlugin, $package);

    $user->notify($notification);

    // Illuminate\Notifications\Notification::$locale is a manual override only — nothing in the
    // real send path (NotificationSender::sendNow(), which wraps the send in App::setLocale())
    // or the fake path (NotificationFake::sendNow()) ever writes $notification->locale from
    // HasLocalePreference. What both DO compute per-send is:
    //   $notification->locale ?? ChannelManager-wide override ?? $notifiable->preferredLocale()
    // NotificationFake records that computed value as the 4th element of the tracked send
    // (see NotificationFake::sendNow()'s stored array: notification, channels, notifiable, locale),
    // and assertSentTo()'s callback receives it as the 4th positional argument. Asserting on it
    // is what actually proves HasLocalePreference drove the outcome — this would fail if User
    // stopped implementing HasLocalePreference, unlike inspecting $notification->locale (always
    // null) or the app locale (never mutated by a faked send).
    Notification::assertSentTo(
        $user,
        PackagePublishedNotification::class,
        fn (PackagePublishedNotification $n, array $channels, User $notifiable, ?string $locale): bool => $locale === 'de'
    );

    expect(App::getLocale())->toBe('en'); // app locale itself is untouched by a faked send
});

test('mail rendered via toMail with app locale explicitly set to de matches the user override', function (): void {
    $user = User::factory()->create(['settings' => ['locale' => 'de']]);
    App::setLocale($user->preferredLocale());

    $project = Project::factory()->create(['user_id' => $user->id]);
    $package = Package::factory()->create();

    $mail = (new PackagePublishedNotification($project, EcosystemEnum::WpPlugin, $package))->toMail($user);
    $body = (string) $mail->render();

    expect($body)->toContain('Paket jetzt öffentlich gelistet');
});
