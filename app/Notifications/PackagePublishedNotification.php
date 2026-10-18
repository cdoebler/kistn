<?php

namespace App\Notifications;

use App\Enums\EcosystemEnum;
use App\Models\Package;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PackagePublishedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly EcosystemEnum $ecosystem,
        public readonly Package $package,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('Package now publicly listed — :project', ['project' => $this->project->name]))
            ->view('mail.notifications.package-published', [
                'project' => $this->project,
                'ecosystem' => $this->ecosystem,
                'package' => $this->package,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'project_uuid' => $this->project->uuid,
            'ecosystem' => $this->ecosystem->value,
            'package_name' => $this->package->name,
        ];
    }
}
