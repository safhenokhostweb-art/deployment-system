<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 5: which local checkout on a developer's machine a Local Agent
 * should treat as a given Hub Project — the input the Publish flow needs
 * to know where to run git commands. Gated the same as approving/
 * disabling a Local Agent ('local_agents.manage') since a mapping grants
 * that agent the ability to publish into a specific Project.
 */
class LocalProjectMappingController extends Controller
{
    public function store(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'local_path' => 'required|string|max:1000',
            'git_branch' => 'nullable|string|max:255',
            'remote_name' => 'nullable|string|max:255',
        ]);

        $project = Project::findOrFail($validated['project_id']);

        abort_if(
            LocalProjectMapping::where('local_agent_id', $localAgent->id)->where('project_id', $project->id)->exists(),
            409,
            "This local agent is already mapped to «{$project->name}»."
        );

        LocalProjectMapping::create([
            'local_agent_id' => $localAgent->id,
            'project_id' => $project->id,
            'local_path' => $validated['local_path'],
            'git_branch' => $validated['git_branch'] ?? null,
            'remote_name' => $validated['remote_name'] ?? 'origin',
        ]);

        return redirect()->route('local-agents.show', $localAgent)
            ->with('status', "Project mapping for «{$project->name}» added.");
    }

    public function destroy(LocalAgent $localAgent, LocalProjectMapping $mapping): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($mapping->local_agent_id === $localAgent->id, 404);

        $mapping->delete();

        return redirect()->route('local-agents.show', $localAgent)->with('status', 'Project mapping removed.');
    }

    /**
     * Phase 5.2 §9: Manual/Approval/Auto — how a detected local change
     * on this specific agent+project pair is handled. Same
     * 'local_agents.manage' gate as creating the mapping itself.
     */
    public function updateDeploymentMode(Request $request, LocalAgent $localAgent, LocalProjectMapping $mapping): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($mapping->local_agent_id === $localAgent->id, 404);

        $validated = $request->validate([
            'deployment_mode' => 'required|string|in:' . implode(',', [
                LocalProjectMapping::MODE_MANUAL,
                LocalProjectMapping::MODE_APPROVAL,
                LocalProjectMapping::MODE_AUTO,
            ]),
        ]);

        $mapping->update(['deployment_mode' => $validated['deployment_mode']]);

        return redirect()->route('local-agents.show', $localAgent)->with('status', 'حالت انتشار به‌روزرسانی شد.');
    }
}
