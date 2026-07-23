<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Support\Notification\NotificationVisibilityFilter;
use App\Support\Notification\RelatedResourceResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Phase 4-J Task 5-6: every method here is scoped to the authenticated
 * user's own notifications only — there is no role/permission check
 * beyond "is this row mine", by design (a personal inbox, not a
 * resource the existing PermissionMatrix/ProjectPolicy system governs).
 * Clicking through to a notification's related resource still goes
 * through that resource's own, unmodified Gate checks on the next page
 * load — this controller never bypasses them.
 */
class NotificationController extends Controller
{
    /**
     * A capped list rather than a paginator — matches every other index
     * page in this app (AgentController::index(), ProjectController::
     * index(), DashboardService's widgets), none of which paginate.
     */
    private const LIST_LIMIT = 50;

    public function index(Request $request, RelatedResourceResolver $resolver, NotificationVisibilityFilter $visibility)
    {
        $user = $request->user();

        $query = $user->notifications();

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }
        if ($request->query('status') === 'unread') {
            $query->unread();
        }

        // Task 3: "own row" alone is not enough — a notification about a
        // Deployment/Project the user is no longer authorized to view must
        // not display its title/message either, not just hide its link.
        // Enforced here, in the backend query result, not left to the view.
        $notifications = $visibility->filterVisible($user, $query->take(self::LIST_LIMIT)->get());

        $relatedUrls = $notifications->mapWithKeys(
            fn (Notification $notification) => [$notification->id => $resolver->urlFor($notification)]
        );

        $types = Notification::query()->where('user_id', $user->id)->distinct()->pluck('type');
        $severities = [
            Notification::SEVERITY_SUCCESS,
            Notification::SEVERITY_INFO,
            Notification::SEVERITY_WARNING,
            Notification::SEVERITY_CRITICAL,
        ];
        $unreadCount = $user->notifications()->unread()->count();

        return view('notifications.index', compact('notifications', 'relatedUrls', 'types', 'severities', 'unreadCount'));
    }

    public function markRead(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        if (!$notification->isRead()) {
            $notification->update(['read_at' => now()]);
        }

        return redirect()->back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->notifications()->unread()->update(['read_at' => now()]);

        return redirect()->route('notifications.index')->with('status', __('messages.flash.notifications_marked_read'));
    }

    public function preferences(Request $request)
    {
        return view('notifications.preferences', ['user' => $request->user()]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $types = array_keys($this->notificationTypeGroups());

        $validated = $request->validate([
            'enabled' => 'array',
            'enabled.*' => 'string|in:' . implode(',', $types),
        ]);

        $enabled = $validated['enabled'] ?? [];
        $preferences = collect($types)->mapWithKeys(fn (string $type) => [$type => in_array($type, $enabled, true)])->all();

        $request->user()->update(['notification_preferences' => $preferences]);

        return redirect()->route('notifications.preferences')->with('status', __('messages.flash.notification_preferences_saved'));
    }

    /**
     * @return array<string, string>
     */
    public static function notificationTypeGroups(): array
    {
        return [
            Notification::TYPE_DEPLOYMENT_STARTED => 'deployment',
            Notification::TYPE_DEPLOYMENT_SUCCESS => 'deployment',
            Notification::TYPE_DEPLOYMENT_FAILED => 'deployment',
            Notification::TYPE_DEPLOYMENT_ROLLBACK => 'deployment',
            Notification::TYPE_DEPLOYMENT_ROLLBACK_STARTED => 'deployment',
            Notification::TYPE_AGENT_CONNECTED => 'agent',
            Notification::TYPE_AGENT_OFFLINE => 'agent',
            Notification::TYPE_AGENT_APPROVAL_REQUIRED => 'agent',
            Notification::TYPE_GITHUB_CONNECTED => 'github',
            Notification::TYPE_GITHUB_FAILED => 'github',
            Notification::TYPE_PERMISSION_CHANGED => 'security',
            Notification::TYPE_CREDENTIAL_ROTATED => 'security',
        ];
    }
}
