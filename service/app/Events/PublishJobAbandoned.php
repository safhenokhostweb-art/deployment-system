<?php

namespace App\Events;

use App\Models\PublishJob;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by deployments:recover-stale (never by the agent itself) when a
 * claimed PublishJob's lease expired with no heartbeat — the claiming
 * Local Agent crashed, was killed, or lost connectivity mid-command.
 * Deliberately distinct from PublishJobFailed: a failure is something the
 * agent observed and reported about its own command; an abandonment is
 * the Hub inferring, from silence, that no report is ever coming.
 */
class PublishJobAbandoned
{
    use Dispatchable;

    public function __construct(
        public readonly PublishJob $publishJob,
    ) {
    }
}
