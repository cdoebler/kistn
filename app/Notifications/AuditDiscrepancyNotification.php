<?php

namespace App\Notifications;

use App\Enums\EcosystemEnum;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuditDiscrepancyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $onlyInServer
     * @param  list<string>  $onlyInClient
     */
    public function __construct(
        public readonly Project $project,
        public readonly EcosystemEnum $ecosystem,
        public readonly array $onlyInServer,
        public readonly array $onlyInClient,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('Audit discrepancy — :project (:ecosystem)', [
                'project' => $this->project->name,
                'ecosystem' => $this->ecosystem->value,
            ]))
            ->view('mail.notifications.audit-discrepancy', [
                'project' => $this->project,
                'ecosystem' => $this->ecosystem,
                'onlyInServer' => $this->onlyInServer,
                'onlyInClient' => $this->onlyInClient,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'project_uuid' => $this->project->uuid,
            'project_name' => $this->project->name,
            'ecosystem' => $this->ecosystem->value,
            'only_in_server' => $this->onlyInServer,
            'only_in_client' => $this->onlyInClient,
        ];
    }
}
