<?php

namespace App\Support\Notification;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\GitHubAccount;
use App\Models\Notification;
use App\Models\OperationJob;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-J Task 3 (hardening): NotificationController previously only
 * checked "is this notification row mine" — a user whose Project
 * membership was later revoked could still see the text of a Notification
 * about a Deployment in that Project, only the click-through link's own
 * Gate check would fail. This closes that gap by re-checking the *current*
 * authorization state of a notification's related resource before it's
 * displayed, reusing the existing PermissionMatrix/ProjectPolicy exactly as
 * they're already used everywhere else in the app — no new Policy.
 *
 * A notification with no related resource (related_type is null) is always
 * visible — it's purely personal, nothing to authorize against.
 */
class NotificationVisibilityFilter
{
    /**
     * @param Collection<int, Notification> $notifications
     * @return Collection<int, Notification>
     */
    public function filterVisible(User $user, Collection $notifications): Collection
    {
        $deployments = $this->loadDeployments($notifications);

        return $notifications->filter(
            fn (Notification $notification) => $this->isVisible($user, $notification, $deployments)
        )->values();
    }

    /**
     * @param Collection<int, Notification> $notifications
     * @return EloquentCollection<int, Deployment>
     */
    private function loadDeployments(Collection $notifications): EloquentCollection
    {
        $ids = $notifications
            ->where('related_type', Deployment::class)
            ->pluck('related_id')
            ->unique();

        if ($ids->isEmpty()) {
            return new EloquentCollection();
        }

        // One batched query for every Deployment-related notification in
        // the list, not one per row — the same "no N+1" concern the header
        // bell's own unread count is held to.
        return Deployment::with('project')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param EloquentCollection<int, Deployment> $deployments
     */
    private function isVisible(User $user, Notification $notification, EloquentCollection $deployments): bool
    {
        if ($notification->related_type === null) {
            return true;
        }

        return match ($notification->related_type) {
            Deployment::class => $this->canViewDeployment($user, $deployments->get($notification->related_id)),
            // Server/Agent/GitHubAccount/User have no per-resource Policy
            // anywhere in this app (Phase 4-G deliberately kept them
            // role-only) — matching that existing convention rather than
            // inventing per-resource authorization that doesn't exist for
            // these types anywhere else.
            Server::class => Gate::forUser($user)->allows('servers.view'),
            Agent::class => Gate::forUser($user)->allows('agents.view'),
            GitHubAccount::class => Gate::forUser($user)->allows('github.view'),
            User::class => Gate::forUser($user)->allows('users.manage'),
            // Phase 4-K: same role-only pattern as Server/Agent above — no
            // per-resource Policy for OperationJob either.
            OperationJob::class => Gate::forUser($user)->allows('operations.view'),
            default => true,
        };
    }

    private function canViewDeployment(User $user, ?Deployment $deployment): bool
    {
        // Resource already deleted since the notification was created —
        // RelatedResourceResolver already renders no link for this case;
        // there's nothing left to authorize against, so the text stays
        // visible (matches existing "deleted resource" handling).
        if ($deployment === null || $deployment->project === null) {
            return true;
        }

        $gate = Gate::forUser($user);

        return $gate->allows('deployments.view') && $gate->allows('view', $deployment->project);
    }
}
