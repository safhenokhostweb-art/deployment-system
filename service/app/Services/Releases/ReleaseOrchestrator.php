<?php

namespace App\Services\Releases;

use App\Models\Artifact;
use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Release;
use Illuminate\Support\Facades\DB;

class ReleaseOrchestrator
{
    public function __construct(private readonly ReleaseMetadataValidator $validator)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function recordMetadata(Project $project, array $metadata): Release
    {
        return DB::transaction(function () use ($project, $metadata): Release {
            $release = Release::query()->firstOrCreate(
                ['project_id' => $project->id, 'commit_hash' => $metadata['commit_sha']],
                [
                    'branch' => $metadata['branch'],
                    'status' => Release::STATUS_SOURCE_RECEIVED,
                    'source' => 'project_contract',
                    'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
                    'source_received_at' => now(),
                ],
            );
            if ($release->branch !== null && $release->branch !== $metadata['branch']) {
                abort(409, 'Release metadata branch does not match the recorded release.');
            }
            if (isset($release->metadata['base_sha']) && $release->metadata['base_sha'] !== $metadata['base_sha']) {
                abort(409, 'RELEASE METADATA MISMATCH: base SHA changed for an existing release.');
            }
            $release->forceFill(['branch' => $metadata['branch'], 'metadata' => $metadata])->save();
            if ($metadata['release_ready'] !== true) {
                return $this->markFailed($release, 'contract_not_ready', 'Project release gates did not pass.');
            }
            return $this->syncArtifactState($release);
        });
    }

    /** @param array<string, mixed> $metadata */
    public function recordLegacyMetadata(Project $project, array $metadata, string $source, string $reason, string $actor): Release
    {
        abort_unless(in_array($source, ['project_contract', 'admin_backfill', 'import', 'legacy'], true), 422, 'Unsupported legacy Release source.');

        DB::transaction(function () use ($project, $metadata, $source, $reason, $actor): void {
            $release = Release::query()
                ->where('project_id', $project->id)
                ->where('commit_hash', $metadata['commit_sha'])
                ->lockForUpdate()
                ->first();

            if ($release !== null) {
                abort_unless($release->isLegacyFallbackEligible(), 409, 'An ordinary exact-commit Release cannot be reclassified for legacy fallback.');
                return;
            }

            $release = Release::create([
                'project_id' => $project->id,
                'commit_hash' => $metadata['commit_sha'],
                'branch' => $metadata['branch'],
                'status' => Release::STATUS_SOURCE_RECEIVED,
                'source' => $source,
                'readiness_policy' => Release::READINESS_POLICY_LEGACY_FALLBACK,
                'legacy_fallback_eligible_at' => now(),
                'legacy_fallback_reason' => $reason,
                'legacy_fallback_eligible_by' => $actor,
            ]);

            AuditLogEntry::record(
                actorType: $source === 'admin_backfill' ? AuditLogEntry::ACTOR_USER : AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: $actor,
                action: 'release.legacy_fallback_eligibility_created',
                subjectType: Release::class,
                subjectId: $release->id,
                metadata: ['source' => $source, 'reason' => $reason, 'commit_hash' => $metadata['commit_sha']],
            );
        });

        return $this->recordMetadata($project, $metadata);
    }

    public function createForCommit(
        Project $project,
        string $commitHash,
        ?string $branch = null,
        string $source = 'manual',
        array $metadata = []
    ): Release {
        return DB::transaction(function () use ($project, $commitHash, $branch, $source, $metadata): Release {
            $release = Release::query()->firstOrCreate(
                [
                    'project_id' => $project->id,
                    'commit_hash' => $commitHash,
                ],
                [
                    'branch' => $branch,
                    'status' => Release::STATUS_SOURCE_RECEIVED,
                    'source' => $source,
                    'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT,
                    'source_received_at' => now(),
                    'metadata' => $metadata === [] ? null : $metadata,
                ]
            );

            if (! $release->wasRecentlyCreated) {
                return $release;
            }

            return $this->syncArtifactState($release);
        });
    }

