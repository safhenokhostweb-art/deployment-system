<?php

namespace App\Services\Releases;

use App\Models\PublishJob;
use App\Models\Release;
use App\Services\Publish\AuthoritativeBuildAgentResolver;
use App\Services\Publish\PublishJobService;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Root-cause fix (release-orchestration bootstrap deadlock): closes the
 * loop ProcessGitHubWebhookPush's inline attempt cannot — a Release left
 * `awaiting_artifact` because its authoritative build Agent was ineligible
 * (most commonly: below min_local_agent_version_for_assigned_artifact,
 * with its exact-commit self-upgrade auto-queued by that same webhook
 * step) has nothing that ever tries again on its own; GitHub never
 * redelivers a push spontaneously, so without this sweep the Release would
 * stay stranded forever even after the Agent successfully upgrades. Same
 * "thin command over a service" shape as StalePublishJobRecoveryService /
 * WebhookDeliveryOwnershipService::reclaimExpiredProcessing().
 *
 * Historical-recovery regression fix: `awaiting_artifact` alone is NOT
 * eligibility — a project accumulates one such row per push that a
 * subsequent commit later superseded, and every one of them still matches
 * that bare status filter forever. Every Release considered here is
 * additionally required to pass ReleaseRecoveryEligibility::isCurrent()
 * (the shared "is this Release still the branch tip" contract) before
 * anything is queued for it, and syncArtifactState() is re-run immediately
 * before queuing in case a matching Artifact has appeared through another
 * path since this Release last synced — both close the exact gap that let
 * an Agent-version blocker's recovery revive an entire historical backlog
 * instead of only the currently relevant Release per project.
 */
final class ReleaseArtifactBuildRetryService
{
    public function __construct(
        private readonly AuthoritativeBuildAgentResolver $buildAgents,
        private readonly PublishJobService $publishJobs,
        private readonly ReleaseOrchestrator $releases,
        private readonly ReleaseRecoveryEligibility $eligibility,
    ) {}

    /** @return array{checked:int, superseded:int, queued:int} */
    public function retry(): array
    {
        $checked = 0;
        $superseded = 0;
        $queued = 0;

        Release::query()
            ->where('status', Release::STATUS_AWAITING_ARTIFACT)
            ->whereHas('project', fn ($query) => $query->where('requires_complete_artifact', true)->where('is_active', true))
            ->whereDoesntHave('publishJobs', fn ($query) => $query
                ->where('action', PublishJob::ACTION_BUILD_ARTIFACT)
                ->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED, PublishJob::STATUS_RUNNING]))
            ->with('project')
            ->orderBy('id')
            ->chunkById(50, function (Collection $releases) use (&$checked, &$superseded, &$queued): void {
                foreach ($releases as $release) {
                    if (! $this->eligibility->isCurrent($release)) {
                        // A later push already superseded this Release for
                        // the same project+branch — never a candidate for
                        // automatic recovery, no matter how long it has
                        // sat awaiting_artifact. Left untouched: it is not
                        // this sweep's place to reclassify or mutate it.
                        $superseded++;

                        continue;
                    }

                    $checked++;

                    // A matching Artifact may have appeared since this
                    // Release last synced (a manual Dashboard build for the
                    // same exact commit, for instance) — re-check before
                    // queuing a redundant one.
                    $release = $this->releases->syncArtifactState($release);
                    if ($release->status !== Release::STATUS_AWAITING_ARTIFACT) {
                        continue;
                    }

                    try {
                        $mapping = $this->buildAgents->resolve($release->project, $release->branch);
                        $this->publishJobs->queueArtifactBuild(
                            $mapping->localAgent,
                            $release->project,
                            $release->commit_hash,
                            $release->branch,
                            'system:release-artifact-retry',
                            $release,
                        );
                        $this->releases->markArtifactBuilding($release);
                        $queued++;
                    } catch (HttpExceptionInterface) {
                        // Still ineligible (Agent not yet upgraded, offline,
                        // unconfigured, ...) — left awaiting_artifact for
                        // the next sweep; no retry-storm risk since this
                        // read is cheap and idempotent.
                    }
                }
            });

        return ['checked' => $checked, 'superseded' => $superseded, 'queued' => $queued];
    }
}
