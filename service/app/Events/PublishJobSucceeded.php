<?php

namespace App\Events;

use App\Models\PublishJob;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 5: fired when a Local Agent reports a successful publish OR
 * rollback push (see PublishJob::action) — deliberately a distinct
 * class from the existing RollbackCompleted event, which is about
 * rolling back a Deployment release, not a local git commit.
 */
class PublishJobSucceeded
{
    use Dispatchable;

    public function __construct(
        public readonly PublishJob $publishJob,
    ) {
    }
}
