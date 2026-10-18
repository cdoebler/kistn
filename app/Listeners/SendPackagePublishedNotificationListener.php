<?php

namespace App\Listeners;

use App\Events\PackagePublishedEvent;
use App\Notifications\PackagePublishedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendPackagePublishedNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(PackagePublishedEvent $event): void
    {
        $event->project->loadMissing('user');
        $user = $event->project->user;

        if ($user === null) {
            return;
        }

        $user->notify(new PackagePublishedNotification(
            project: $event->project,
            ecosystem: $event->ecosystem,
            package: $event->package,
        ));
    }
}
