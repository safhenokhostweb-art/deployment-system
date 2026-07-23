<?php

namespace App\Events;

use App\Models\Agent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-J: fired the moment a brand-new Agent finishes its
 * self-registration call and is now genuinely waiting on an Admin's
 * approve/reject decision — see AgentRegistrationController::register().
 */
class AgentApprovalRequired
{
    use Dispatchable;

    public function __construct(
        public readonly Agent $agent,
    ) {
    }
}
