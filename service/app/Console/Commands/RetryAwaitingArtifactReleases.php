<?php

namespace App\Console\Commands;

use App\Services\Releases\ReleaseArtifactBuildRetryService;
use Illuminate\Console\Command;

/**
 * Root-cause fix (release-orchestration bootstrap deadlock): a Release
 * left `awaiting_artifact` because its authoritative build Agent was
 * ineligible (see ProcessGitHubWebhookPush's auto-queued exact-commit
 * upgrade for that Agent) has no other path back to building — GitHub
 * never redelivers a push on its own. This re-attempts the build for
 * every such Release on a schedule, so it recovers automatically the
 * moment its Agent becomes eligible, with no new push required.
 */
class RetryAwaitingArtifactReleases extends Command
{
    protected $signature = 'releases:retry-awaiting-artifact';

    protected $description = 'Re-attempt the Artifact build for Releases stuck awaiting_artifact once their authoritative build Agent becomes eligible';

    public function handle(ReleaseArtifactBuildRetryService $retry): int
    {
        $result = $retry->retry();

        $this->info(sprintf(
            '%d current awaiting_artifact Release(s) checked (%d superseded and skipped): %d build(s) queued.',
            $result['checked'],
            $result['superseded'],
            $result['queued'],
        ));

        return self::SUCCESS;
    }
}
