<?php

namespace App\Events;

use App\Models\LocalChangeDetectionJob;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 5.2: fired exactly once, on the transition into
 * `waiting_approval` — never on a repeated report while the developer
 * keeps editing (see LocalChangeDetectionService::report()).
 */
class LocalChangeDetectionAwaitingApproval
{
    use Dispatchable;

    public function __construct(
        public readonly LocalChangeDetectionJob $changeDetectionJob,
    ) {
    }
}
