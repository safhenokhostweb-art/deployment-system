<?php

namespace App\Services\Deployment\Artifact;

use App\Models\Artifact;
use App\Models\AuditLogEntry;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly). Owns the
 * artifact lifecycle Hub-side: receiving an upload from the Local Agent
 * (which built it via `git archive` on a machine that DOES have GitHub
 * access), verifying its checksum, storing it on the private `artifacts`
 * disk, and matching one to a Deployment by (project_id, commit_hash) —
 * see DeploymentDispatcher::attachArtifactIfAvailable().
 *
 * Upload is deliberately two phases, not one multipart request:
 * PHP's `php://input` (what request-body signature verification reads)
 * is documented as unavailable for `multipart/form-data` requests, so a
 * single multipart upload could never be verified by the existing HMAC
 * middleware's whole-body hash — a real constraint discovered while
 * building this, not a stylistic choice. Splitting into
 * createPending() (a normal, fully JSON-bodied, signed request —
 * metadata only) and finalizeUpload() (a signed request whose ENTIRE
 * raw body IS the file bytes, Content-Type: application/octet-stream)
 * fits the existing signing scheme with zero changes to shared
 * middleware, and the checksum recorded (and authenticated) in phase
 * one is what phase two's received bytes are verified against.
 */
class ArtifactService
{
    /** Hard ceiling regardless of any project/config override — a bug or a misbehaving agent must never be able to fill the disk. */
    public const MAX_ARTIFACT_SIZE_BYTES = 500 * 1024 * 1024;

    /** How long an artifact may sit at status=uploading before artifacts:cleanup treats it as abandoned (phase one happened, phase two never did). */
    public const UPLOAD_TIMEOUT_MINUTES = 60;

    public function maxSizeBytes(): int
    {
        // The class constant is a hard ceiling config can only ever
        // narrow, never raise — a misconfigured env var must never let
        // an artifact past the size this was ever designed to handle.
        return min(self::MAX_ARTIFACT_SIZE_BYTES, (int) config('deployment.artifact_max_size_mb') * 1024 * 1024);
    }

