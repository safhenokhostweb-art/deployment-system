<?php

namespace App\Events;

use App\Models\Agent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-J: fired when an Admin approves a pending Agent
 * (AgentController::approve()) — the point it becomes able to
 * authenticate, not its next physical poll (which happens every few
 * seconds once approved and would be far too noisy to notify on).
 */
class AgentConnected
{
    use Dispatchable;

    public function __construct(
        public readonly Agent $agent,
    ) {
    }
}
