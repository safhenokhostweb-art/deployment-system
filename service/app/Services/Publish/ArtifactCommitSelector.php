<?php

namespace App\Services\Publish;

use App\Models\Artifact;
use App\Models\LocalProjectMapping;
use App\Models\Project;

final class ArtifactCommitSelector
{
    public const MODE_LATEST = 'latest';

    public const MODE_ADVANCED = 'advanced';

    /**
     * Build the read model used by the Artifact commit selector. The Local
     * Agent heartbeat is authoritative for the workspace state; GitHub HEAD
     * is comparison-only and never silently replaces the workspace commit.
     *
     * @param  array<string, string|null>  $githubHeadsByBranch
     * @return array<int, array<string, mixed>>
     */
    public function options(Project $project, array $githubHeadsByBranch = []): array
    {
        return $project->localProjectMappings->map(function (LocalProjectMapping $mapping) use ($project, $githubHeadsByBranch): array {
            $branch = $mapping->effectiveBranch();
            $workspaceSha = $this->validSha($mapping->local_head_sha) ? $mapping->local_head_sha : null;
            $githubSha = $githubHeadsByBranch[$branch] ?? null;
            $state = $this->workspaceState($mapping);
            $artifact = $workspaceSha === null ? null : $this->findAvailableArtifact($project, $workspaceSha, $branch);

            return [
                'mapping_id' => $mapping->id,
                'agent_name' => $mapping->localAgent->name ?? $mapping->localAgent->public_id,
                'source_workspace_path' => $mapping->sourceWorkspacePath(),
                'branch' => $branch,
                'workspace_sha' => $workspaceSha,
                'workspace_short_sha' => $workspaceSha === null ? null : substr($workspaceSha, 0, 12),
                'reported_at' => $mapping->reported_at?->toIso8601String(),
                'reported_at_human' => $mapping->reported_at?->diffForHumans(),
                'workspace_state' => $state,
                'is_verified' => $state === 'clean',
                'github_sha' => $githubSha,
                'github_short_sha' => $githubSha === null ? null : substr($githubSha, 0, 12),
                'github_matches_workspace' => $githubSha === null || $workspaceSha === null ? null : hash_equals($githubSha, $workspaceSha),
                'existing_artifact_id' => $artifact?->id,
            ];
        })->values()->all();
    }

    public function resolveCommit(LocalProjectMapping $mapping, string $mode, ?string $selectedCommit): string
    {
        abort_unless(in_array($mode, [self::MODE_LATEST, self::MODE_ADVANCED], true), 422, 'Invalid Artifact commit selection mode.');

        if ($mode === self::MODE_ADVANCED) {
            abort_unless($this->validSha($selectedCommit), 422, 'Artifact build requires a valid 40-character commit SHA.');

            return $selectedCommit;
        }

        $currentCommit = $mapping->local_head_sha;
        abort_unless($this->workspaceState($mapping) === 'clean' && $this->validSha($currentCommit), 422, 'The source workspace does not currently have a clean, verified commit. Wait for Local Agent sync.');

        $selectedCommit ??= $currentCommit;
        abort_unless($this->validSha($selectedCommit) && hash_equals($currentCommit, $selectedCommit), 422, 'The selected commit is not the current verified source workspace commit.');

        return $selectedCommit;
    }

    public function findAvailableArtifact(Project $project, string $commitHash, string $branch): ?Artifact
    {
        return Artifact::query()
            ->where('project_id', $project->id)
            ->where('commit_hash', $commitHash)
            ->where('branch', $branch)
            ->where('status', Artifact::STATUS_AVAILABLE)
            ->latest('id')
            ->first();
    }

    private function workspaceState(LocalProjectMapping $mapping): string
    {
        if (! $this->validSha($mapping->local_head_sha) || $mapping->reported_at === null) {
            return 'not_reported';
        }

        if ($mapping->reported_branch !== $mapping->effectiveBranch()) {
            return 'branch_mismatch';
        }

        if ($mapping->working_tree_dirty !== false) {
            return 'dirty';
        }

        return 'clean';
    }

    private function validSha(?string $sha): bool
    {
        return is_string($sha) && preg_match('/^[0-9a-f]{40}$/', $sha) === 1;
    }
}
