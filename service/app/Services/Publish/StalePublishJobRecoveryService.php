<?php

namespace App\Services\Publish;

use App\Models\PublishJob;
use Illuminate\Support\Facades\DB;

/**
 * Root-cause fix: the single place that finds and finalizes a PublishJob
 * whose claiming Local Agent went silent or ran too long, plus the
 * review-expiry sweep that finalizes an awaiting_approval status_check
 * review older than publish_review_ttl_minutes — used by both the
 * deployments:recover-stale scheduled command and the Dashboard's
 * "بازیابی انتشارهای معلق" button, so the two never drift into two
 * slightly-different definitions of "stale". All actual state mutation
 * happens through PublishJobService (markAbandoned()/markTimedOut()/
 * expireReview()) — this class only selects candidates and classifies
 * which of the three applies.
 *
 * Idempotent and safe to run repeatedly or concurrently: a job is only
 * ever selected while it is still in its stale state (status='running'
 * AND lease expired, or awaiting_approval AND older than the review TTL);
 * once finalized it drops out of that query on the very next call, and two
 * overlapping runs racing for the same row are serialized by the row lock
 * PublishJobService::markAbandoned()/markTimedOut()/expireReview() take via
 * their own update() (last writer simply finds nothing left to do).
 */
class StalePublishJobRecoveryService
{
    public function __construct(
        private readonly PublishJobService $publishJobs,
    ) {
    }

    /**
     * @return array{checked: int, timed_out: int, abandoned: int, reviews_expired: int, deployments_finalized: int, locks_released: int}
     */
    public function recover(bool $dryRun = false): array
    {
        $now = now();
        $maxRuntimeSeconds = (int) config('deployment.publish_job_max_runtime_seconds');
        $reviewTtlMinutes = (int) config('deployment.publish_review_ttl_minutes');
        $reviewCutoff = $now->copy()->subMinutes($reviewTtlMinutes);

        $result = ['checked' => 0, 'timed_out' => 0, 'abandoned' => 0, 'reviews_expired' => 0, 'deployments_finalized' => 0, 'locks_released' => 0];

        $stale = PublishJob::query()
            ->where('status', PublishJob::STATUS_RUNNING)
            ->where(function ($query) use ($now) {
                $query->where('lease_expires_at', '<', $now)->orWhereNull('lease_expires_at');
            })
            ->with(['localAgent', 'project'])
            ->get();

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

        // Sweep 2 — expired reviews: an awaiting_approval status_check older
        // than publish_review_ttl_minutes is finalized as rejected
        // (publish_job.review_expired). A human approval review legitimately
        // stays open far longer than a running command, but not forever.
        // Reviews never acquire the project publish lock, so this sweep never
        // touches any lock state.
        $expiredReviews = PublishJob::query()
            ->where('action', PublishJob::ACTION_STATUS_CHECK)
            ->where('status', PublishJob::STATUS_AWAITING_APPROVAL)
            ->with(['project'])
            ->get();

        foreach ($expiredReviews as $candidate) {
            $openedAt = $candidate->finished_at ?? $candidate->created_at;
            if ($openedAt === null || $openedAt->gt($reviewCutoff)) {
                continue;
            }

            $outcome = DB::transaction(function () use ($candidate, $reviewCutoff, $dryRun) {
                // Same re-fetch-and-lock pattern as sweep 1 so a concurrent
                // run or a racing approve() can never both act on one row.
                $job = PublishJob::query()->lockForUpdate()->find($candidate->id);

                if ($job === null
                    || $job->action !== PublishJob::ACTION_STATUS_CHECK
                    || $job->status !== PublishJob::STATUS_AWAITING_APPROVAL) {
                    return null;
                }

                $openedAt = $job->finished_at ?? $job->created_at;
                if ($openedAt === null || $openedAt->gt($reviewCutoff)) {
                    return null;
                }

                if ($dryRun) {
                    return ['classification' => 'reviews_expired', 'lock_held' => false];
                }

                $this->publishJobs->expireReview($job);

                return ['classification' => 'reviews_expired', 'lock_held' => false];
            });

            if ($outcome === null) {
                continue;
            }

            $result['checked']++;
            $result['deployments_finalized']++;
            $result['reviews_expired']++;
        }

        return $result;
    }
}
