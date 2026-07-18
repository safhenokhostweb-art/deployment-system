<?php

namespace App\Console\Commands;

use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentLogEntry;
use Illuminate\Console\Command;

/**
 * Retention policy:
 *  - Deployment log *output* (the raw, potentially large and
 *    secret-adjacent command output) is purged once its deployment has
 *    been finished for longer than deployment_log_output_retention_days.
 *    Step name, exit code, and duration are kept indefinitely — enough to
 *    keep deployment history meaningful without the unbounded text.
 *  - Audit log entries are kept much longer (default 365 days) since
 *    they're the compliance/forensic trail, and pruned only past that.
 */
class PruneDeploymentData extends Command
{
    protected $signature = 'deployment-system:prune-logs';

    protected $description = 'Purge old deployment log output and expired audit log entries per the retention policy';

    public function handle(): int
    {
        $logCutoff = now()->subDays((int) config('deployment.deployment_log_output_retention_days'));

        $purgedLogEntries = DeploymentLogEntry::query()
            ->where('output', '!=', '')
            ->whereHas('deployment', function ($query) use ($logCutoff) {
                $query->whereIn('status', [
                    Deployment::STATUS_SUCCEEDED,
                    Deployment::STATUS_FAILED,
                    Deployment::STATUS_CANCELLED,
                ])->where('finished_at', '<', $logCutoff);
            })
            ->update(['output' => '[purged by retention policy]']);

        $auditCutoff = now()->subDays((int) config('deployment.audit_log_retention_days'));
        $purgedAuditEntries = AuditLogEntry::where('created_at', '<', $auditCutoff)->delete();

        $this->info("Purged output on {$purgedLogEntries} log entry/entries older than {$logCutoff->toDateString()}.");
        $this->info("Deleted {$purgedAuditEntries} audit log entry/entries older than {$auditCutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
