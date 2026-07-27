<?php

namespace App\Events;

use App\Models\LocalAgent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 5: fired the moment a brand-new Local Agent finishes its
 * self-registration call and is now waiting on an Admin's
 * approve/reject decision — mirrors AgentApprovalRequired.
 */
class LocalAgentApprovalRequired
{
    use Dispatchable;

    public function __construct(
        public readonly LocalAgent $localAgent,
    ) {
    }
}
