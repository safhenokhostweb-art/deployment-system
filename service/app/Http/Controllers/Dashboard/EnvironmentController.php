<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-C.5: management UI for the additive Deployment Domain
 * (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md). Purely a Read/Write layer
 * over the existing Environment model — no new columns, no change to how
 * DeploymentTargetResolver or DeploymentDispatcher behave for a legacy
 * Project with zero Environment rows.
 */
class EnvironmentController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('manage-deployment-topology');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                'unique:environments,key,NULL,id,project_id,' . $project->id,
            ],
        ]);

        $environment = $project->environments()->create([
            'name' => $validated['name'],
            'key' => $validated['key'],
            'status' => Environment::STATUS_ACTIVE,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'environment.created',
            subjectType: Environment::class,
            subjectId: $environment->id,
            metadata: ['project_id' => $project->id],
        );

        return redirect()->route('projects.show', $project)
            ->with('status', __('messages.flash.environment_created', ['name' => $environment->name]));
    }

    public function toggleStatus(Request $request, Environment $environment): RedirectResponse
    {
        Gate::authorize('manage-deployment-topology');

        $newStatus = $environment->status === Environment::STATUS_ACTIVE
            ? Environment::STATUS_DISABLED
            : Environment::STATUS_ACTIVE;

        $environment->update(['status' => $newStatus]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'environment.status_changed',
            subjectType: Environment::class,
            subjectId: $environment->id,
            metadata: ['status' => $newStatus],
        );

        $messageKey = $newStatus === Environment::STATUS_ACTIVE
            ? 'messages.flash.environment_enabled'
            : 'messages.flash.environment_disabled';

        return redirect()->route('projects.show', $environment->project)
            ->with('status', __($messageKey, ['name' => $environment->name]));
    }
}
