<?php

namespace App\Console\Commands;

use App\Models\AuditLogEntry;
use App\Models\Deployment;
use Illuminate\Console\Command;

/**
 * A deployment an agent claimed but then never reported back on again
 * (crashed process, lost connectivity, killed server) would otherwise sit
 * in "running" forever, permanently blocking that server's queue — job
 * claiming only ever looks at `status = queued`, so a stuck `running` row
 * isn't retried automatically. This finds those and fails them so a human
 * (or a re-push) can recover.
 */
class DetectStalledDeployments extends Command
{
    protected $signature = 'deployments:detect-stalled';

    protected $description = 'Mark running deployments with no recent agent activity as failed';

    public function handle(): int
    {
        $timeoutMinutes = (int) config('deployment.stalled_deployment_timeout_minutes');
        $cutoff = now()->subMinutes($timeoutMinutes);

        $stalled = Deployment::query()
            ->where('status', Deployment::STATUS_RUNNING)
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhereNull('last_activity_at');
            })
            ->get();

        foreach ($stalled as $deployment) {
            $deployment->update([
                'status' => Deployment::STATUS_FAILED,
                'finished_at' => now(),
                'failure_message' => "No activity reported for over {$timeoutMinutes} minutes; assumed the agent lost connectivity or crashed mid-job.",
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: null,
                action: 'deployment.marked_stalled',
                subjectType: Deployment::class,
                subjectId: $deployment->id,
                metadata: ['timeout_minutes' => $timeoutMinutes],
            );
        }

        $this->info("Marked {$stalled->count()} stalled deployment(s) as failed.");

        return self::SUCCESS;
    }
}
