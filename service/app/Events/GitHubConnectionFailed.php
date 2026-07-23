<?php

namespace App\Events;

use App\Models\GitHubAccount;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-J: fired when an already-connected GitHub account's connection
 * test fails (GitHubAccountController::testConnection()) — a previously
 * working integration silently broke, which is exactly the kind of thing
 * worth surfacing in the Notification Center. The initial connect()
 * failure isn't separately notified: it's synchronous, immediate UI
 * feedback on the same page the operator is already looking at, and no
 * GitHubAccount row exists yet at that point to attach a notification to.
 */
class GitHubConnectionFailed
{
    use Dispatchable;

    public function __construct(
        public readonly GitHubAccount $account,
    ) {
    }
}
