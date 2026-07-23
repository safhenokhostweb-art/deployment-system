<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-C.5. Read/Write layer only — DeploymentTargetResolver already
 * enforces "an inactive target is never used for a deploy" via its
 * existing ->where('is_active', true) filter (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md),
 * so that rule needed no new code here, only a UI for the is_active
 * column that already existed.
 */
class DeploymentTargetController extends Controller
{
    /**
     * One Server can be checked more than once across different
     * Environments/Targets — deliberately allowed, since a single VPS
     * legitimately hosts multiple projects/environments in this app's
     * model. Creates one DeploymentTarget per selected server, named
     * after the server (de-duplicated within the environment).
     */
    public function store(Request $request, Environment $environment): RedirectResponse
    {
        Gate::authorize('manage-deployment-topology');

        $validated = $request->validate([
            'server_ids' => 'required|array|min:1',
            'server_ids.*' => 'exists:servers,id',
        ]);

        $existingNames = $environment->deploymentTargets()->pluck('name')->all();
        $created = [];

        foreach ($validated['server_ids'] as $serverId) {
            $server = Server::findOrFail($serverId);
            $name = $this->uniqueTargetName($server->name, $existingNames);
            $existingNames[] = $name;

            $target = $environment->deploymentTargets()->create([
                'server_id' => $server->id,
                // Informational only — AgentTaskService claims by
                // server_id, not this column (confirmed in the Task 1
                // audit). Set only when unambiguous, purely so the Target
                // list has something meaningful to show in Task 5's
                // "Agent" column; never used to route execution.
                'agent_id' => $this->soleApprovedAgentFor($server),
                'name' => $name,
                'is_active' => true,
            ]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $request->user()->email,
                action: 'deployment_target.created',
                subjectType: DeploymentTarget::class,
                subjectId: $target->id,
                metadata: ['environment_id' => $environment->id, 'server_id' => $server->id],
            );

            $created[] = $target;
        }

        return redirect()->route('projects.show', $environment->project)
            ->with('status', __('messages.flash.targets_created', ['count' => count($created)]));
    }

    public function toggleStatus(Request $request, DeploymentTarget $deploymentTarget): RedirectResponse
    {
        Gate::authorize('manage-deployment-topology');

        $deploymentTarget->update(['is_active' => !$deploymentTarget->is_active]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'deployment_target.status_changed',
            subjectType: DeploymentTarget::class,
            subjectId: $deploymentTarget->id,
            metadata: ['is_active' => $deploymentTarget->is_active],
        );

        $messageKey = $deploymentTarget->is_active
            ? 'messages.flash.target_enabled'
            : 'messages.flash.target_disabled';

        return redirect()->route('projects.show', $deploymentTarget->environment->project)
            ->with('status', __($messageKey, ['name' => $deploymentTarget->name]));
    }

    public function destroy(Request $request, DeploymentTarget $deploymentTarget): RedirectResponse
    {
        Gate::authorize('manage-deployment-topology');

        // deployment_targets.id is restrictOnDelete()'d by deployment_tasks
        // at the schema level (2026_07_19_100000_create_deployment_domain_tables.php)
        // — this check exists to turn that into a friendly, translated
        // redirect instead of a raw QueryException, not to duplicate a
        // safety the database doesn't already guarantee.
        abort_if(
            $deploymentTarget->deploymentTasks()->exists(),
            409,
            __('messages.errors.target_has_deployment_history')
        );

        $project = $deploymentTarget->environment->project;
        $name = $deploymentTarget->name;

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'deployment_target.deleted',
            subjectType: DeploymentTarget::class,
            subjectId: $deploymentTarget->id,
            metadata: ['environment_id' => $deploymentTarget->environment_id],
        );

        $deploymentTarget->delete();

        return redirect()->route('projects.show', $project)
            ->with('status', __('messages.flash.target_deleted', ['name' => $name]));
    }

    private function uniqueTargetName(string $base, array $existingNames): string
    {
        if (!in_array($base, $existingNames, true)) {
            return $base;
        }

        $suffix = 2;
        while (in_array("{$base} ({$suffix})", $existingNames, true)) {
            $suffix++;
        }

        return "{$base} ({$suffix})";
    }

    private function soleApprovedAgentFor(Server $server): ?int
    {
        $approvedAgentIds = Agent::where('server_id', $server->id)
            ->where('status', Agent::STATUS_APPROVED)
            ->pluck('id');

        return $approvedAgentIds->count() === 1 ? $approvedAgentIds->first() : null;
    }
}
