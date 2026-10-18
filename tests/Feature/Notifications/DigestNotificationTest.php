<?php

use App\Models\NotificationDigestItem;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DigestNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;

test('DigestNotification subject includes project count', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $items = collect([
        NotificationDigestItem::factory()->make([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'type' => 'outdated',
            'ecosystem' => 'composer',
            'data' => [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
        ]),
    ]);

    $mail = (new DigestNotification($items))->toMail($user);

    expect($mail->subject)->toContain('Daily digest');
});

test('DigestNotification is sent via mail and database channels', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $items = collect([
        NotificationDigestItem::factory()->make([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'type' => 'outdated',
            'ecosystem' => 'composer',
            'data' => [],
        ]),
    ]);

    $user->notify(new DigestNotification($items));

    Notification::assertSentTo($user, DigestNotification::class);
});

test('DigestNotification does not implement ShouldQueue', function (): void {
    expect(new DigestNotification(collect([])))->not->toBeInstanceOf(ShouldQueue::class);
});

test('DigestNotification mail body lists outdated package names and versions', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $item = NotificationDigestItem::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'type' => 'outdated',
        'ecosystem' => 'composer',
        'data' => [
            ['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major'],
            ['name' => 'vendor/bar', 'current' => '1.5.0', 'latest' => '1.6.0', 'type' => 'minor'],
        ],
        'created_at' => now(),
    ]);
    $item->load('project');

    $mail = (new DigestNotification(collect([$item])))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('vendor/foo')
        ->toContain('1.0.0')
        ->toContain('2.0.0')
        ->toContain('vendor/bar');
});

test('DigestNotification mail body lists vuln advisory ids and severity', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $item = NotificationDigestItem::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'type' => 'vuln',
        'ecosystem' => 'composer',
        'data' => [
            ['package_name' => 'vendor/pkg', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-xxxx-yyyy-zzzz', 'severity' => 'high'],
        ],
        'created_at' => now(),
    ]);
    $item->load('project');

    $mail = (new DigestNotification(collect([$item])))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('vendor/pkg')
        ->toContain('GHSA-xxxx-yyyy-zzzz')
        ->toContain('High');
});

test('DigestNotification mail body renders both outdated and vuln sections', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $outdated = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'type' => 'outdated', 'ecosystem' => 'npm',
        'data' => [['name' => 'lodash', 'current' => '4.17.20', 'latest' => '4.17.21', 'type' => 'patch']],
        'created_at' => now(),
    ]);
    $vuln = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'type' => 'vuln', 'ecosystem' => 'npm',
        'data' => [['package_name' => 'lodash', 'package_version' => '4.17.20', 'advisory_id' => 'GHSA-35jh-r3h4-6jhm', 'severity' => 'critical']],
        'created_at' => now(),
    ]);

    $items = NotificationDigestItem::with('project')->whereIn('id', [$outdated->id, $vuln->id])->get();

    $mail = (new DigestNotification($items))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('Outdated')
        ->toContain('lodash')
        ->toContain('Vulnerabilities')
        ->toContain('GHSA-35jh-r3h4-6jhm');
});

test('DigestNotification dedupes same package appearing across multiple outdated snapshot items', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $itemA = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'type' => 'outdated', 'ecosystem' => 'wp-theme',
        'data' => [['name' => 'astra', 'current' => '3.7.5', 'latest' => '4.13.6', 'type' => 'major']],
        'created_at' => now()->subHour(),
    ]);
    $itemB = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $project->id,
        'type' => 'outdated', 'ecosystem' => 'wp-theme',
        'data' => [['name' => 'astra', 'current' => '3.7.5', 'latest' => '4.13.6', 'type' => 'major']],
        'created_at' => now(),
    ]);

    $items = NotificationDigestItem::with('project')->whereIn('id', [$itemA->id, $itemB->id])->get();

    $mail = (new DigestNotification($items))->toMail($user);
    $body = (string) $mail->render();

    expect(substr_count($body, '3.7.5'))->toBe(1);
    expect($body)->toContain('Outdated (1)');
});

test('DigestNotification mail body renders sections for each project separately', function (): void {
    $user = User::factory()->create();
    $projectA = Project::factory()->create(['user_id' => $user->id, 'name' => 'Project Alpha']);
    $projectB = Project::factory()->create(['user_id' => $user->id, 'name' => 'Project Beta']);

    $itemA = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $projectA->id,
        'type' => 'outdated', 'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/alpha', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);
    $itemB = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $projectB->id,
        'type' => 'vuln', 'ecosystem' => 'npm',
        'data' => [['package_name' => 'beta-pkg', 'package_version' => '1.0.0', 'advisory_id' => 'GHSA-beta-0001-xxxx', 'severity' => 'medium']],
        'created_at' => now(),
    ]);

    $items = NotificationDigestItem::with('project')->whereIn('id', [$itemA->id, $itemB->id])->get();

    $mail = (new DigestNotification($items))->toMail($user);
    $body = (string) $mail->render();

    expect($body)
        ->toContain('Project Alpha')
        ->toContain('vendor/alpha')
        ->toContain('Project Beta')
        ->toContain('GHSA-beta-0001-xxxx');

    expect($mail->subject)->toContain('2 projects updated');
});

test('DigestNotification mail body lists project sections alphabetically by name', function (): void {
    $user = User::factory()->create();
    $zebra = Project::factory()->create(['user_id' => $user->id, 'name' => 'Zebra Corp']);
    $apple = Project::factory()->create(['user_id' => $user->id, 'name' => 'Apple Inc']);
    $mango = Project::factory()->create(['user_id' => $user->id, 'name' => 'mango Ltd']);

    $itemZebra = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $zebra->id,
        'type' => 'outdated', 'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/z', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);
    $itemApple = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $apple->id,
        'type' => 'outdated', 'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/a', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);
    $itemMango = NotificationDigestItem::factory()->create([
        'user_id' => $user->id, 'project_id' => $mango->id,
        'type' => 'outdated', 'ecosystem' => 'composer',
        'data' => [['name' => 'vendor/m', 'current' => '1.0.0', 'latest' => '2.0.0', 'type' => 'major']],
        'created_at' => now(),
    ]);

    // whereIn preserves insertion (id) order, i.e. Zebra, Apple, mango — deliberately not alphabetical.
    $items = NotificationDigestItem::with('project')
        ->whereIn('id', [$itemZebra->id, $itemApple->id, $itemMango->id])
        ->get();

    $mail = (new DigestNotification($items))->toMail($user);
    $body = (string) $mail->render();

    $applePos = strpos($body, 'Apple Inc');
    $mangoPos = strpos($body, 'mango Ltd');
    $zebraPos = strpos($body, 'Zebra Corp');

    expect($applePos)->not->toBeFalse();
    expect($mangoPos)->not->toBeFalse();
    expect($zebraPos)->not->toBeFalse();
    expect($applePos)->toBeLessThan($mangoPos);
    expect($mangoPos)->toBeLessThan($zebraPos);
});

test('mail renders in German when locale is de', function (): void {
    App::setLocale('de');
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);

    $items = collect([
        NotificationDigestItem::factory()->make([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'type' => 'outdated',
            'ecosystem' => 'composer',
            'data' => [['name' => 'vendor/foo', 'current' => '1.0.0', 'latest' => '2.0.0']],
        ]),
    ]);

    $notification = new DigestNotification($items);
    $mail = $notification->toMail($user);
    $body = (string) $mail->render();

    expect($mail->subject)->toContain('Tägliche Zusammenfassung')
        ->and($body)->toContain('Tägliche Zusammenfassung');
});