    /**
     * Root-cause fix (bootstrap deadlock): every push already creates a
     * Release (createForCommit) but never recorded WHY it isn't ready —
     * only an explicit signed POST or admin backfill ever populated real
     * metadata, so a Deploy Latest page had nothing to show but "metadata
     * has not been recorded" for every push, forever, until a human acted.
     * This records the FACTS a push already makes knowable — base SHA,
     * changed files, migration files, all GitHub-derived, never operator
     * input — the moment a webhook is processed, using infrastructure that
     * is always already deployed by the time any push happens (unlike a
     * new UI feature or project-side tool, which can't help bootstrap
     * themselves). It never sets release_ready — that still requires
     * genuine EVIDENCE (test/build attestation), which no push event can
     * honestly supply on its own.
     *
     * Deliberately never downgrades or overwrites an already-ready
     * (release_ready === true) release: once real evidence has been
     * recorded — by the signed endpoint or the admin backfill path — a
     * later webhook redelivery or duplicate call must be a pure no-op.
     *
     * @param  array<int, string>  $changedFiles
     * @param  array<int, string>  $migrationFiles
     */
    public function recordFactualMetadata(
        Project $project,
        Release $release,
        string $commitHash,
        string $branch,
        string $baseSha,
        array $changedFiles,
        array $migrationFiles,
    ): Release {
        return DB::transaction(function () use ($project, $release, $commitHash, $branch, $baseSha, $changedFiles, $migrationFiles): Release {
            $release = Release::query()->lockForUpdate()->find($release->id) ?? $release;
            $existing = is_array($release->metadata) ? $release->metadata : [];

            if (($existing['release_ready'] ?? false) === true) {
                return $release;
            }
            if (isset($existing['base_sha']) && $existing['base_sha'] !== $baseSha) {
                // A prior signed submission disagrees with what GitHub
                // itself now reports for this exact commit — a real
                // anomaly worth surfacing, not silently reconciling.
                abort(409, 'RELEASE METADATA MISMATCH: base SHA changed for an existing release.');
            }

            $metadata = array_merge($existing, [
                'schema_version' => $existing['schema_version'] ?? 1,
                'project_key' => $project->project_key,
                'commit_sha' => $commitHash,
                'base_sha' => $baseSha,
                'branch' => $branch,
                'changed_files' => $changedFiles,
                'migration_files' => $migrationFiles,
                'test_results' => $existing['test_results'] ?? [],
                'build_result' => $existing['build_result'] ?? ['required' => true, 'status' => 'pending', 'contract' => 'server-managed-exact-commit'],
                'risk_flags' => $existing['risk_flags'] ?? [],
                'created_timestamp' => $existing['created_timestamp'] ?? now()->toIso8601String(),
                'release_ready' => false,
                'source' => $existing['source'] ?? 'webhook_derived',
            ]);
            $metadata = $this->validator->validate($project, $metadata);

            $release->forceFill(['branch' => $branch, 'metadata' => $metadata])->save();

            return $release->refresh();
        });
    }

