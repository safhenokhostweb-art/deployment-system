<?php

namespace App\Events;

use App\Models\Server;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-J: fired when a Server's agent_token/signing_secret are rotated
 * (ServerController::rotateToken()). Deliberately carries no credential
 * value at all — only the Server reference — so there is no way for a
 * Listener to ever put a secret into a notification's title/message
 * (Task 11).
 */
class CredentialRotated
{
    use Dispatchable;

    public function __construct(
        public readonly Server $server,
        public readonly string $rotatedBy,
    ) {
    }
}
