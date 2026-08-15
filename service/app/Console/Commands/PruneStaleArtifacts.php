<?php

namespace App\Console\Commands;

use App\Models\Artifact;
use App\Models\AuditLogEntry;
use App\Services\Deployment\Artifact\ArtifactService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Operator-facing counterpart to the scheduled artifact cleanup. It is
 * deliberately dry-run by default and only considers artifacts with no
 * Deployment association, so release/rollback evidence is never pruned.
 */
class PruneStaleArtifacts extends Command
{
    protected $signature = 'deployment-system:prune-stale {--execute : Delete the dry-run candidates}';

    protected $description = 'Report or delete stale, unreferenced artifact upload records';

    public function handle(): int
    {
        $candidates = $this->candidates();
        $this->table(['ID', 'Project', 'Commit', 'Status', 'Reason'], $candidates->map(fn (Artifact $artifact) => [
            $artifact->public_id,
            $artifact->project_id,
            $artifact->commit_hash,
            $artifact->status,
            $artifact->status === Artifact::STATUS_UPLOADING ? 'upload_timeout' : 'failed_retention',
        ])->all());

        if (!$this->option('execute')) {
            $this->info("Dry run: {$candidates->count()} stale, unreferenced artifact(s) would be deleted. Use --execute to apply.");
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($candidates as $candidate) {
            $deleted += DB::transaction(function () use ($candidate): int {
                $artifact = Artifact::query()->lockForUpdate()->find($candidate->id);
                if ($artifact === null || $artifact->deployments()->exists() || !$this->isStale($artifact)) {
                    return 0;
                }

                // Delete the private file before its row; if storage fails,
                // the transaction rolls back and leaves recoverable metadata.
                Storage::disk('artifacts')->delete($artifact->disk_path);
                AuditLogEntry::record(
                    actorType: AuditLogEntry::ACTOR_SYSTEM,
                    actorIdentifier: null,
                    action: 'artifact.pruned_stale',
                    subjectType: Artifact::class,
                    subjectId: $artifact->id,
                    metadata: ['project_id' => $artifact->project_id, 'commit_hash' => $artifact->commit_hash, 'status' => $artifact->status],
                );
                $artifact->delete();

                return 1;
            });
        }

        $this->info("Deleted {$deleted} stale, unreferenced artifact(s).");
        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Artifact> */
    private function candidates()
    {
        return Artifact::query()
            ->whereDoesntHave('deployments')
            ->where(function ($query): void {
                $query->where(function ($uploads): void {
                    $uploads->where('status', Artifact::STATUS_UPLOADING)
                        ->where('created_at', '<', now()->subMinutes(ArtifactService::UPLOAD_TIMEOUT_MINUTES));
                })->orWhere(function ($failed): void {
                    $failed->where('status', Artifact::STATUS_FAILED)
                        ->where('created_at', '<', now()->subDays((int) config('deployment.artifact_retention_days')));
                });
            })
            ->orderBy('id')
            ->get();
    }

    private function isStale(Artifact $artifact): bool
    {
        return ($artifact->status === Artifact::STATUS_UPLOADING
                && $artifact->created_at->lt(now()->subMinutes(ArtifactService::UPLOAD_TIMEOUT_MINUTES)))
            || ($artifact->status === Artifact::STATUS_FAILED
                && $artifact->created_at->lt(now()->subDays((int) config('deployment.artifact_retention_days'))));
    }
}
