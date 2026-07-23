<?php

namespace App\Events;

use App\Models\GitHubAccount;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-J: fired on a successful GitHub App installation callback
 * (GitHubAccountController::callback()).
 */
class GitHubConnected
{
    use Dispatchable;

    public function __construct(
        public readonly GitHubAccount $account,
        public readonly string $connectedBy,
    ) {
    }
}
