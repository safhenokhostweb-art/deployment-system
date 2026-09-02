<?php

namespace App\Console\Commands;

use App\Services\Releases\ObsoleteArtifactBuildReconciliationService;
use Illuminate\Console\Command;

/**
 * Root-cause fix (historical-release-recovery regression): a not-yet-started
 * build_artifact PublishJob that the old, unbounded
 * releases:retry-awaiting-artifact sweep queued for a Release since
 * superseded by a later push (see ReleaseRecoveryEligibility) never
 * invalidates itself — it just sits queued/approved until the authoritative
 * Agent eventually claims and builds it for no one. This is the explicit,
 * operator-invoked cleanup for that backlog: dry-run by default (reports
 * what it would invalidate without changing anything), --apply to actually
 * invalidate. Deliberately generic — it re-runs the fixed eligibility
 * contract rather than targeting any specific incident's job IDs, so it
 * remains correct for any future recurrence of the same shape.
 */
class ReconcileObsoleteArtifactBuilds extends Command
{
    protected $signature = 'releases:reconcile-obsolete-artifact-builds {--apply : Actually invalidate the obsolete jobs; without this flag, only reports what would change}';

    protected $description = 'Invalidate not-yet-started Artifact build PublishJobs queued for a Release since superseded by a later push to the same project+branch';

    public function handle(ObsoleteArtifactBuildReconciliationService $service): int
    {
        $apply = (bool) $this->option('apply');
        $result = $service->reconcile($apply);

        if ($apply) {
            $this->info(sprintf(
                '%d not-yet-started Artifact build job(s) examined: %d obsolete, %d invalidated.',
                $result['examined'],
                $result['obsolete'],
                $result['invalidated'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[dry-run] %d not-yet-started Artifact build job(s) examined: %d obsolete.',
            $result['examined'],
            $result['obsolete'],
        ));

        if ($result['obsolete'] > 0) {
            $this->comment('Re-run with --apply to invalidate them.');
        }

        return self::SUCCESS;
    }
}
