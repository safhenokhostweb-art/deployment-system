<?php

namespace App\Jobs;

use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use App\Models\WebhookDelivery;
use App\Services\Publish\AuthoritativeBuildAgentResolver;
use App\Services\Publish\PublishJobService;
use App\Services\Releases\ReleaseGitFactsResolver;
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
        public readonly ?int $webhookDeliveryId = null,
    ) {
    }

    public function handle(
        ReleaseOrchestrator $releases,
        ReleaseGitFactsResolver $gitFacts,
        AuthoritativeBuildAgentResolver $buildAgents,
        PublishJobService $publishJobs,
    ): void
    {
        if ($this->webhookDeliveryId !== null) {
            WebhookDelivery::whereKey($this->webhookDeliveryId)->update([
                'status' => WebhookDelivery::STATUS_PROCESSING,
                'processing_at' => now(),
                'failure_message' => null,
            ]);
        }
        // A branch advance prepares a Release; it never creates a production
        // Deployment. Deployment remains an explicit operator-confirmed act.
        $commitHash = strtolower($this->commitHash);
        $release = $releases->createForCommit(
            $this->project,
            $commitHash,
            $this->branch,
            'webhook',
            ['pusher' => $this->pusher],
        );

        // Root-cause fix (bootstrap deadlock): record the FACTS this push
        // already makes knowable — never evidence, never release_ready —
        // so Deploy Latest's "missing requirements" narrows to genuine
        // test/build evidence instead of everything, for every project,
        // on every push, using infrastructure that's always already
        // deployed by the time any push happens. Best-effort: GitHub being
        // briefly unreachable must never fail push processing itself.
        $facts = $gitFacts->resolve($this->project, $commitHash);
        if ($facts['error'] === null) {
            $releases->recordFactualMetadata(
                $this->project,
                $release,
                $commitHash,
                $this->branch,
                $facts['base_sha'],
                $facts['changed_files'],
                $facts['migration_files'],
            );
        }

        if ($release->status !== Release::STATUS_AWAITING_ARTIFACT || ! $this->project->requires_complete_artifact) {
            $this->markDeliveryProcessed();
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
                $release,
            );
            $releases->markArtifactBuilding($release);
        } catch (HttpExceptionInterface) {
            // No valid build agent is a visible awaiting_artifact state. The
            // dashboard offers remediation; it never falls back to a random agent.
        }

        $this->markDeliveryProcessed();
    }

    public function failed(Throwable $exception): void
    {
        if ($this->webhookDeliveryId !== null) {
            WebhookDelivery::whereKey($this->webhookDeliveryId)->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'failed_at' => now(),
                'failure_message' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
        }
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

    private function markDeliveryProcessed(): void
    {
        if ($this->webhookDeliveryId === null) {
            return;
        }
        WebhookDelivery::whereKey($this->webhookDeliveryId)->update([
            'status' => WebhookDelivery::STATUS_PROCESSED,
            'processed_at' => now(),
            'failed_at' => null,
            'failure_message' => null,
        ]);
    }
}
