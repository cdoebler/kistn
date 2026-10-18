<?php

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PackagePublishedNotification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Notification;

test('PackagePublishedNotification is sent via mail and database channels', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $package = Package::factory()->create(['project_id' => 0, 'ecosystem' => 'wp-plugin', 'name' => 'my-plugin']);

    $user->notify(new PackagePublishedNotification($project, EcosystemEnum::WpPlugin, $package));

    Notification::assertSentTo($user, PackagePublishedNotification::class, fn (PackagePublishedNotification $n): bool => $n->project->id === $project->id);
});

test('PackagePublishedNotification mail subject includes project name', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'My WP Site']);
    $package = Package::factory()->create(['project_id' => 0, 'ecosystem' => 'wp-plugin', 'name' => 'my-plugin']);

    $notification = new PackagePublishedNotification($project, EcosystemEnum::WpPlugin, $package);
    $mail = $notification->toMail($user);

    expect($mail->subject)->toContain('My WP Site');
});

test('PackagePublishedNotification mail body includes package name and ecosystem', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $package = Package::factory()->create(['project_id' => 0, 'ecosystem' => 'wp-plugin', 'name' => 'my-plugin']);

    $notification = new PackagePublishedNotification($project, EcosystemEnum::WpPlugin, $package);
    $mail = $notification->toMail($user);
    $html = (string) $mail->render();

    expect($html)->toContain('my-plugin')
        ->and($html)->toContain('wp-plugin');
});

test('PackagePublishedNotification toArray contains expected keys', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $package = Package::factory()->create(['project_id' => 0, 'ecosystem' => 'wp-plugin', 'name' => 'my-plugin']);

    $notification = new PackagePublishedNotification($project, EcosystemEnum::WpPlugin, $package);
    $data = $notification->toArray($user);

    expect($data)
        ->toHaveKey('project_uuid', $project->uuid)
        ->toHaveKey('ecosystem', 'wp-plugin')
        ->toHaveKey('package_name', 'my-plugin');
});

test('mail renders in German when locale is de', function (): void {
    App::setLocale('de');
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id, 'name' => 'My Project']);
    $package = Package::factory()->create(['name' => 'acme/widget']);

    $notification = new PackagePublishedNotification($project, EcosystemEnum::Composer, $package);
    $mail = $notification->toMail($user);
    $body = (string) $mail->render();

    expect($mail->subject)->toBe('Paket jetzt öffentlich gelistet — My Project')
        ->and($body)->toContain('Paket jetzt öffentlich gelistet')
        ->and($body)->toContain('war zuvor als privat markiert');
});
