<?php

namespace App\Services\Releases;

use App\Models\Project;
use App\Models\Release;

/**
 * Root-cause fix (historical-release-recovery regression): the domain
 * definition of "the Release that still matters" for a project+branch —
 * the single fact every AUTOMATIC (unattended) artifact-recovery path
 * must agree on before acting on a Release, so none of them independently
 * reinvents "which Release is current" and disagrees with the others.
 * Shared by ProcessGitHubWebhookPush's inline retry, the
 * releases:retry-awaiting-artifact sweep, and the
 * releases:reconcile-obsolete-artifact-builds cleanup command.
 *
 * Each webhook push appends exactly one new Release row for its
 * (project_id, branch) pair (see ReleaseOrchestrator::createForCommit()),
 * so — exactly like a git branch ref — the newest such row for a given
 * project+branch IS the tip; every older sibling has been superseded by a
 * subsequent push, regardless of whatever status (awaiting_artifact,
 * failed, even ready) it was left in. This is deliberately NOT "highest id
 * overall" or any other arbitrary ordering — it is scoped to the exact
 * (project_id, branch) identity a Release shares with every other Release
 * that could ever supersede it, mirroring how a git branch ref itself only
 * ever points at one commit at a time.
 */
final class ReleaseRecoveryEligibility
{
    public function isCurrent(Release $release): bool
    {
        if ($release->branch !== $release->project->branch) {
            // Recorded against a branch the Project no longer tracks as
            // its deployable branch at all — never current, regardless of
            // how recently it was created.
            return false;
        }

        return ! Release::query()
            ->where('project_id', $release->project_id)
            ->where('branch', $release->branch)
            ->where('id', '>', $release->id)
            ->exists();
    }

    /**
     * Finder counterpart to isCurrent() above, expressing the exact same
     * "newest row for this project+branch is the tip" rule — the single
     * place any operator-facing "what is the current Release right now"
     * question (e.g. Review & Deploy Latest) resolves it from, so it can
     * never independently drift from what automatic recovery agrees is
     * current. Deliberately status-agnostic: a current Release that is
     * awaiting_artifact/failed is still returned as-is — never skipped
     * backward to an older ready sibling, exactly like isCurrent() never
     * lets one supersede an older Release only when the newer one itself
     * passes.
     */
    public function currentReleaseFor(Project $project): ?Release
    {
        return Release::query()
            ->where('project_id', $project->id)
            ->where('branch', $project->branch)
            ->latest('id')
            ->first();
    }

    /**
     * Full gate for automatic (unattended) artifact-build recovery: the
     * Project must still want a complete Artifact at all and be active,
     * and $release must still be the branch tip. Deliberately narrower
     * than "is this Release generally valid" — an operator-initiated
     * Dashboard build is not bound by this, only unattended recovery.
     */
    public function isEligibleForAutomaticRecovery(Release $release): bool
    {
        return (bool) $release->project->requires_complete_artifact
            && (bool) $release->project->is_active
            && $this->isCurrent($release);
    }
}
