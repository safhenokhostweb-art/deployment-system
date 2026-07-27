<?php

namespace App\Events;

use App\Models\PublishJob;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 5: fired when a Local Agent reports a failed publish OR
 * rollback push (see PublishJob::action) — deliberately a distinct
 * class from any existing deployment-rollback event.
 */
class PublishJobFailed
{
    use Dispatchable;

    public function __construct(
        public readonly PublishJob $publishJob,
    ) {
    }
}
