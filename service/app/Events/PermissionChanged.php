<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-J: fired when an Admin changes another user's role
 * (UserController::updateRole()).
 */
class PermissionChanged
{
    use Dispatchable;

    public function __construct(
        public readonly User $targetUser,
        public readonly string $previousRole,
        public readonly string $newRole,
        public readonly string $changedBy,
    ) {
    }
}
