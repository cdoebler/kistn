<?php

namespace App\Console\Commands;

use App\Enums\EcosystemEnum;
use App\Models\Project;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('kistn:audit
                            {project? : UUID of the project to audit}
                            {--all : Audit all eligible projects}
                            {--force : Bypass audit_local_* flag and staleness check; still requires a stored lock file}')]
#[Description('Dispatch composer and npm audit/outdated jobs for projects with stored lock files')]
class AuditCommand extends Command
{
    public function handle(): int
    {
        $arg = $this->argument('project');
        $uuid = is_string($arg) ? $arg : null;
        $all = $this->option('all');
        $force = (bool) $this->option('force');

        if ($uuid === null && ! $all) {
            $this->error('Provide a project UUID or pass --all.');

            return self::FAILURE;
        }

        if ($uuid !== null) {
            $project = Project::where('uuid', $uuid)->first();

            if ($project === null) {
                $this->error("Project not found: {$uuid}");

                return self::FAILURE;
            }

            $this->auditProject($project, $force);
        } else {
            Project::query()->each(fn (Project $p) => $this->auditProject($p, $force));
        }

        return self::SUCCESS;
    }

    private function auditProject(Project $project, bool $force): void
    {
        $dispatched = false;

        foreach (EcosystemEnum::cases() as $ecosystem) {
            $jobClass = $ecosystem->auditJobClass();

            if ($jobClass !== null && $jobClass::shouldDispatch($project, $force)) {
                dispatch(new $jobClass($project));
                $dispatched = true;
            }
        }

        if ($dispatched) {
            Log::debug('AuditCommand: dispatched', [
                'project_uuid' => $project->uuid,
                'force' => $force,
            ]);
        }
    }
}
