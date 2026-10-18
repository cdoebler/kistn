<?php

use App\Enums\NotificationModeEnum;
use App\Models\NotificationDigestItem;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DigestNotification;
use App\Notifications\EmptyDigestNotification;
use Illuminate\Support\Facades\Notification;

test('SendDigestNotifications sends digest to users past their digest time', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => [
            'notification_mode' => NotificationModeEnum::DailyDigest->value,
            'digest_time' => '00:00',
            'timezone' => 'UTC',
        ],
        'last_digest_sent_at' => null,
    ]);

    $project = Project::factory()->create(['user_id' => $user->id]);

    NotificationDigestItem::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'type' => 'outdated',
        'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
        'created_at' => now(),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, DigestNotification::class);
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBe(0);
    expect($user->fresh()->last_digest_sent_at)->not->toBeNull();
});

test('SendDigestNotifications skips users not yet at their digest time', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => [
            'notification_mode' => NotificationModeEnum::DailyDigest->value,
            'digest_time' => '23:59',
            'timezone' => 'UTC',
        ],
        'last_digest_sent_at' => null,
    ]);

    $project = Project::factory()->create(['user_id' => $user->id]);
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'type' => 'outdated',
        'ecosystem' => 'composer',
        'data' => [],
        'created_at' => now(),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertNothingSent();
});

test('SendDigestNotifications updates last_digest_sent_at even with no pending items', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => [
            'notification_mode' => NotificationModeEnum::DailyDigest->value,
            'digest_time' => '00:00',
            'timezone' => 'UTC',
        ],
        'last_digest_sent_at' => null,
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    expect($user->fresh()->last_digest_sent_at)->not->toBeNull();
    Notification::assertNothingSent();
});

test('SendDigestNotifications processes remaining users when one user throws', function (): void {
    Notification::fake();

    $failingUser = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'not-a-real-timezone'],
        'last_digest_sent_at' => null,
    ]);

    $okUser = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'UTC'],
        'last_digest_sent_at' => null,
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    expect($okUser->fresh()->last_digest_sent_at)->not->toBeNull();
    expect($failingUser->fresh()->last_digest_sent_at)->toBeNull();
});

test('SendDigestNotifications skips users already sent a digest today', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => [
            'notification_mode' => NotificationModeEnum::DailyDigest->value,
            'digest_time' => '00:00',
            'timezone' => 'UTC',
        ],
        'last_digest_sent_at' => today(),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertNothingSent();
});

test('digest notification contains only outdated items for single project', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'UTC'],
        'last_digest_sent_at' => null,
    ]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    NotificationDigestItem::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'type' => 'outdated',
        'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/outdated', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, DigestNotification::class, fn (DigestNotification $n): bool => $n->items->count() === 1 && $n->items->first()->type === 'outdated');
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBe(0);
});

test('digest notification contains only vuln finding items for single project', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'UTC'],
        'last_digest_sent_at' => null,
    ]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    NotificationDigestItem::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'type' => 'vuln',
        'ecosystem' => 'composer',
        'data' => [['package_name' => 'vendor/pkg', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-xxxx-yyyy-zzzz', 'severity' => 'high']],
        'created_at' => now(),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, DigestNotification::class, fn (DigestNotification $n): bool => $n->items->count() === 1 && $n->items->first()->type === 'vuln');
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBe(0);
});

test('digest notification contains both outdated and finding items for single project', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'UTC'],
        'last_digest_sent_at' => null,
    ]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'type' => 'outdated', 'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'type' => 'vuln', 'ecosystem' => 'composer',
        'data' => [['package_name' => 'vendor/pkg', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-xxxx-yyyy-zzzz', 'severity' => 'critical']],
        'created_at' => now(),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, DigestNotification::class, fn (DigestNotification $n): bool => $n->items->count() === 2
        && $n->items->where('type', 'outdated')->count() === 1
        && $n->items->where('type', 'vuln')->count() === 1);
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBe(0);
});

test('digest accumulates items written across multiple days and sends all at once', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'UTC'],
        'last_digest_sent_at' => now()->subDay(),
    ]);
    $project = Project::factory()->create(['user_id' => $user->id]);

    // Item written yesterday afternoon (after yesterday's digest ran)
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'type' => 'outdated', 'ecosystem' => 'npm',
        'data' => [['name' => 'lodash', 'current' => '4.17.20', 'latest' => '4.17.21', 'type' => 'patch']],
        'created_at' => now()->subHours(20),
    ]);

    // Item written this morning (before today's digest)
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'type' => 'vuln', 'ecosystem' => 'npm',
        'data' => [['package_name' => 'lodash', 'package_version' => '4.17.20', 'advisory_id' => 'GHSA-35jh-r3h4-6jhm', 'severity' => 'high']],
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, DigestNotification::class, fn (DigestNotification $n): bool => $n->items->count() === 2);
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBe(0);
});

