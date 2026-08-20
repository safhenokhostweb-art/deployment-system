<?php

namespace App\Services\Deployment\Artifact;

use App\Models\Artifact;
use App\Models\AuditLogEntry;
use App\Models\Deployment;
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
    public function createPending(Project $project, string $commitHash, string $branch, string $claimedChecksum, int $claimedSizeBytes, ?string $uploadedBy, array $requiredPaths = []): Artifact
    {
        abort_unless(preg_match('/^[0-9a-f]{40}$/', $commitHash) === 1, 422, 'commit_hash must be a 40-character hex SHA.');
        abort_unless(preg_match('/^[0-9a-f]{64}$/', $claimedChecksum) === 1, 422, 'checksum must be a 64-character hex SHA-256.');
        abort_if($claimedSizeBytes > $this->maxSizeBytes(), 422, 'Artifact exceeds the maximum allowed size of ' . (int) ($this->maxSizeBytes() / 1024 / 1024) . 'MB.');

        $diskPath = "{$project->project_key}/{$commitHash}.zip";

        return DB::transaction(function () use ($project, $commitHash, $branch, $claimedChecksum, $claimedSizeBytes, $uploadedBy, $requiredPaths, $diskPath) {
            $existing = Artifact::where('project_id', $project->id)->where('commit_hash', $commitHash)->lockForUpdate()->first();
            if ($existing !== null) {
                abort_if($existing->checksum_sha256 !== $claimedChecksum, 409, 'artifact_checksum_conflict: this project and commit already have an artifact with a different checksum.');

                // Same canonical identity: preserve both the public ID and
                // an already-verified file. This is safe for a lost create
                // response and for concurrent retry callers.
                return $existing;
            }

            $artifact = Artifact::create([
                'project_id' => $project->id,
                'commit_hash' => $commitHash,
                'branch' => $branch,
                'checksum_sha256' => $claimedChecksum,
                'size_bytes' => $claimedSizeBytes,
                'required_paths' => array_values(array_unique($requiredPaths)),
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
     * Also independently re-validates the zip's own contents (defense in
     * depth against an outdated or misbehaving Local Agent binary that
     * skipped ArtifactBuilder's equivalent local checks — the same
     * "don't rely on a single enforcement point" principle
     * ServiceAllowlist already applies agent-side): a project whose zip
     * contains ANY vendor/ entry must contain vendor/autoload.php; ANY
     * public/build/ entry must come with public/build/manifest.json. A
     * project with neither directory in its zip at all (never ran
     * composer/npm) is unaffected — this only fires for a build that
     * demonstrably started but produced an incomplete result.
     *
     * @throws ArtifactValidationException on checksum mismatch or a failed content validation
     */
    public function finalizeUpload(Artifact $artifact, string $rawBytes, int $retentionCount): Artifact
    {
        if ($artifact->status === Artifact::STATUS_AVAILABLE) {
            $actualChecksum = hash('sha256', $rawBytes);
            abort_if($actualChecksum !== $artifact->checksum_sha256, 409, 'artifact_checksum_conflict: uploaded bytes differ from the available artifact.');

            return $artifact;
        }
        abort_unless($artifact->status === Artifact::STATUS_UPLOADING, 409, "Artifact is not awaiting upload (status: {$artifact->status}).");
        abort_if(strlen($rawBytes) > $this->maxSizeBytes(), 422, 'Artifact exceeds the maximum allowed size of ' . (int) ($this->maxSizeBytes() / 1024 / 1024) . 'MB.');

        $actualChecksum = hash('sha256', $rawBytes);

        if ($actualChecksum !== $artifact->checksum_sha256) {
            $this->rejectUpload($artifact, 'checksum_mismatch', "Checksum mismatch: expected {$artifact->checksum_sha256}, received {$actualChecksum}.", [
                'expected_checksum' => $artifact->checksum_sha256,
                'actual_checksum' => $actualChecksum,
            ]);
        }

        Storage::disk('artifacts')->put($artifact->disk_path, $rawBytes);

        $contentIssue = $this->validateArchiveContents($artifact);
        if ($contentIssue !== null) {
            Storage::disk('artifacts')->delete($artifact->disk_path);
            $this->rejectUpload($artifact, $contentIssue['reason'], $contentIssue['message'], []);
        }

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

    /**
     * @return array{reason: string, message: string}|null null if the archive looks structurally sound
     */
    private function validateArchiveContents(Artifact $artifact): ?array
    {
        $path = Storage::disk('artifacts')->path($artifact->disk_path);

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return ['reason' => 'corrupt_zip', 'message' => 'The uploaded artifact could not be opened as a zip archive.'];
        }

        if ($zip->numFiles === 0) {
            $zip->close();

            return ['reason' => 'empty_archive', 'message' => 'The uploaded artifact archive is empty.'];
        }

        $hasVendorEntry = false;
        $hasAutoload = false;
        $hasBuildEntry = false;
        $hasManifest = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            if (str_starts_with($name, 'vendor/')) {
                $hasVendorEntry = true;
                if ($name === 'vendor/autoload.php') {
                    $hasAutoload = true;
                }
            }
            if (str_starts_with($name, 'public/build/')) {
                $hasBuildEntry = true;
                if ($name === 'public/build/manifest.json') {
                    $hasManifest = true;
                }
            }
        }
        $zip->close();

        if ($hasVendorEntry && !$hasAutoload) {
            return ['reason' => 'missing_vendor', 'message' => 'The uploaded artifact has a vendor/ directory but no vendor/autoload.php.'];
        }

        if ($hasBuildEntry && !$hasManifest) {
            return ['reason' => 'missing_build', 'message' => 'The uploaded artifact has a public/build/ directory but no public/build/manifest.json.'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     * @throws ArtifactValidationException always — this method never returns
     */
    private function rejectUpload(Artifact $artifact, string $reason, string $message, array $metadata): void
    {
        $artifact->update(['status' => Artifact::STATUS_FAILED, 'failure_message' => $message]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_AGENT,
            actorIdentifier: $artifact->uploaded_by,
            action: 'artifact.upload_rejected',
            subjectType: Artifact::class,
            subjectId: $artifact->id,
            metadata: ['reason' => $reason] + $metadata,
        );

        throw new ArtifactValidationException($reason, $message);
    }

    public function findMatching(Project $project, string $commitHash): ?Artifact
    {
        return Artifact::where('project_id', $project->id)
            ->where('commit_hash', $commitHash)
            ->where('status', Artifact::STATUS_AVAILABLE)
            ->first();
    }

    /**
     * Single source of truth for attaching the exact-commit artifact to a
     * deployment. Only ever attaches the artifact whose (project, 40-char
     * commit SHA) exactly matches this deployment - never a latest,
     * branch-only, or other-commit artifact - and only for deploy actions.
     *
     * Called at every pre-queue boundary AND again at claim time (both the
     * legacy AgentJobService path and the new-domain AgentTaskService path)
     * so a Local Agent that finishes uploading the artifact a few seconds
     * after the webhook fired still gets that exact artifact correlated
     * with the webhook-created deployment instead of silently falling back
     * to the live-directory git pipeline.
     *
     * @return Artifact|null the artifact now attached (or already present)
     */
    public function attachToDeploymentIfAvailable(Deployment $deployment): ?Artifact
    {
        if ($deployment->action !== Deployment::ACTION_DEPLOY || $deployment->artifact_id !== null) {
            return $deployment->artifact;
        }

        $artifact = $this->findMatching($deployment->project, $deployment->commit_hash);

        if ($artifact !== null) {
            $deployment->update(['artifact_id' => $artifact->id]);
        }

        return $artifact;
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
     * @return array{public_id: string, checksum_sha256: string, size_bytes: int, required_paths: array<int,string>}
     */
    public function downloadDescriptor(Artifact $artifact): array
    {
        return [
            'public_id' => $artifact->public_id,
            'checksum_sha256' => $artifact->checksum_sha256,
            'size_bytes' => $artifact->size_bytes,
            'commit_hash' => $artifact->commit_hash,
            'required_paths' => $artifact->required_paths ?? [],
        ];
    }
}
