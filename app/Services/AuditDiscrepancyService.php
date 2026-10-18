<?php

namespace App\Services;

use App\Enums\EcosystemEnum;
use App\Enums\VulnSourceEnum;
use App\Models\Project;
use App\Notifications\AuditDiscrepancyNotification;

class AuditDiscrepancyService
{
    public function check(Project $project, EcosystemEnum $ecosystem, VulnSourceEnum $serverSource): void
    {
        /** @var list<string> $clientAdvisories */
        $clientAdvisories = $project->vulnFindings()
            ->whereHas('package', fn ($q) => $q->where('ecosystem', $ecosystem->value))
            ->where('source', VulnSourceEnum::ClientAudit->value)
            ->pluck('advisory_id')
            ->all();

        if ($clientAdvisories === []) {
            return;
        }

        /** @var list<string> $serverAdvisories */
        $serverAdvisories = $project->vulnFindings()
            ->whereHas('package', fn ($q) => $q->where('ecosystem', $ecosystem->value))
            ->where('source', $serverSource->value)
            ->pluck('advisory_id')
            ->all();

        /** @var list<string> $onlyInServer */
        $onlyInServer = array_values(array_diff($serverAdvisories, $clientAdvisories));
        /** @var list<string> $onlyInClient */
        $onlyInClient = array_values(array_diff($clientAdvisories, $serverAdvisories));

        if ($onlyInServer !== [] || $onlyInClient !== []) {
            $project->loadMissing('user'); // @pest-mutate-ignore defensive; Model::automaticallyEagerLoadRelationships() transparently loads it anyway if skipped
            $user = $project->user;

            if ($user !== null) {
                $user->notify(new AuditDiscrepancyNotification(
                    project: $project,
                    ecosystem: $ecosystem,
                    onlyInServer: $onlyInServer,
                    onlyInClient: $onlyInClient,
                ));
            }
        }
    }
}
