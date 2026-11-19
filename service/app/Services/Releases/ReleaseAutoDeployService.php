<?php

namespace App\Services\Releases;

use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\Release;
use App\Services\Deployment\DeploymentDispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owner opt-in automatic deployment of a Release the moment it becomes
 * READY (see DeploymentDispatcher::createAutomaticFromReadyRelease() for
 * the root cause and the gates).
 *
 * ReleaseOrchestrator::syncArtifactState() calls afterReleaseReady() after
 * its transaction commits, and only on a not-READY -> READY transition, so
 * Releases that were already READY before this shipped are never deployed
 * retroactively. It runs synchronously in the request that made the Release
 * READY (artifact upload or build result report); it does not depend on the
 * Hub queue worker.
 *
 * Never throws: the artifact upload / build report that made the Release
 * READY has already succeeded and must not be turned into an error. A skip
 * or a failure is written to the audit log against the Release so the
 * dashboard shows why no Deployment was created; the operator path
 * (Deploy Latest) remains available either way.
 */
final class ReleaseAutoDeployService
{
    public const SKIP_NOT_READY = 'release_not_ready';
    public const SKIP_PROJECT_INACTIVE = 'project_inactive';
    public const SKIP_SUPERSEDED = 'release_superseded';
    public const SKIP_ALREADY_DEPLOYED = 'commit_already_deployed';
    public const SKIP_DEPLOYMENT_EXISTS = 'deployment_already_exists';

    public function __construct(private readonly ReleaseRecoveryEligibility $eligibility)
    {
    }

    public function afterReleaseReady(int $releaseId): ?Deployment
    {
        $release = Release::query()->with('project')->find($releaseId);
        if ($release === null || $release->project === null || ! $release->project->auto_deploy) {
            // auto_deploy off is the normal, silent case: explicit confirm.
            return null;
        }

        $skip = $this->skipReason($release);
        if ($skip !== null) {
            $this->audit($release, 'release.auto_deploy_skipped', ['reason' => $skip]);

            return null;
        }

        try {
            // Resolved lazily: DeploymentDispatcher's own dependency graph
            // reaches services that depend on ReleaseOrchestrator.
            $deployment = app(DeploymentDispatcher::class)->createAutomaticFromReadyRelease($release);
        } catch (Throwable $e) {
            Log::warning('Release auto-deploy failed.', ['release_id' => $release->id, 'error' => $e->getMessage()]);
            $this->audit($release, 'release.auto_deploy_failed', ['error' => mb_substr($e->getMessage(), 0, 500)]);

            return null;
        }

        if ($deployment === null) {
            $this->audit($release, 'release.auto_deploy_skipped', ['reason' => self::SKIP_DEPLOYMENT_EXISTS]);

            return null;
        }

        $this->audit($release, 'release.auto_deployed', [
            'deployment_id' => $deployment->id,
            'deployment_status' => $deployment->status,
        ]);

        return $deployment;
    }

    private function skipReason(Release $release): ?string
    {
        if ($release->status !== Release::STATUS_READY) {
            return self::SKIP_NOT_READY;
        }
        if (! $release->project->is_active) {
            return self::SKIP_PROJECT_INACTIVE;
        }
        // A newer Release on the deployable branch exists (or the Release is
        // on a branch the Project no longer deploys): deploying this one
        // would roll production back to an older commit.
        if (! $this->eligibility->isCurrent($release)) {
            return self::SKIP_SUPERSEDED;
        }
        if (Deployment::query()->where('release_id', $release->id)->exists()) {
            return self::SKIP_DEPLOYMENT_EXISTS;
        }
        if ($release->project->latestSuccessfulDeployment()?->commit_hash === $release->commit_hash) {
            return self::SKIP_ALREADY_DEPLOYED;
        }

        return null;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Release $release, string $action, array $metadata): void
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: 'system:release-auto-deploy',
            action: $action,
            subjectType: Release::class,
            subjectId: $release->id,
            metadata: array_merge([
                'project_id' => $release->project_id,
                'commit_hash' => $release->commit_hash,
                'branch' => $release->branch,
            ], $metadata),
        );
    }
}
