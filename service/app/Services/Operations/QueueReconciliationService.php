<?php

namespace App\Services\Operations;

use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\LocalAgentControlJob;
use App\Models\PublishJob;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Publish\PublishJobService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Explicit, idempotent reconciliation of stale queue records. */
final class QueueReconciliationService
{
    public const STALE_MINUTES = 60;

    public function __construct(
        private readonly DeploymentDispatcher $deployments,
        private readonly PublishJobService $publishes,
    ) {}

    public function isStale($record): bool
    {
        $at = $record instanceof Deployment ? ($record->started_at ?? $record->updated_at) : ($record->started_at ?? $record->updated_at);
        return $at !== null && $at->lt(now()->subMinutes(self::STALE_MINUTES));
    }

    public function reconcile(string $type, int $id, string $actor, string $reason): void
    {
        abort_unless(trim($reason) !== '', 422, 'A reconciliation reason is required.');
        DB::transaction(function () use ($type, $id, $actor, $reason): void {
            $record = match ($type) {
                'deployment' => Deployment::query()->lockForUpdate()->findOrFail($id),
                'publish' => PublishJob::query()->lockForUpdate()->findOrFail($id),
                'agent_control' => LocalAgentControlJob::query()->lockForUpdate()->findOrFail($id),
                default => abort(422, 'Unsupported queue record type.'),
            };
            if ($this->terminal($record)) return;
            abort_unless($this->isStale($record), 409, 'Fresh operational records cannot be reconciled.');

            if ($record instanceof Deployment) {
                abort_unless(in_array($record->status, [Deployment::STATUS_PENDING_APPROVAL, Deployment::STATUS_QUEUED], true), 409, 'Only approval/queued deployments can be reconciled.');
                $this->deployments->cancel($record, $actor);
            } elseif ($record instanceof PublishJob) {
                if ($record->status === PublishJob::STATUS_RUNNING) {
                    $this->publishes->markAbandoned($record, PublishJob::FAILURE_CODE_JOB_LEASE_EXPIRED, $reason, ['reconciled_by' => $actor, 'reconciliation_reason' => $reason]);
                } else {
                    abort_unless(in_array($record->status, [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED, PublishJob::STATUS_AWAITING_APPROVAL], true), 409, 'Publish job is not reconcilable.');
                    $record->update(['status' => PublishJob::STATUS_REJECTED, 'error_message' => $reason, 'failure_code' => PublishJob::FAILURE_CODE_JOB_LEASE_EXPIRED]);
                }
            } else {
                abort_unless(in_array($record->status, [LocalAgentControlJob::STATUS_QUEUED, LocalAgentControlJob::STATUS_RUNNING, LocalAgentControlJob::STATUS_AWAITING_HEARTBEAT], true), 409, 'Agent-control job is not reconcilable.');
                $record->update(['status' => LocalAgentControlJob::STATUS_FAILED, 'error_message' => $reason, 'output' => 'Reconciled by '.$actor]);
            }
            AuditLogEntry::record(actorType: AuditLogEntry::ACTOR_USER, actorIdentifier: $actor, action: 'queue_record.reconciled', subjectType: $record::class, subjectId: $record->id, metadata: ['record_type' => $type, 'reason' => $reason]);
        });
    }

    private function terminal($record): bool
    {
        if ($record instanceof Deployment) {
            return in_array($record->status, [Deployment::STATUS_SUCCEEDED, Deployment::STATUS_FAILED, Deployment::STATUS_CANCELLED, Deployment::STATUS_PARTIAL_SUCCESS, Deployment::STATUS_ROLLED_BACK], true);
        }
        if ($record instanceof PublishJob) {
            return in_array($record->status, [PublishJob::STATUS_SUCCEEDED, PublishJob::STATUS_FAILED, PublishJob::STATUS_REJECTED, PublishJob::STATUS_TIMED_OUT, PublishJob::STATUS_ABANDONED], true);
        }
        return in_array($record->status, [LocalAgentControlJob::STATUS_SUCCEEDED, LocalAgentControlJob::STATUS_FAILED], true);
    }
}
