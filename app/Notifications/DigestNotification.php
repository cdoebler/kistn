<?php

namespace App\Notifications;

use App\Enums\EcosystemEnum;
use App\Models\NotificationDigestItem;
use App\Models\Project;
use App\ValueObjects\AdvisoryIdValueObject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection as BaseCollection;

class DigestNotification extends Notification
{
    /** @param  Collection<int, NotificationDigestItem>|BaseCollection<int, NotificationDigestItem>  $items */
    public function __construct(
        public readonly Collection|BaseCollection $items,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $projectCount = $this->items->pluck('project_id')->unique()->count();

        return (new MailMessage)
            ->subject((string) __('Daily digest — :summary', [
                'summary' => trans_choice(':count project updated|:count projects updated', $projectCount, ['count' => $projectCount]),
            ]))
            ->view('mail.notifications.digest', [
                'projectSections' => $this->buildProjectSections(),
            ]);
    }

    /** @return list<array{project: Project, ecosystems: list<array{ecosystem: EcosystemEnum, outdated: list<array{name: string, current: string, latest: string, url: string}>, vuln: list<array{package_name: string, package_version: string, advisory_id: string, severity: string, url: string, advisory_url: ?string}>}>}> */
    private function buildProjectSections(): array
    {
        $sections = [];

        foreach ($this->items->groupBy('project_id') as $projectItems) {
            $project = $projectItems->first()?->project;

            if ($project === null) {
                continue;
            }

            $ecosystems = [];

            foreach ($projectItems->groupBy('ecosystem') as $ecosystemValue => $ecosystemItems) {
                $ecosystem = EcosystemEnum::tryFrom((string) $ecosystemValue);

                if ($ecosystem === null) {
                    continue;
                }

                $outdated = [];
                $vuln = [];

                foreach ($ecosystemItems as $item) {
                    $data = is_array($item->data) ? $item->data : [];

                    if ($item->type === 'outdated') {
                        foreach ($data as $pkg) {
                            if (! is_array($pkg)
                                || ! isset($pkg['name'], $pkg['current'], $pkg['latest'])
                                || ! is_string($pkg['name'])
                                || ! is_string($pkg['current'])
                                || ! is_string($pkg['latest'])) {
                                continue;
                            }

                            $outdated[$pkg['name']] = ['name' => $pkg['name'], 'current' => $pkg['current'], 'latest' => $pkg['latest'], 'url' => $ecosystem->registryUrl($pkg['name'])];
                        }
                    } elseif ($item->type === 'vuln') {
                        foreach ($data as $finding) {
                            if (! is_array($finding)
                                || ! isset($finding['package_name'], $finding['advisory_id'], $finding['severity'])
                                || ! is_string($finding['package_name'])
                                || ! is_string($finding['advisory_id'])
                                || ! is_string($finding['severity'])) {
                                continue;
                            }

                            $rawVersion = $finding['package_version'] ?? null;
                            $version = is_string($rawVersion) ? $rawVersion : '';

                            $vuln["{$finding['package_name']}|{$version}|{$finding['advisory_id']}"] = [
                                'package_name' => $finding['package_name'],
                                'package_version' => $version,
                                'advisory_id' => $finding['advisory_id'],
                                'severity' => $finding['severity'],
                                'url' => $ecosystem->registryUrl($finding['package_name']),
                                'advisory_url' => (new AdvisoryIdValueObject($finding['advisory_id']))->url(),
                            ];
                        }
                    }
                }

                $outdated = array_values($outdated);
                $vuln = array_values($vuln);

                if ($outdated === [] && $vuln === []) {
                    continue;
                }

                $ecosystems[] = ['ecosystem' => $ecosystem, 'outdated' => $outdated, 'vuln' => $vuln];
            }

            $sections[] = ['project' => $project, 'ecosystems' => $ecosystems];
        }

        usort($sections, fn (array $a, array $b): int => mb_strtolower((string) $a['project']->name) <=> mb_strtolower((string) $b['project']->name));

        return $sections;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'project_ids' => $this->items->pluck('project_id')->unique()->values()->all(),
            'item_count' => $this->items->count(),
        ];
    }
}
