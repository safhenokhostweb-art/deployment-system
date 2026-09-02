<?php

namespace App\Services\Releases;

use App\Models\PublishJob;
use App\Services\Publish\PublishJobService;

/**
 * Root-cause fix (historical-release-recovery regression): the explicit,
 * operator-invoked companion to fixing the sweep itself — a not-yet-started
 * build_artifact PublishJob that the OLD, unbounded
 * ReleaseArtifactBuildRetryService already queued for a since-superseded
 * Release stays in the queue forever unless something invalidates it. Never
 * touches a job the Agent has already claimed (STATUS_RUNNING) or any
 * terminal job (succeeded/failed/etc.) — see
 * PublishJobService::invalidateObsoleteArtifactBuild()'s own guard.
 *
 * Safe to run repeatedly: an already-invalidated job is STATUS_ABANDONED,
 * which the discovery query excludes, so a second run finds nothing left
 * to do for it. Re-running the fixed eligibility contract (rather than
 * hardcoding the incident's own job IDs) also means this stays correct for
 * any future recurrence of the same shape, not just this one.
 */
final class ObsoleteArtifactBuildReconciliationService
{
    public function __construct(
        private readonly ReleaseRecoveryEligibility $eligibility,
        private readonly PublishJobService $publishJobs,
        private readonly ReleaseOrchestrator $releases,
    ) {}

    /** @return array{examined:int, obsolete:int, invalidated:int} */
    public function reconcile(bool $apply): array
    {
        $examined = 0;
        $obsolete = 0;
        $invalidated = 0;

        PublishJob::query()
            ->where('action', PublishJob::ACTION_BUILD_ARTIFACT)
            ->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED])
            ->whereNotNull('release_id')
            ->with(['release.project'])
            ->orderBy('id')
            ->chunkById(50, function ($jobs) use (&$examined, &$obsolete, &$invalidated, $apply): void {
                foreach ($jobs as $job) {
                    $examined++;
                    $release = $job->release;

                    if ($release === null || $this->eligibility->isCurrent($release)) {
                        // No Release to check against (shouldn't happen given
                        // whereNotNull above, but never treat "unknown" as
                        // "obsolete"), or genuinely still the branch tip —
                        // not obsolete, leave it for the Agent to claim.
                        continue;
                    }

                    $obsolete++;

                    if (! $apply) {
                        continue;
                    }

                    $reason = sprintf(
                        'Release #%d (%s@%s, commit %s) was superseded by a later push before its automatically-queued Artifact build was claimed.',
                        $release->id,
                        $release->project->project_key,
                        $release->branch,
                        substr((string) $release->commit_hash, 0, 12),
                    );

                    $this->publishJobs->invalidateObsoleteArtifactBuild($job, $reason);
                    // Reconciles the Release back to whatever is actually
                    // true now that its job is no longer active (typically
                    // awaiting_artifact) — the same primitive every other
                    // build-completion path already uses, not a new rule.
                    $this->releases->syncArtifactState($release->fresh());
                    $invalidated++;
                }
            });

        return ['examined' => $examined, 'obsolete' => $obsolete, 'invalidated' => $invalidated];
    }
}