test('digest groups items from multiple projects into one notification', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'UTC'],
        'last_digest_sent_at' => null,
    ]);
    $projectA = Project::factory()->create(['user_id' => $user->id]);
    $projectB = Project::factory()->create(['user_id' => $user->id]);

    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $projectA->id,
        'type' => 'outdated', 'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $projectB->id,
        'type' => 'outdated', 'ecosystem' => 'npm',
        'data' => [['name' => 'react', 'current' => '17.0.0', 'latest' => '18.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, DigestNotification::class, function (DigestNotification $n) use ($projectA, $projectB): bool {
        $projectIds = $n->items->pluck('project_id')->unique()->sort()->values()->all();

        return $n->items->count() === 2
            && $projectIds === collect([$projectA->id, $projectB->id])->sort()->values()->all();
    });
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBe(0);
});

test('digest covers multiple projects with different content types', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'UTC'],
        'last_digest_sent_at' => null,
    ]);
    $projectA = Project::factory()->create(['user_id' => $user->id]);
    $projectB = Project::factory()->create(['user_id' => $user->id]);

    // Project A: outdated only
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $projectA->id,
        'type' => 'outdated', 'ecosystem' => 'wp-plugin',
        'data' => [['name' => 'woocommerce', 'current' => '7.0.0', 'latest' => '8.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);

    // Project B: vuln only
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $projectB->id,
        'type' => 'vuln', 'ecosystem' => 'composer',
        'data' => [['package_name' => 'symfony/http-kernel', 'package_version' => '5.4.0', 'advisory_id' => 'GHSA-abcd-1234-5678', 'severity' => 'medium']],
        'created_at' => now(),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, DigestNotification::class, function (DigestNotification $n) use ($projectA, $projectB): bool {
        $outdatedForA = $n->items->where('project_id', $projectA->id)->where('type', 'outdated');
        $vulnForB = $n->items->where('project_id', $projectB->id)->where('type', 'vuln');

        return $n->items->count() === 2
            && $outdatedForA->count() === 1
            && $vulnForB->count() === 1;
    });
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBe(0);
});

test('SendDigestNotifications sends empty digest when user opted in and has no items', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => [
            'notification_mode' => NotificationModeEnum::DailyDigest->value,
            'digest_time' => '00:00',
            'timezone' => 'UTC',
            'notify_when_empty' => true,
        ],
        'last_digest_sent_at' => null,
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, EmptyDigestNotification::class);
    expect($user->fresh()->last_digest_sent_at)->not->toBeNull();
});

test('SendDigestNotifications does not send empty digest when user opted out', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => [
            'notification_mode' => NotificationModeEnum::DailyDigest->value,
            'digest_time' => '00:00',
            'timezone' => 'UTC',
            'notify_when_empty' => false,
        ],
        'last_digest_sent_at' => null,
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertNothingSent();
    expect($user->fresh()->last_digest_sent_at)->not->toBeNull();
});

test('digest accumulates items from multiple projects across multiple days', function (): void {
    Notification::fake();

    $user = User::factory()->create([
        'settings' => ['notification_mode' => NotificationModeEnum::DailyDigest->value, 'digest_time' => '00:00', 'timezone' => 'UTC'],
        'last_digest_sent_at' => now()->subDay(),
    ]);
    $projectA = Project::factory()->create(['user_id' => $user->id]);
    $projectB = Project::factory()->create(['user_id' => $user->id]);

    // Yesterday afternoon: project A got outdated packages
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $projectA->id,
        'type' => 'outdated', 'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major']],
        'created_at' => now()->subHours(18),
    ]);

    // This morning: project B got a vuln finding
    NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $projectB->id,
        'type' => 'vuln', 'ecosystem' => 'npm',
        'data' => [['package_name' => 'axios', 'package_version' => '0.21.0', 'advisory_id' => 'GHSA-cxjh-pmin-gh8v', 'severity' => 'high']],
        'created_at' => now()->subHours(3),
    ]);

    $this->artisan('notifications:send-digest')->assertSuccessful();

    Notification::assertSentTo($user, DigestNotification::class, fn (DigestNotification $n): bool => $n->items->count() === 2
        && $n->items->pluck('project_id')->unique()->count() === 2);
    expect(NotificationDigestItem::where('user_id', $user->id)->count())->toBe(0);
});
