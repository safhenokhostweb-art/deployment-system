<?php

namespace App\Jobs;

use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Publish\AuthoritativeBuildAgentResolver;
use App\Services\Publish\PublishJobService;
use App\Services\Releases\ReleaseOrchestrator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Signature verification and event/branch filtering happen synchronously in
 * GitHubWebhookController (they're cheap and GitHub's UI benefits from an
 * immediate, specific response). Only the actual deployment-creation write
 * is queued, so a transient DB hiccup gets retried instead of silently
 * dropping a push, and the webhook response time doesn't depend on DB load.
 */
class ProcessGitHubWebhookPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public int $timeout = 30;

    public function __construct(
        public readonly Project $project,
        public readonly string $commitHash,
        public readonly string $branch,
        public readonly ?string $pusher,
    ) {
    }

    public function handle(
        ReleaseOrchestrator $releases,
        AuthoritativeBuildAgentResolver $buildAgents,
        PublishJobService $publishJobs,
    ): void
    {
        // A branch advance prepares a Release; it never creates a production
        // Deployment. Deployment remains an explicit operator-confirmed act.
        $release = $releases->createForCommit(
            $this->project,
            strtolower($this->commitHash),
            $this->branch,
            'webhook',
            ['pusher' => $this->pusher],
        );
        if ($release->status !== Release::STATUS_AWAITING_ARTIFACT || ! $this->project->requires_complete_artifact) {
            return;
        }
        $existingBuild = PublishJob::query()
            ->where('project_id', $this->project->id)
            ->where('action', PublishJob::ACTION_BUILD_ARTIFACT)
            ->where('reviewed_head_sha', strtolower($this->commitHash))
            ->whereNotIn('status', [PublishJob::STATUS_FAILED, PublishJob::STATUS_TIMED_OUT, PublishJob::STATUS_ABANDONED])
            ->first();
        if ($existingBuild !== null) {
            $existingBuild->update(['release_id' => $release->id]);
            $releases->markArtifactBuilding($release);
            return;
        }
        try {
            $mapping = $buildAgents->resolve($this->project, $this->branch);
            $job = $publishJobs->queueArtifactBuild(
                $mapping->localAgent,
                $this->project,
                strtolower($this->commitHash),
                $this->branch,
                'system:release-orchestrator',
            );
            $job->update(['release_id' => $release->id]);
            $releases->markArtifactBuilding($release);
        } catch (HttpExceptionInterface) {
            // No valid build agent is a visible awaiting_artifact state. The
            // dashboard offers remediation; it never falls back to a random agent.
        }
    }

    public function failed(Throwable $exception): void
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'webhook.release_preparation_failed',
            subjectType: Project::class,
            subjectId: $this->project->id,
            metadata: [
                'commit_hash' => $this->commitHash,
                'branch' => $this->branch,
                'error' => $exception->getMessage(),
            ],
        );
    }
}
