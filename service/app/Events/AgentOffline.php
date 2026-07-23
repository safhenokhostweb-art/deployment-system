<?php

namespace App\Events;

use App\Models\Agent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-J: fired by the new agents:detect-offline scheduled command the
 * first sweep after an approved Agent crosses the existing
 * server_offline_threshold_seconds cutoff (Agent::isOnline()'s own,
 * unmodified threshold) — never re-fired on every later sweep while it
 * stays offline. See App\Console\Commands\DetectOfflineAgents.
 */
class AgentOffline
{
    use Dispatchable;

    public function __construct(
        public readonly Agent $agent,
    ) {
    }
}
