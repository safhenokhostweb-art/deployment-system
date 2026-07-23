<?php

namespace App\Http\Controllers\Dashboard;

use App\Events\PermissionChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-F Task 3 ("مدیریت کاربران" is an Admin-only capability) + Task 8
 * (role changes must be audit-logged). Deliberately minimal — a list and
 * one role-change action, not a full user CRUD suite, since nothing else
 * was asked for this phase.
 */
class UserController extends Controller
{
    public function index()
    {
        Gate::authorize('users.manage');

        $users = User::latest()->get();

        return view('users.index', compact('users'));
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('users.manage');

        $validated = $request->validate([
            'role' => 'required|string|in:' . implode(',', [
                User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_DEVELOPER, User::ROLE_VIEWER,
            ]),
        ]);

        $previousRole = $user->role;
        $user->update(['role' => $validated['role']]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'user.role_changed',
            subjectType: User::class,
            subjectId: $user->id,
            metadata: ['from' => $previousRole, 'to' => $validated['role'], 'target_user' => $user->email],
        );

        // Phase 4-J: pure instrumentation for NotificationEventSubscriber.
        event(new PermissionChanged($user, $previousRole, $validated['role'], $request->user()->email));

        return redirect()->route('users.index')
            ->with('status', __('messages.flash.user_role_changed', ['name' => $user->name, 'role' => __('ui.users.role_' . $validated['role'])]));
    }
}
