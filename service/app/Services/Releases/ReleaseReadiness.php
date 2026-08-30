<?php

namespace App\Services\Releases;

use App\Models\Artifact;
use App\Models\PublishJob;
use App\Models\Release;

final class ReleaseReadiness
{
    /** @return array{code:string,deployable:bool,message:string} */
    public function for(?string $commit, ?Release $release, ?Artifact $artifact): array
    {
        if ($commit === null) {
            return $this->state('NOT READY', false, 'The production branch head is unavailable.');
        }
        if ($release === null || ! is_array($release->metadata)) {
            return $this->state('NOT READY', false, 'Project-generated release metadata has not been recorded.');
        }
        if (($release->metadata['release_ready'] ?? false) !== true) {
            $flags = array_map('strtoupper', $release->metadata['risk_flags'] ?? []);
            if (in_array('MIXED_WORKTREE', $flags, true)) {
                return $this->state('MIXED WORKTREE', false, 'The project reported unrelated worktree changes.');
            }
            if (in_array('TEST_FAILURE', $flags, true)) {
                return $this->state('TEST FAILURE', false, 'Required project tests failed.');
            }
            if (in_array('MIGRATION_REVIEW_REQUIRED', $flags, true)) {
                return $this->state('MIGRATION REVIEW REQUIRED', false, 'A migration requires explicit review.');
            }
            return $this->state('NOT READY', false, 'The project release contract did not pass.');
        }
        if ($release->status === Release::STATUS_ARTIFACT_BUILDING) {
            return $this->state('ARTIFACT BUILDING', false, 'The exact-commit Artifact is building.');
        }
        if ($artifact === null || $release->status !== Release::STATUS_READY) {
            return $this->state('NOT READY', false, 'No physically valid Artifact exists for this exact commit.');
        }
        if (! $release->isLegacyFallbackEligible()) {
            $evidenceJobExists = PublishJob::query()
                ->whereKey($artifact->publish_job_id)
                ->where('release_id', $release->id)
                ->where('project_id', $release->project_id)
                ->where('action', PublishJob::ACTION_BUILD_ARTIFACT)
                ->where('status', PublishJob::STATUS_SUCCEEDED)
                ->whereNotNull('evidence_verified_at')
                ->where('reviewed_head_sha', $release->commit_hash)
                ->where('reviewed_branch', $release->branch)
                ->exists();
            if ($artifact->semantic_status !== 'verified' || ! $evidenceJobExists) {
                return $this->state('NOT READY', false, 'The exact-commit evidence and job-bound Artifact contract is incomplete.');
            }
        }
        return $this->state('READY TO DEPLOY', true, 'All release gates passed; operator confirmation is required.');
    }

    private function state(string $code, bool $deployable, string $message): array
    {
        return compact('code', 'deployable', 'message');
    }
}
