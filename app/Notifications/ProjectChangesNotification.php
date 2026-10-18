<?php

namespace App\Notifications;

use App\Enums\EcosystemEnum;
use App\Models\Project;
use App\ValueObjects\AdvisoryIdValueObject;
use App\ValueObjects\OutdatedSummaryValueObject;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectChangesNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Project $project,
        public readonly ProjectNotificationPayloadValueObject $payload,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) __('Updates — :project', ['project' => $this->project->name]))
            ->view('mail.notifications.project-changes', [
                'project' => $this->project,
                'outdatedSections' => $this->buildOutdatedSections(),
                'findingsSections' => $this->buildFindingsSections(),
            ]);
    }

    /** @return list<array{ecosystem: EcosystemEnum, groups: list<array{label: string, packages: list<array{name: string, current: string, latest: string, url: string}>}>}> */
    private function buildOutdatedSections(): array
    {
        $sections = [];

        foreach ($this->payload->outdatedByEcosystem as $ecosystemValue => $summary) {
            if ($summary->isEmpty()) {
                continue;
            }

            $ecosystem = EcosystemEnum::from($ecosystemValue);

            /** @var array<string, list<array{name: string, current: string, latest: string}>> $summaries */
            $summaries = [
                'major' => $summary->major,
                'minor' => $summary->minor,
                'patch' => $summary->patch,
                'other' => $summary->other,
            ];

            $labels = [
                'major' => (string) __('Major updates'),
                'minor' => (string) __('Minor updates'),
                'patch' => (string) __('Patch updates'),
                'other' => (string) __('Other changes'),
            ];

            $groups = [];

            foreach ($summaries as $key => $packages) {
                if ($packages === []) {
                    continue;
                }

                $groups[] = [
                    'label' => $labels[$key],
                    'packages' => array_map(
                        fn (array $pkg): array => [...$pkg, 'url' => $ecosystem->registryUrl($pkg['name'])],
                        $packages,
                    ),
                ];
            }

            $sections[] = ['ecosystem' => $ecosystem, 'groups' => $groups];
        }

        return $sections;
    }

    /** @return list<array{ecosystem: EcosystemEnum, findings: list<array{package_name: string, package_version: string, severity: string, pkg_url: string, advisory_id: string, advisory_url: ?string}>}> */
    private function buildFindingsSections(): array
    {
        $sections = [];

        foreach ($this->payload->findingsByEcosystem as $ecosystemValue => $findings) {
            if ($findings === []) {
                continue;
            }

            $ecosystem = EcosystemEnum::from($ecosystemValue);

            $sections[] = [
                'ecosystem' => $ecosystem,
                'findings' => array_map(
                    fn (array $finding): array => [
                        'package_name' => $finding['package_name'],
                        'package_version' => $finding['package_version'],
                        'severity' => $finding['severity'],
                        'pkg_url' => $ecosystem->registryUrl($finding['package_name']),
                        'advisory_id' => $finding['advisory_id'],
                        'advisory_url' => (new AdvisoryIdValueObject($finding['advisory_id']))->url(),
                    ],
                    $findings,
                ),
            ];
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'project_uuid' => $this->project->uuid,
            'outdated_by_ecosystem' => array_map(
                fn (OutdatedSummaryValueObject $s): array => [
                    'major' => $s->major,
                    'minor' => $s->minor,
                    'patch' => $s->patch,
                    'other' => $s->other,
                ],
                $this->payload->outdatedByEcosystem,
            ),
            'findings_by_ecosystem' => $this->payload->findingsByEcosystem,
        ];
    }
}
