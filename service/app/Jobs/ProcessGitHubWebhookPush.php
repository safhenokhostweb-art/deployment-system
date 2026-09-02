<?php

namespace App\Jobs;

use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\Release;
use App\Services\Publish\AuthoritativeBuildAgentResolver;
use App\Services\Publish\LocalAgentControlJobService;
use App\Services\Publish\PublishJobService;
use App\Services\Releases\ReleaseGitFactsResolver;
use App\Services\Releases\ReleaseOrchestrator;
use App\Services\Webhooks\WebhookDeliveryOwnershipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ProcessGitHubWebhookPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels {
        SerializesModels::__unserialize as private restoreSerializedModels;
    }

    public int $tries = 3;
    public array $backoff = [30, 120, 300];
    public int $timeout = 30;

    public function __construct(
        public readonly Project $project,
        public readonly string $commitHash,
        public readonly string $branch,
        public readonly ?string $pusher,
        public readonly ?int $webhookDeliveryId = null,
        public readonly ?string $webhookDispatchToken = null,
    ) {
    }

    /**
     * A job serialized before dispatch-token ownership existed never had
     * this property in its payload at all -- SerializesModels::__unserialize()
     * only restores keys actually present in the payload, so without this
     * override a pre-hardening queued job would come back with
     * webhookDispatchToken permanently uninitialized: a typed readonly
     * property with no constructor ever having run on the unserialized
     * object. The very first read (handle()'s claimProcessing() call, or
     * failed()) would then throw "must not be accessed before
     * initialization" instead of running -- silently losing that webhook's
     * processing rather than completing it.
     */
    public function __unserialize(array $values): void
    {
        $this->restoreSerializedModels($values);

        if (! isset($this->webhookDispatchToken)) {
            $this->webhookDispatchToken = null;
        }
    }

    public function handle(
        ReleaseOrchestrator $releases,
        ReleaseGitFactsResolver $gitFacts,
        AuthoritativeBuildAgentResolver $buildAgents,
        PublishJobService $publishJobs,
        ?WebhookDeliveryOwnershipService $deliveries = null,
        ?LocalAgentControlJobService $controlJobs = null,
    ): void {
        $controlJobs ??= app(LocalAgentControlJobService::class);
        $deliveries ??= app(WebhookDeliveryOwnershipService::class);
        $ownedToken = null;
        if ($this->webhookDeliveryId !== null) {
            $ownedToken = $deliveries->claimProcessing($this->webhookDeliveryId, $this->webhookDispatchToken);
            if ($ownedToken === null) {
                return;
            }
        }

        try {
            $commitHash = strtolower($this->commitHash);
            $release = $releases->createForCommit(
                $this->project,
                $commitHash,
                $this->branch,
                'webhook',
                ['pusher' => $this->pusher],
            );

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

            if ($release->status === Release::STATUS_AWAITING_ARTIFACT && $this->project->requires_complete_artifact) {
                try {
                    $mapping = $buildAgents->resolve($this->project, $this->branch);
                    $publishJobs->queueArtifactBuild(
                        $mapping->localAgent,
                        $this->project,
                        $commitHash,
                        $this->branch,
                        'system:release-orchestrator',
                        $release,
                    );
                    $releases->markArtifactBuilding($release);
                } catch (HttpExceptionInterface $blocked) {
                    // No authoritative build Agent leaves the Release visibly
                    // awaiting remediation; it never falls back to another Agent.
                    // Root-cause fix (release-orchestration bootstrap deadlock):
                    // when version is the ONLY thing standing between this
                    // Agent and eligibility, don't just wait on a human to
                    // notice — queue the exact-commit self-upgrade that
                    // clears it. releases:retry-awaiting-artifact then
                    // re-attempts this same build once the Agent's heartbeat
                    // verifies the upgrade, so this Release recovers without
                    // needing a brand-new push. Never persisted as a Release
                    // failure: this is an ordinary, self-recoverable
                    // ordering condition, not a build defect.
                    $blockedAgent = $buildAgents->agentPendingVersionUpgradeOnly($this->project);
                    $upgradeJobId = null;
                    if ($blockedAgent !== null) {
                        try {
                            $upgradeJobId = $controlJobs->queueUpgrade($blockedAgent, 'system:release-orchestrator')->id;
                        } catch (HttpExceptionInterface) {
                            // Already upgrading, not Linux, predates remote
                            // upgrade support, or below the exact-commit
                            // upgrade protocol minimum — nothing more this
                            // orchestration step can do automatically.
                        }
                    }

                    AuditLogEntry::record(
                        actorType: AuditLogEntry::ACTOR_SYSTEM,
                        actorIdentifier: null,
                        action: 'release.artifact_build_blocked_by_authoritative_agent',
                        subjectType: Release::class,
                        subjectId: $release->id,
                        metadata: array_filter([
                            'project_id' => $this->project->id,
                            'commit_hash' => $commitHash,
                            'branch' => $this->branch,
                            'reason' => $blocked->getMessage(),
                            'auto_queued_agent_upgrade_job_id' => $upgradeJobId,
                        ], static fn ($value): bool => $value !== null),
                    );
                }
            }

            if ($this->webhookDeliveryId !== null && $ownedToken !== null) {
                $deliveries->markProcessed($this->webhookDeliveryId, $ownedToken);
            }
        } catch (Throwable $e) {
            if ($this->webhookDeliveryId !== null && $ownedToken !== null) {
                if ($this->webhookDispatchToken === null) {
                    $deliveries->markFailed($this->webhookDeliveryId, $ownedToken, $e->getMessage());
                } else {
                    $deliveries->releaseForQueueRetry($this->webhookDeliveryId, $ownedToken);
                }
            }
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        if ($this->webhookDeliveryId !== null && $this->webhookDispatchToken !== null) {
            app(WebhookDeliveryOwnershipService::class)->markFailed(
                $this->webhookDeliveryId,
                $this->webhookDispatchToken,
                $exception->getMessage(),
            );
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
}
