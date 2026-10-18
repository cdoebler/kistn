<?php

use App\Enums\EcosystemEnum;
use App\Events\PackagePublishedEvent;
use App\Listeners\SendPackagePublishedNotificationListener;
use App\Models\Package;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PackagePublishedNotification;
use Illuminate\Support\Facades\Notification;

test('listener dispatches PackagePublishedNotification to project user when event fires', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $package = Package::factory()->create(['project_id' => 0, 'ecosystem' => 'wp-plugin', 'name' => 'my-plugin']);
    $ecosystem = EcosystemEnum::WpPlugin;

    $event = new PackagePublishedEvent($project, $ecosystem, $package);
    $listener = new SendPackagePublishedNotificationListener;
    $listener->handle($event);

    Notification::assertSentTo(
        $user,
        PackagePublishedNotification::class,
        fn (PackagePublishedNotification $n): bool => $n->project->id === $project->id
            && $n->ecosystem === $ecosystem
            && $n->package->id === $package->id,
    );
});

test('listener does nothing when project has no user', function (): void {
    Notification::fake();

    $project = Project::factory()->create();
    $package = Package::factory()->create(['project_id' => 0, 'ecosystem' => 'wp-plugin', 'name' => 'my-plugin']);

    // Simulate a project whose user relation resolves to null
    $project->setRelation('user', null);

    $event = new PackagePublishedEvent($project, EcosystemEnum::WpPlugin, $package);
    $listener = new SendPackagePublishedNotificationListener;
    $listener->handle($event);

    Notification::assertNothingSent();
});
