<?php

namespace App\Console\Commands;

use App\Services\Publish\StalePublishJobRecoveryService;
use Illuminate\Console\Command;

/**
 * Root-cause fix: PublishJob (Local Agent publish/rollback) previously had
 * no equivalent of DetectStalledDeployments — a job whose claiming agent
 * hung (e.g. a `git add` blocked on a huge diff) or crashed mid-command
 * sat "running" forever, with no lease to expire and nothing to notice.
 * This is that missing piece for the PublishJob domain specifically; the
 * existing deployments:detect-stalled already fully owns detection AND
 * recovery for the separate Deployment/AgentJob domain (it does not
 * merely notify — see its own docblock), so this command intentionally
 * does not touch those tables at all, avoiding any overlap between the
 * two.
 *
 * All actual selection/classification/mutation logic lives in
 * StalePublishJobRecoveryService, shared with the Dashboard's "بازیابی
 * انتشارهای معلق" button — this command is a thin, schedulable wrapper
 * around it.
 */
class RecoverStalePublishJobs extends Command
{
    protected $signature = 'deployments:recover-stale {--dry-run : Report what would be recovered without changing anything}';

    protected $description = 'Finalize PublishJobs whose claiming Local Agent went silent (lease expired) or ran too long';

    public function handle(StalePublishJobRecoveryService $recovery): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $recovery->recover($dryRun);

        $prefix = $dryRun ? '[dry-run] Would recover' : 'Recovered';

        $this->info(sprintf(
            '%s %d stale publish job(s) checked: %d timed out, %d abandoned, %d review(s) expired, %d deployment(s) finalized, %d lock(s) released.',
            $prefix,
            $result['checked'],
            $result['timed_out'],
            $result['abandoned'],
            $result['reviews_expired'],
            $result['deployments_finalized'],
            $result['locks_released'],
        ));

        return self::SUCCESS;
    }
}
