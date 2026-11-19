<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Deployment\Artifact\DeployedArtifactPruner;
use Illuminate\Console\Command;

/**
 * Operator entry point for DeployedArtifactPruner. Dry run by default:
 * prints every deployed artifact with its decision and the bytes a real
 * run would reclaim. --execute deletes the files of the "prune" rows.
 */
class PruneDeployedArtifacts extends Command
{
    protected $signature = 'artifacts:prune-deployed
                            {--execute : Delete the files of the prune candidates}
                            {--project= : Limit to one project_key}
                            {--keep= : Override DEPLOYMENT_ARTIFACT_KEEP_DEPLOYED}';

    protected $description = 'Keep the newest N deployed artifacts per project (plus live, rollback target and in-flight) and delete the rest';

    public function handle(DeployedArtifactPruner $pruner): int
    {
        $keep = max(1, (int) ($this->option('keep') ?? config('deployment.artifact_keep_deployed_per_project', 3)));
        $execute = (bool) $this->option('execute');

        $projects = Project::query()
            ->when($this->option('project'), fn ($q, $key) => $q->where('project_key', $key))
            ->orderBy('id')
            ->get();

        if ($this->option('project') && $projects->isEmpty()) {
            $this->error("Unknown project_key: {$this->option('project')}");

            return self::FAILURE;
        }

        $this->info(($execute ? 'EXECUTE' : 'DRY RUN') . " — keep newest {$keep} deployed artifact(s) per project");

        $candidates = 0;
        $plannedBytes = 0;
        $reclaimed = 0;
        $pruned = 0;

        foreach ($projects as $project) {
            $plan = $pruner->plan($project, $keep);
            if ($plan === []) {
                continue;
            }

            $rows = [];
            foreach ($plan as $entry) {
                $artifact = $entry['artifact'];
                $rows[] = [
                    $artifact->id,
                    substr((string) $artifact->commit_hash, 0, 10),
                    number_format(((int) $artifact->size_bytes) / 1048576, 1),
                    $entry['last_deployment_id'],
                    $entry['decision'],
                    implode(', ', $entry['reasons']),
                ];

                if ($entry['decision'] !== DeployedArtifactPruner::DECISION_PRUNE) {
                    continue;
                }

                $candidates++;
                $plannedBytes += (int) $artifact->size_bytes;
                if ($execute) {
                    $bytes = $pruner->prune($artifact, 'console:artifacts:prune-deployed');
                    $reclaimed += $bytes;
                    $pruned += $artifact->refresh()->status === \App\Models\Artifact::STATUS_DELETED ? 1 : 0;
                }
            }

            $this->line('');
            $this->line("Project {$project->project_key} (#{$project->id})");
            $this->table(['artifact', 'commit', 'MB', 'last deployment', 'decision', 'protected because'], $rows);
        }

        $this->line('');
        if ($execute) {
            $this->info("Pruned {$pruned} of {$candidates} candidate(s); reclaimed " . number_format($reclaimed / 1048576, 1) . ' MB.');
        } else {
            $this->info("{$candidates} candidate(s), about " . number_format($plannedBytes / 1048576, 1) . ' MB. Nothing was deleted; re-run with --execute.');
        }

        return self::SUCCESS;
    }
}
