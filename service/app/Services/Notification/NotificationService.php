<?php

namespace App\Services\Notification;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 4-J: the only place that writes to the notifications table.
 * Deliberately thin — a pure presentation/awareness layer, never a
 * source of business logic (see docs/PHASE_4J_NOTIFICATION_OPERATIONS_REPORT.md
 * §3-5). Every call site is a Listener reacting to an event that was
 * already going to fire regardless of whether this service exists.
 */
class NotificationService
{
    /**
     * @param class-string|null $actorType one of AuditLogEntry::ACTOR_* for consistency, or null when there's no meaningful actor
     */
    public function notify(
        User $user,
        string $type,
        string $title,
        string $message,
        string $severity,
        ?Model $related = null,
        ?string $actorType = null,
        ?string $actorIdentifier = null,
        ?array $metadata = null,
    ): ?Notification {
        // Task 8: preferences foundation — a user who turned this type off
        // simply never gets a row for it. Every user before this phase (and
        // every type never explicitly toggled) has no preference recorded,
        // so wantsNotification() defaults to true and nothing changes for
        // them.
        if (!$user->wantsNotification($type)) {
            return null;
        }

        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'severity' => $severity,
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'actor_type' => $actorType,
            'actor_identifier' => $actorIdentifier,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Fan-out to every Admin — the audience for agent/server/GitHub/
     * security operational events, since managing those is Admin-only in
     * the existing PermissionMatrix (agents.approve, github.connect,
     * servers.rotate-credentials, users.manage all resolve to Admin-only
     * via the '*' wildcard — no non-admin role grants any of them).
     */
    public function notifyAdmins(
        string $type,
        string $title,
        string $message,
        string $severity,
        ?Model $related = null,
        ?string $actorType = null,
        ?string $actorIdentifier = null,
        ?array $metadata = null,
    ): void {
        User::where('role', User::ROLE_ADMIN)->get()->each(
            fn (User $admin) => $this->notify($admin, $type, $title, $message, $severity, $related, $actorType, $actorIdentifier, $metadata)
        );
    }
}
