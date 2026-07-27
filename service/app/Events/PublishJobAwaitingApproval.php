<?php

namespace App\Events;

use App\Models\PublishJob;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 5: fired once the Local Agent has reported `git status` for a
 * publish request and the operator now needs to review the changed
 * files and either approve (with a commit message) or leave it. Never
 * fired for a rollback (nothing to review there).
 */
class PublishJobAwaitingApproval
{
    use Dispatchable;

    public function __construct(
        public readonly PublishJob $publishJob,
    ) {
    }
}
