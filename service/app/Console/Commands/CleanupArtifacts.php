<?php

namespace App\Console\Commands;

use App\Models\Artifact;
use App\Models\AuditLogEntry;
use App\Services\Deployment\Artifact\ArtifactService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Root-cause fix (customer-site direct-GitHub-dependency removal):
 * ArtifactService::cleanupOldArtifacts() already enforces the
 * per-project retention count on every successful upload, but two
 * things it can't catch on its own: an artifact that's simply been
 * sitting available past its expires_at (artifact_retention_days), and
 * one stuck at status=uploading because phase two (finalizeUpload())
 * never happened — a Local Agent that crashed or lost connectivity
 * between the two signed requests. Both would otherwise accumulate on
 * disk forever.
 */
class CleanupArtifacts extends Command
{
    protected $signature = 'artifacts:cleanup';

    protected $description = 'Delete expired artifacts and abandoned (never-finalized) artifact uploads';

    public function handle(): int
    {
        $expiredCount = $this->deleteExpired();
        $abandonedCount = $this->deleteAbandonedUploads();

        $this->info("Deleted {$expiredCount} expired artifact(s) and {$abandonedCount} abandoned upload(s).");

        return self::SUCCESS;
    }

    private function deleteExpired(): int
    {
        $expired = Artifact::where('status', Artifact::STATUS_AVAILABLE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->whereDoesntHave('deployments')
            ->get();

        foreach ($expired as $artifact) {
            Storage::disk('artifacts')->delete($artifact->disk_path);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'artifact.expired_and_deleted',
                subjectType: Artifact::class,
                subjectId: $artifact->id,
                metadata: ['project_id' => $artifact->project_id, 'commit_hash' => $artifact->commit_hash],
            );

            $artifact->delete();
        }

        return $expired->count();
    }

    private function deleteAbandonedUploads(): int
    {
        $abandoned = Artifact::where('status', Artifact::STATUS_UPLOADING)
            ->where('created_at', '<', now()->subMinutes(ArtifactService::UPLOAD_TIMEOUT_MINUTES))
            ->get();

        foreach ($abandoned as $artifact) {
            // The row used to be deleted with any partial file left behind
            // on the artifacts disk, where nothing would ever reclaim it.
            $fileDeleted = false;
            if ($artifact->disk_path !== null && Storage::disk('artifacts')->exists($artifact->disk_path)) {
                $fileDeleted = Storage::disk('artifacts')->delete($artifact->disk_path);
            }

            // A row that deployments reference (a pruned artifact being
            // rebuilt) keeps its row, so those deployments keep their link.
            $referenced = $artifact->deployments()->exists();

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'artifact.abandoned_upload_deleted',
                subjectType: Artifact::class,
                subjectId: $artifact->id,
                metadata: [
                    'project_id' => $artifact->project_id,
                    'commit_hash' => $artifact->commit_hash,
                    'age_minutes' => $artifact->created_at->diffInMinutes(now()),
                    'file_deleted' => $fileDeleted,
                    'row_kept_for_deployments' => $referenced,
                ],
            );

            if ($referenced) {
                $artifact->update(['status' => Artifact::STATUS_DELETED]);
            } else {
                $artifact->delete();
            }
        }

        return $abandoned->count();
    }
}