    /**
     * Phase one: records the claimed metadata (including checksum) under
     * a normal signed JSON request. No file bytes exist yet — status
     * stays `uploading` until finalizeUpload() succeeds.
     */
    public function createPending(Project $project, string $commitHash, string $branch, string $claimedChecksum, int $claimedSizeBytes, ?string $uploadedBy): Artifact
    {
        abort_unless(preg_match('/^[0-9a-f]{40}$/', $commitHash) === 1, 422, 'commit_hash must be a 40-character hex SHA.');
        abort_unless(preg_match('/^[0-9a-f]{64}$/', $claimedChecksum) === 1, 422, 'checksum must be a 64-character hex SHA-256.');
        abort_if($claimedSizeBytes > $this->maxSizeBytes(), 422, 'Artifact exceeds the maximum allowed size of ' . (int) ($this->maxSizeBytes() / 1024 / 1024) . 'MB.');

        $diskPath = "{$project->project_key}/{$commitHash}.zip";

        return DB::transaction(function () use ($project, $commitHash, $branch, $claimedChecksum, $claimedSizeBytes, $uploadedBy, $diskPath) {
            // Upsert: a retried upload for the same commit (an earlier
            // attempt failed partway) replaces the old row/file instead of
            // violating the (project_id, commit_hash) unique index.
            $existing = Artifact::where('project_id', $project->id)->where('commit_hash', $commitHash)->lockForUpdate()->first();
            if ($existing !== null) {
                Storage::disk('artifacts')->delete($existing->disk_path);
                $existing->delete();
            }

            $artifact = Artifact::create([
                'project_id' => $project->id,
                'commit_hash' => $commitHash,
                'branch' => $branch,
                'checksum_sha256' => $claimedChecksum,
                'size_bytes' => $claimedSizeBytes,
                'disk_path' => $diskPath,
                'status' => Artifact::STATUS_UPLOADING,
                'uploaded_by' => $uploadedBy,
                'expires_at' => now()->addDays((int) config('deployment.artifact_retention_days')),
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $uploadedBy,
                action: 'artifact.upload_started',
                subjectType: Artifact::class,
                subjectId: $artifact->id,
                metadata: ['project_id' => $project->id, 'commit_hash' => $commitHash, 'claimed_size_bytes' => $claimedSizeBytes],
            );

            return $artifact;
        });
    }

    /**
     * Phase two: $rawBytes is the exact, already-signed-and-verified
     * request body. Rejects (marks failed, deletes any partial file) if
     * its actual sha256 doesn't match what phase one recorded — that
     * checksum was itself authenticated via a proper signed request, so
     * this is a genuine tamper/corruption check, not a redundant one.
     *
     * @throws \RuntimeException on checksum mismatch
     */
    public function finalizeUpload(Artifact $artifact, string $rawBytes, int $retentionCount): Artifact
    {
        abort_unless($artifact->status === Artifact::STATUS_UPLOADING, 409, "Artifact is not awaiting upload (status: {$artifact->status}).");
        abort_if(strlen($rawBytes) > $this->maxSizeBytes(), 422, 'Artifact exceeds the maximum allowed size of ' . (int) ($this->maxSizeBytes() / 1024 / 1024) . 'MB.');

        $actualChecksum = hash('sha256', $rawBytes);

        if ($actualChecksum !== $artifact->checksum_sha256) {
            $artifact->update(['status' => Artifact::STATUS_FAILED, 'failure_message' => "Checksum mismatch: expected {$artifact->checksum_sha256}, received {$actualChecksum}."]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_AGENT,
                actorIdentifier: $artifact->uploaded_by,
                action: 'artifact.checksum_mismatch_rejected',
                subjectType: Artifact::class,
                subjectId: $artifact->id,
                metadata: ['expected_checksum' => $artifact->checksum_sha256, 'actual_checksum' => $actualChecksum],
            );

            throw new \RuntimeException("Uploaded artifact checksum mismatch: expected {$artifact->checksum_sha256}, received {$actualChecksum}.");
        }

        Storage::disk('artifacts')->put($artifact->disk_path, $rawBytes);

        $artifact->update(['status' => Artifact::STATUS_AVAILABLE, 'size_bytes' => strlen($rawBytes)]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $artifact->uploaded_by,
            action: 'artifact.uploaded',
            subjectType: Artifact::class,
            subjectId: $artifact->id,
            metadata: ['project_id' => $artifact->project_id, 'commit_hash' => $artifact->commit_hash, 'size_bytes' => $artifact->size_bytes],
        );

        $this->cleanupOldArtifacts($artifact->project, $retentionCount);

        return $artifact->refresh();
    }

    public function findMatching(Project $project, string $commitHash): ?Artifact
    {
        return Artifact::where('project_id', $project->id)
            ->where('commit_hash', $commitHash)
            ->where('status', Artifact::STATUS_AVAILABLE)
            ->first();
    }

    /**
     * Keeps only the newest $retentionCount AVAILABLE artifacts for a
     * project, deleting both the row and its file for anything older —
     * called after every successful upload so disk usage never grows
     * unbounded even if the scheduled artifacts:cleanup command's run is
     * delayed. Never touches a row still at status=uploading (that's
     * artifacts:cleanup's job, via a timeout, not a retention count).
     */
    public function cleanupOldArtifacts(Project $project, int $retentionCount): int
    {
        $stale = Artifact::where('project_id', $project->id)
            ->where('status', Artifact::STATUS_AVAILABLE)
            ->orderByDesc('created_at')
            ->skip($retentionCount)
            ->take(PHP_INT_MAX)
            ->get();

        foreach ($stale as $artifact) {
            Storage::disk('artifacts')->delete($artifact->disk_path);
            $artifact->delete();
        }

        return $stale->count();
    }

    /**
     * Streams the artifact's checksum/size for the job payload
     * (AgentJobService::claimNextJob()) — never the disk_path itself,
     * which is Hub-internal storage detail, not something the Server
     * Agent needs or should see.
     *
     * @return array{public_id: string, checksum_sha256: string, size_bytes: int}
     */
    public function downloadDescriptor(Artifact $artifact): array
    {
        return [
            'public_id' => $artifact->public_id,
            'checksum_sha256' => $artifact->checksum_sha256,
            'size_bytes' => $artifact->size_bytes,
        ];
    }
}
