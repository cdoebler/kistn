<?php

namespace App\Console\Commands;

use App\Enums\InventoryActionEnum;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Signature('kistn:detect-churn')]
#[Description('Flag projects whose recent added-package rate suggests catalog probing/crawling rather than genuine dependency growth')]
class DetectPackageChurnCommand extends Command
{
    public function handle(): int
    {
        $threshold = Config::integer('inventory.churn_threshold');
        $since = now()->subDay();

        /** @var Collection<int, object{project_id: int, added_count: int}> $counts */
        $counts = DB::table('inventory_events')
            ->select('project_id', DB::raw('count(*) as added_count'))
            ->where('action', InventoryActionEnum::Added->value)
            ->where('occurred_at', '>', $since)
            ->groupBy('project_id')
            ->having('added_count', '>', $threshold)
            ->get();

        foreach ($counts as $row) {
            $project = Project::find($row->project_id);

            if ($project === null) {
                continue;
            }

            $project->update([
                'churn_flagged_at' => now(),
                'churn_flagged_count' => $project->churn_flagged_count + 1,
            ]);

            Log::warning('Project churn anomaly detected', [
                'project_uuid' => $project->uuid,
                'added_count_24h' => $row->added_count,
                'threshold' => $threshold,
            ]);
        }

        return self::SUCCESS;
    }
}
