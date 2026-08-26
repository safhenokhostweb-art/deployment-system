<?php

namespace App\Services\Publish;

use App\Models\Artifact;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Support\Deployment\SourceWorkspacePath;

final class ArtifactCommitSelector
{
    public function __construct(private readonly ArtifactService $artifacts)
    {
    }
    public const MODE_LATEST = 'latest';

    public const MODE_ADVANCED = 'advanced';

    /**
     * Build the read model used by the Artifact commit selector. Normal
     * latest-release builds target the exact remote production-branch HEAD.
     * The workspace HEAD remains health/evidence only and must never shadow
     * a newer remote commit.
     *
     * @param  array<string, string|null>  $githubHeadsByBranch
     * @return array<int, array<string, mixed>>
     */
    public function options(Project $project, array $githubHeadsByBranch = []): array
    {
        $mappings = $project->requires_complete_artifact && $project->authoritative_build_local_agent_id !== null
            ? $project->localProjectMappings->where('local_agent_id', $project->authoritative_build_local_agent_id)
            : $project->localProjectMappings;

        return $mappings->map(function (LocalProjectMapping $mapping) use ($project, $githubHeadsByBranch): array {
            $branch = $project->branch;
            $workspaceSha = $this->validSha($mapping->local_head_sha) ? $mapping->local_head_sha : null;
            $githubSha = $githubHeadsByBranch[$branch] ?? null;
            $remoteSha = $this->latestRemoteCommit($mapping, $githubSha);
            $state = $this->workspaceState($mapping, $branch);
            $reportedSource = $this->reportedSourcePath($mapping);
            $artifact = $remoteSha === null ? null : $this->findAvailableArtifact($project, $remoteSha, $branch);

            return [
                'mapping_id' => $mapping->id,
                'agent_name' => $mapping->localAgent->name ?? $mapping->localAgent->public_id,
                'source_workspace_path' => $state === 'invalid_source' ? null : $reportedSource,
                'branch' => $branch,
                'workspace_sha' => $workspaceSha,
                'workspace_short_sha' => $workspaceSha === null ? null : substr($workspaceSha, 0, 12),
                'reported_at' => $mapping->reported_at?->toIso8601String(),
                'reported_at_human' => $mapping->reported_at?->diffForHumans(),
                'workspace_state' => $state,
                'is_verified' => $state === 'clean',
                'is_build_ready' => $this->isEligibleForExactRemoteBuild($state) && $remoteSha !== null,
                'github_sha' => $githubSha,
                'github_short_sha' => $githubSha === null ? null : substr($githubSha, 0, 12),
                'remote_sha' => $remoteSha,
                'remote_short_sha' => $remoteSha === null ? null : substr($remoteSha, 0, 12),
                'remote_source' => $this->validSha($githubSha) ? 'github' : ($remoteSha === null ? null : 'agent'),
                'remote_matches_workspace' => $remoteSha === null || $workspaceSha === null ? null : hash_equals($remoteSha, $workspaceSha),
                'existing_artifact_id' => $artifact?->id,
            ];
        })->values()->all();
    }

    public function resolveCommit(LocalProjectMapping $mapping, string $mode, ?string $selectedCommit, ?string $latestRemoteCommit = null): string
    {
        abort_unless(in_array($mode, [self::MODE_LATEST, self::MODE_ADVANCED], true), 422, 'Invalid Artifact commit selection mode.');

        if ($mode === self::MODE_ADVANCED) {
            abort_unless($this->validSha($selectedCommit), 422, 'Artifact build requires a valid 40-character commit SHA.');

            return $selectedCommit;
        }

        abort_unless($this->isEligibleForExactRemoteBuild($this->workspaceState($mapping, $mapping->project->branch)), 422, 'The source workspace does not currently have a valid, verified state for the production branch. Wait for Local Agent sync.');

        $remoteCommit = $this->latestRemoteCommit($mapping, $latestRemoteCommit);
        abort_unless($remoteCommit !== null, 422, 'The latest remote production-branch commit is not available. Wait for Local Agent sync or restore GitHub access.');

        return $remoteCommit;
    }

    /**
     * Root-cause fix (dirty persistent workspace incorrectly blocking a
     * safe exact-remote build): an exact-commit Artifact build never reads
     * the persistent workspace's WORKING TREE content — Agent-side,
     * WorkspaceSourcePreparer::prepareRemoteCommit() fetches the production
     * branch and checks out the exact requested commit inside a completely
     * separate, freshly `git init`'d isolated BuildWorkspace. The persistent
     * workspace is consulted only for its trusted remote URL/branch and, via
     * committed history (`merge-base --is-ancestor`), whether it has
     * diverged — never for its uncommitted or untracked files. A developer's
     * unrelated local WIP sitting in that workspace (e.g. a separate,
     * deliberately-uncommitted change) has zero bearing on that safety, so
     * 'dirty' must not block eligibility here — only a source path that
     * can't even be trusted/located ('invalid_source'), a workspace that has
     * never reported at all ('not_reported'), or one reporting the wrong
     * branch ('branch_mismatch') still does, since those mean this class
     * cannot establish a trustworthy remote to fetch from at all. Working-
     * tree cleanliness remains required exactly where it still matters:
     * the no-source-change publish flow (PublishJobService), which builds
     * and pushes from the live working tree, not an isolated exact commit.
     */
    private function isEligibleForExactRemoteBuild(string $state): bool
    {
        return in_array($state, ['clean', 'dirty'], true);
    }

    /** Resolve the exact remote branch HEAD without falling back to workspace HEAD. */
    public function latestRemoteCommit(LocalProjectMapping $mapping, ?string $githubCommit = null): ?string
    {
        if ($githubCommit !== null) {
            return $this->validSha($githubCommit) ? strtolower($githubCommit) : null;
        }

        if ($mapping->effectiveBranch() !== $mapping->project->branch
            || $mapping->reported_branch !== $mapping->project->branch
            || ! $this->validSha($mapping->remote_tracking_sha)) {
            return null;
        }

        return strtolower($mapping->remote_tracking_sha);
    }

    public function findAvailableArtifact(Project $project, string $commitHash, string $branch): ?Artifact
    {
        return $this->artifacts->findMatching($project, $commitHash, $branch);
    }

    private function workspaceState(LocalProjectMapping $mapping, ?string $expectedBranch = null): string
    {
        $source = $this->reportedSourcePath($mapping);
        if ($source === '' || SourceWorkspacePath::isProductionLike($source)) {
            return 'invalid_source';
        }

        if (! $this->validSha($mapping->local_head_sha) || $mapping->reported_at === null) {
            return 'not_reported';
        }

        if ($mapping->effectiveBranch() !== ($expectedBranch ?? $mapping->effectiveBranch())
            || $mapping->reported_branch !== ($expectedBranch ?? $mapping->effectiveBranch())) {
            return 'branch_mismatch';
        }

        if ($mapping->working_tree_dirty !== false) {
            return 'dirty';
        }

        return 'clean';
    }

    private function reportedSourcePath(LocalProjectMapping $mapping): string
    {
        return is_string($mapping->source_workspace_path) && trim($mapping->source_workspace_path) !== ''
            ? trim($mapping->source_workspace_path)
            : trim((string) $mapping->local_path);
    }

    private function validSha(?string $sha): bool
    {
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/', $sha) === 1;
    }
}
