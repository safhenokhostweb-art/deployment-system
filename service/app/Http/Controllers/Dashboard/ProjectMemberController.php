<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-G Task 3/9: who can see/act on a specific Project. Deliberately
 * Admin-only ('projects.manage-members' isn't granted to any other role
 * in the Phase 4-F matrix) — a non-admin cannot grant themselves or
 * anyone else access to a Project, mirroring how only Admin can change a
 * User's global role (Phase 4-F's UserController).
 */
class ProjectMemberController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.manage-members');

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $member = ProjectMember::firstOrCreate([
            'project_id' => $project->id,
            'user_id' => $validated['user_id'],
        ]);

        $addedUser = User::findOrFail($validated['user_id']);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'project_member.added',
            subjectType: Project::class,
            subjectId: $project->id,
            metadata: ['user_id' => $addedUser->id, 'user_email' => $addedUser->email],
        );

        return redirect()->route('projects.show', $project)
            ->with('status', __('messages.flash.project_member_added', ['name' => $addedUser->name]));
    }

    public function destroy(Request $request, Project $project, ProjectMember $member): RedirectResponse
    {
        Gate::authorize('projects.manage-members');

        abort_unless($member->project_id === $project->id, 404);

        $removedUser = $member->user;
        $member->delete();

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'project_member.removed',
            subjectType: Project::class,
            subjectId: $project->id,
            metadata: ['user_id' => $removedUser->id, 'user_email' => $removedUser->email],
        );

        return redirect()->route('projects.show', $project)
            ->with('status', __('messages.flash.project_member_removed', ['name' => $removedUser->name]));
    }
}