    public function syncArtifactState(Release $release): Release
    {
        return DB::transaction(function () use ($release): Release {
            // Read from the row, not the (possibly stale) passed-in model:
            // automatic deployment fires only on a real not-READY -> READY
            // transition, never for a Release that was already READY.
            $wasReady = Release::query()->whereKey($release->id)->value('status') === Release::STATUS_READY;

            $artifact = Artifact::query()
                ->where('project_id', $release->project_id)
                ->where('commit_hash', $release->commit_hash)
                ->where('branch', $release->branch)
                ->where('status', Artifact::STATUS_AVAILABLE)
                ->lockForUpdate()
                ->first();

            if ($artifact !== null) {
                $artifact->update(['release_id' => $release->id]);

                if ($release->dependency_snapshot === null
                    && is_array($artifact->publishJob?->dependency_snapshot)
                    && $artifact->publishJob->dependency_snapshot !== []) {
                    $release->forceFill(['dependency_snapshot' => $artifact->publishJob->dependency_snapshot])->save();
                }

                $metadata = is_array($release->metadata) ? $release->metadata : [];
                $legacyFallback = $release->isLegacyFallbackEligible()
                    && ($metadata['release_ready'] ?? false) === true;
                $evidenceJob = PublishJob::query()
                    ->where('release_id', $release->id)
                    ->where('action', PublishJob::ACTION_BUILD_ARTIFACT)
                    ->where('status', PublishJob::STATUS_SUCCEEDED)
                    ->whereNotNull('evidence_verified_at')
                    ->where('reviewed_head_sha', $release->commit_hash)
                    ->where('reviewed_branch', $release->branch)
                    ->first();
                $exactEvidenceReady = $evidenceJob !== null
                    && $artifact->publish_job_id === $evidenceJob->id
                    && $artifact->release_id === $release->id
                    && $artifact->semantic_status === 'verified';
                $legacyReady = $legacyFallback
                    && in_array($artifact->semantic_status, ['legacy', 'verified'], true);

                if ($exactEvidenceReady || $legacyReady) {
                    if ($exactEvidenceReady) {
                        $evidence = $evidenceJob->release_evidence ?? [];
                        $metadata['test_results'] = array_map(
                            fn (array $check): array => ['name' => $check['id'], 'status' => $check['status']],
                            is_array($evidence['checks'] ?? null) ? $evidence['checks'] : [],
                        );
                        $metadata['build_result'] = ['required' => true, 'status' => 'passed', 'contract' => 'hub-exact-commit-evidence-v1'];
                        $metadata['release_ready'] = true;
                        $metadata['evidence_digest'] = $evidenceJob->evidence_digest;
                    }

                    $release->forceFill([
                        'status' => Release::STATUS_READY,
                        'metadata' => $metadata,
                        'artifact_checked_at' => now(),
                        'ready_at' => now(),
                        'failed_at' => null,
                        'failure_code' => null,
                        'failure_message' => null,
                    ])->save();

                    if (! $wasReady) {
                        // After commit, so the auto-deploy transaction never
                        // nests inside (or rolls back with) this one. Runs
                        // immediately when there is no outer transaction.
                        $releaseId = $release->id;
                        DB::afterCommit(static fn () => app(ReleaseAutoDeployService::class)->afterReleaseReady($releaseId));
                    }

                    return $release->refresh();
                }

                $activeBuild = PublishJob::query()
                    ->where('release_id', $release->id)
                    ->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED, PublishJob::STATUS_RUNNING])
                    ->exists();
                if (!$release->isLegacyFallbackEligible() && array_key_exists('release_ready', $metadata)) {
                    $metadata['release_ready'] = false;
                }
                $release->forceFill([
                    'status' => $activeBuild ? Release::STATUS_ARTIFACT_BUILDING : Release::STATUS_AWAITING_ARTIFACT,
                    'metadata' => $metadata,
                    'artifact_checked_at' => now(),
                    'ready_at' => null,
                ])->save();

                return $release->refresh();
            }

            $metadata = is_array($release->metadata) ? $release->metadata : [];
            if (!$release->isLegacyFallbackEligible() && array_key_exists('release_ready', $metadata)) {
                $metadata['release_ready'] = false;
            }
            $release->forceFill([
                'status' => Release::STATUS_AWAITING_ARTIFACT,
                'metadata' => $metadata,
                'artifact_checked_at' => now(),
                'ready_at' => null,
            ])->save();

            return $release->refresh();
        });
    }

    public function markArtifactBuilding(Release $release): Release
    {
        $release->forceFill([
            'status' => Release::STATUS_ARTIFACT_BUILDING,
            'failed_at' => null,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        return $release->refresh();
    }

    public function markFailed(Release $release, string $failureCode, ?string $failureMessage = null): Release
    {
        $release->forceFill([
            'status' => Release::STATUS_FAILED,
            'failed_at' => now(),
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
        ])->save();

        return $release->refresh();
    }
}
