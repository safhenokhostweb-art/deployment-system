<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Models\Project;
use App\Models\PublishJob;
use App\Support\Deployment\ArtifactDependencyPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PrepareDependencyPinningRollback extends Command
{
    protected $signature = 'dependency-pinning:prepare-rollback {--run-id=} {--project=customer-site}';
    protected $description = 'Disable/cancel dependency-lock v2 work before agent downgrade and emit a hashed run attestation.';

    public function handle(): int
    {
        $runId = (string) $this->option('run-id');
        $projectKey = (string) $this->option('project');
        if (preg_match('/^[A-Za-z0-9._-]+$/', $runId) !== 1 || $projectKey === '') {
            $this->error('A safe --run-id and non-empty --project are required.');
            return self::INVALID;
        }
        if (ArtifactDependencyPolicy::required($projectKey)) {
            $this->error('Dependency pinning policy is still enabled for the project. Disable it and clear Hub config cache first.');
            return self::FAILURE;
        }

        $path = "dependency-pinning-rollback/{$runId}.json";
        if (Storage::disk('local')->exists($path)) {
            $bytes = Storage::disk('local')->get($path);
            $this->line('ROLLBACK_PREP_ALREADY_DONE run_id=' . $runId . ' sha256=' . hash('sha256', $bytes));
            return self::SUCCESS;
        }

        $project = Project::query()->where('project_key', $projectKey)->first();
        if ($project === null) {
            $this->error('Configured project was not found.');
            return self::FAILURE;
        }

        try {
            $counts = DB::transaction(function () use ($project): array {
                $runningBuilds = PublishJob::query()->where('project_id', $project->id)
                    ->whereIn('action', [PublishJob::ACTION_BUILD_ARTIFACT, PublishJob::ACTION_PUBLISH])
                    ->where('status', PublishJob::STATUS_RUNNING)->lockForUpdate()->count();
                $runningV2Deployments = Deployment::query()->where('project_id', $project->id)
                    ->whereIn('status', Deployment::IN_PROGRESS_STATUSES)
                    ->whereHas('artifact', fn ($q) => $q->whereJsonContains('required_paths', '.deployment/dependency-lock-v2'))
                    ->lockForUpdate()->count();
                if ($runningBuilds !== 0 || $runningV2Deployments !== 0) {
                    throw new \RuntimeException('In-flight dependency-lock v2 work must finish or be explicitly stopped before downgrade.');
                }

                $publishJobs = PublishJob::query()->where('project_id', $project->id)
                    ->whereIn('action', [PublishJob::ACTION_BUILD_ARTIFACT, PublishJob::ACTION_PUBLISH])
                    ->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_AWAITING_APPROVAL, PublishJob::STATUS_APPROVED])
                    ->lockForUpdate()->get();
                foreach ($publishJobs as $job) {
                    $job->update(['status' => PublishJob::STATUS_REJECTED, 'error_message' => 'Cancelled for dependency-pinning agent rollback.', 'finished_at' => now()]);
                }

                $deployments = Deployment::query()->where('project_id', $project->id)
                    ->whereIn('status', [Deployment::STATUS_PENDING_APPROVAL, Deployment::STATUS_QUEUED])
                    ->whereHas('artifact', fn ($q) => $q->whereJsonContains('required_paths', '.deployment/dependency-lock-v2'))
                    ->lockForUpdate()->get();
                foreach ($deployments as $deployment) {
                    DeploymentTask::query()->where('deployment_id', $deployment->id)
                        ->where('status', DeploymentTask::STATUS_QUEUED)
                        ->update(['status' => DeploymentTask::STATUS_FAILED, 'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE]);
                    $deployment->update([
                        'status' => Deployment::STATUS_CANCELLED,
                        'failure_code' => Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE,
                        'failure_message' => 'Cancelled for dependency-pinning agent rollback.',
                        'finished_at' => now(),
                    ]);
                }

                return ['publish_jobs_cancelled' => $publishJobs->count(), 'v2_deployments_cancelled' => $deployments->count()];
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $attestation = json_encode([
            'format' => 'dependency-pinning-rollback-attestation',
            'version' => 1,
            'run_id' => $runId,
            'project_key' => $projectKey,
            'policy_required' => false,
            ...$counts,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
        Storage::disk('local')->put($path, $attestation);
        $this->line('ROLLBACK_PREP_OK run_id=' . $runId . ' sha256=' . hash('sha256', $attestation));
        return self::SUCCESS;
    }
}
