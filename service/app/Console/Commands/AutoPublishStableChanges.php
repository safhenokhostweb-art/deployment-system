<?php

namespace App\Console\Commands;

use App\Models\LocalChangeDetectionJob;
use App\Models\LocalProjectMapping;
use App\Services\Publish\LocalChangeDetectionService;
use Illuminate\Console\Command;

/**
 * Phase 5.2 §3/§8: the Hub-side half of Auto Mode. The Local Agent's
 * watcher only ever reports what it sees (see
 * LocalChangeDetectionService::report()) — it has no notion of
 * deployment_mode. This scheduled sweep is what actually decides "has
 * this been stable long enough to auto-publish", the same "run on the
 * same cadence it checks" shape as DetectOfflineAgents, so a change
 * that keeps getting updated every cycle never crosses the stability
 * window while it's still moving.
 */
class AutoPublishStableChanges extends Command
{
    protected $signature = 'publish:auto-publish-stable-changes';

    protected $description = 'Auto-publish LocalChangeDetectionJob rows that have been stable long enough on an Auto Mode mapping';

    public function handle(LocalChangeDetectionService $changeDetection): int
    {
        $stableBefore = now()->subMinutes((int) config('deployment.auto_publish_stability_minutes'));

        $stable = LocalChangeDetectionJob::query()
            ->where('status', LocalChangeDetectionJob::STATUS_DETECTED)
            ->where('detected_at', '<=', $stableBefore)
            ->whereHas('localAgent', fn ($query) => $query->where('status', 'approved'))
            ->get();

        $published = 0;

        foreach ($stable as $job) {
            $mapping = LocalProjectMapping::where('local_agent_id', $job->local_agent_id)
                ->where('project_id', $job->project_id)
                ->first();

            if ($mapping === null || $mapping->deployment_mode !== LocalProjectMapping::MODE_AUTO) {
                continue; // mode was switched away from 'auto' since detection — leave it alone
            }

            if ($job->files_changed_count === 0) {
                continue; // nothing to publish
            }

            $changeDetection->approve($job, null, 'local-agent-auto-publish', triggeredBy: 'auto');
            $published++;
        }

        $this->info("Auto-published {$published} stable change(s).");

        return self::SUCCESS;
    }
}
