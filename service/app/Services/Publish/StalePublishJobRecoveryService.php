<?php

namespace App\Services\Publish;

use App\Models\PublishJob;
use Illuminate\Support\Facades\DB;

/**
 * Root-cause fix: the single place that finds and finalizes a PublishJob
 * whose claiming Local Agent went silent or ran too long — used by both
 * the deployments:recover-stale scheduled command and the Dashboard's
 * "بازیابی انتشارهای معلق" button, so the two never drift into two
 * slightly-different definitions of "stale". All actual state mutation
 * happens through PublishJobService (markAbandoned()/markTimedOut()) —
 * this class only selects candidates and classifies which of the two
 * applies.
 *
 * Idempotent and safe to run repeatedly or concurrently: a job is only
 * ever selected while status='running' AND its lease has expired: once
 * finalized it drops out of that query on the very next call, and two
 * overlapping runs racing for the same row are serialized by the row
 * lock PublishJobService::markAbandoned()/markTimedOut() take via their
 * own update() (last writer simply finds nothing left to do).
 */
class StalePublishJobRecoveryService
{
    public function __construct(
        private readonly PublishJobService $publishJobs,
    ) {
    }

    /**
     * @return array{checked: int, timed_out: int, abandoned: int, deployments_finalized: int, locks_released: int}
     */
    public function recover(bool $dryRun = false): array
    {
        $now = now();
        $maxRuntimeSeconds = (int) config('deployment.publish_job_max_runtime_seconds');

        $stale = PublishJob::query()
            ->where('status', PublishJob::STATUS_RUNNING)
            ->where(function ($query) use ($now) {
                $query->where('lease_expires_at', '<', $now)->orWhereNull('lease_expires_at');
            })
            ->with(['localAgent', 'project'])
            ->get();

        $result = ['checked' => 0, 'timed_out' => 0, 'abandoned' => 0, 'deployments_finalized' => 0, 'locks_released' => 0];

        foreach ($stale as $candidate) {
            $outcome = DB::transaction(function () use ($candidate, $now, $maxRuntimeSeconds, $dryRun) {
                // Re-fetched and locked inside the transaction rather than
                // trusting the snapshot from the query above — a
                // concurrent run of this same sweep (the Dashboard button
                // firing while the scheduled command is also mid-run) or
                // the agent's own late report() arriving in between must
                // never both act on the same row.
                $job = PublishJob::query()->lockForUpdate()->find($candidate->id);

                if ($job === null || $job->status !== PublishJob::STATUS_RUNNING || !$job->isLeaseExpired()) {
                    return null;
                }

                $lockHeld = $job->project !== null && $job->project->locked_by_publish_job_id === $job->id;
                $runtimeSeconds = $job->started_at?->diffInSeconds($now);
                $exceededMaxRuntime = $runtimeSeconds !== null && $runtimeSeconds >= $maxRuntimeSeconds;

                if ($dryRun) {
                    return ['classification' => $exceededMaxRuntime ? 'timed_out' : 'abandoned', 'lock_held' => $lockHeld];
                }

                $metadata = [
                    'lease_expires_at' => $job->lease_expires_at?->toIso8601String(),
                    'last_heartbeat_at' => $job->heartbeat_at?->toIso8601String(),
                    'runtime_seconds' => $runtimeSeconds,
                ];

                if ($exceededMaxRuntime) {
                    $this->publishJobs->markTimedOut(
                        $job,
                        PublishJob::FAILURE_CODE_PROCESS_TIMEOUT,
                        "Exceeded the maximum allowed runtime of {$maxRuntimeSeconds}s — forcibly ended regardless of heartbeat activity.",
                        $metadata,
                    );

                    return ['classification' => 'timed_out', 'lock_held' => $lockHeld];
                }

                $agentIsOnline = $job->localAgent?->isOnline() ?? false;
                $failureCode = $agentIsOnline
                    ? PublishJob::FAILURE_CODE_JOB_LEASE_EXPIRED
                    : PublishJob::FAILURE_CODE_AGENT_HEARTBEAT_LOST;

                $this->publishJobs->markAbandoned(
                    $job,
                    $failureCode,
                    'No heartbeat was received before this job\'s lease expired — the local agent likely crashed, was killed, or lost connectivity mid-command.',
                    $metadata,
                );

                return ['classification' => 'abandoned', 'lock_held' => $lockHeld];
            });

            if ($outcome === null) {
                continue;
            }

            $result['checked']++;
            $result['deployments_finalized']++;
            $result[$outcome['classification']]++;
            if ($outcome['lock_held']) {
                $result['locks_released']++;
            }
        }

        return $result;
    }
}
