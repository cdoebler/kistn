<?php

namespace App\Services;

use App\Models\NotificationDigestItem;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Notifications\ProjectChangesNotification;
use App\ValueObjects\ProjectNotificationPayloadValueObject;
use Illuminate\Support\Facades\Log;

class NotificationDispatcherService
{
    public function dispatch(Project $project, ProjectNotificationPayloadValueObject $payload): void
    {
        if ($payload->isEmpty()) {
            Log::debug('NotificationDispatcher: payload is empty, skipping', [
                'project_uuid' => $project->uuid,
            ]);

            return;
        }

        $project->loadMissing('user'); // @pest-mutate-ignore defensive; Model::automaticallyEagerLoadRelationships() transparently loads it anyway if skipped
        $user = $project->user;

        if ($user === null) {
            Log::warning('NotificationDispatcher: project has no user', [
                'project_uuid' => $project->uuid,
            ]);

            return;
        }

        $sendImmediately = $project->notifyImmediately() || $user->notificationMode()->value === 'immediate';

        if ($sendImmediately) {
            Log::debug('NotificationDispatcher: sending immediate notification', [
                'project_uuid' => $project->uuid,
                'user_id' => $user->id,
            ]);

            $user->notify(new ProjectChangesNotification($project, $payload));
            $project->update(['last_notified_at' => now()]);
        } else {
            $this->writeDigestItems($project, $user->id, $payload);
        }

        $this->markOutdatedAsNotified($project, $payload);
    }

    /**
     * Records the latest_version each outdated package was reported with, so delta mode
     * only reports it again once a newer latest_version shows up. Written without
     * touching project_packages.updated_at — this is notification bookkeeping, not an
     * installation change.
     */
    private function markOutdatedAsNotified(Project $project, ProjectNotificationPayloadValueObject $payload): void
    {
        foreach ($payload->outdatedByEcosystem as $ecosystem => $summary) {
            $entries = [...$summary->major, ...$summary->minor, ...$summary->patch, ...$summary->other];

            foreach ($entries as $entry) {
                // ponytail: one UPDATE per outdated package; batch by latest version if push volume makes this hot
                ProjectPackage::withoutTimestamps(fn (): int => ProjectPackage::query()
                    ->where('project_id', $project->id)
                    ->whereHas('package', fn ($q) => $q->where('ecosystem', $ecosystem)->where('name', $entry['name']))
                    ->update(['notified_latest_version' => $entry['latest']]));
            }

            Log::debug('NotificationDispatcher: marked outdated packages as notified', [ // @pest-mutate-ignore logging not asserted in this test suite
                'project_uuid' => $project->uuid,
                'ecosystem' => $ecosystem,
                'packages' => array_column($entries, 'latest', 'name'),
            ]);
        }
    }

    private function writeDigestItems(Project $project, int $userId, ProjectNotificationPayloadValueObject $payload): void
    {
        $now = now();

        foreach ($payload->outdatedByEcosystem as $ecosystem => $summary) {
            if ($summary->isEmpty()) {
                continue;
            }

            $rows = array_merge(
                array_map(fn (array $pkg): array => array_merge($pkg, ['type' => 'major']), $summary->major),
                array_map(fn (array $pkg): array => array_merge($pkg, ['type' => 'minor']), $summary->minor),
                array_map(fn (array $pkg): array => array_merge($pkg, ['type' => 'patch']), $summary->patch),
                array_map(fn (array $pkg): array => array_merge($pkg, ['type' => 'other']), $summary->other),
            );

            NotificationDigestItem::create([
                'user_id' => $userId,
                'project_id' => $project->id,
                'type' => 'outdated',
                'ecosystem' => $ecosystem,
                'data' => $rows,
                'created_at' => $now,
            ]);
        }

        foreach ($payload->findingsByEcosystem as $ecosystem => $findings) {
            if ($findings === []) {
                continue;
            }

            NotificationDigestItem::create([
                'user_id' => $userId,
                'project_id' => $project->id,
                'type' => 'vuln',
                'ecosystem' => $ecosystem,
                'data' => $findings,
                'created_at' => $now,
            ]);
        }

        $project->update(['last_notified_at' => $now]);

        Log::debug('NotificationDispatcher: wrote digest items', [
            'project_uuid' => $project->uuid,
            'user_id' => $userId,
            'ecosystem_count' => count($payload->outdatedByEcosystem) + count($payload->findingsByEcosystem),
        ]);
    }
}
