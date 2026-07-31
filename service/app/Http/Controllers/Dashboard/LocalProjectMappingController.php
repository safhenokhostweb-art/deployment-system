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

    /**
     * Phase 6 (project-config sync) §3: reduces the manual work of
     * mapping every Project to a Local Agent one form-submission-at-a-
     * time. Fully automatic mapping creation was ruled out — local_path
     * is local-machine-only knowledge the Hub is never told (by design,
     * see agent.php's own docblock), so it cannot be safely guessed for
     * an arbitrary developer/OS. This is the "minimize manual work while
     * preserving correctness" alternative instead: one submission maps
     * several still-unmapped Projects at once, each row pre-filled with
     * an editable default local_path a human reviews/adjusts before
     * submitting — never silently created. Same
     * 'local_agents.manage' gate and same underlying LocalProjectMapping
     * rows as the single-project store() above; no parallel data model.
     */
    public function bulkStore(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        $enabledProjectIds = array_keys($request->input('enabled', []));
        $localPaths = $request->input('local_path', []);
        $gitBranches = $request->input('git_branch', []);
        $remoteNames = $request->input('remote_name', []);

        $createdCount = 0;

        foreach ($enabledProjectIds as $projectId) {
            $project = Project::find($projectId);
            if ($project === null) {
                continue;
            }

            $localPath = trim((string) ($localPaths[$projectId] ?? ''));
            if ($localPath === '') {
                continue; // nothing usable to create a mapping with — skipped, not an error
            }

            if (LocalProjectMapping::where('local_agent_id', $localAgent->id)->where('project_id', $project->id)->exists()) {
                continue; // already mapped since the page was rendered — not this operator's error
            }

            $gitBranch = trim((string) ($gitBranches[$projectId] ?? ''));
            $remoteName = trim((string) ($remoteNames[$projectId] ?? ''));

            LocalProjectMapping::create([
                'local_agent_id' => $localAgent->id,
                'project_id' => $project->id,
                'local_path' => $localPath,
                'git_branch' => $gitBranch !== '' ? $gitBranch : null,
                'remote_name' => $remoteName !== '' ? $remoteName : 'origin',
            ]);
            $createdCount++;
        }

        return redirect()->route('local-agents.show', $localAgent)
            ->with('status', "{$createdCount} project mapping(s) added.");
    }

    /**
     * Phase 6 (project-config sync): lets an Admin correct
     * local_path/remote_name/branch on an existing mapping without
     * deleting and re-creating it — the project itself (project_key)
     * is never re-asked for here, only re-affirmed via the untouched
     * project_id on the mapping row.
     */
    public function update(Request $request, LocalAgent $localAgent, LocalProjectMapping $mapping): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($mapping->local_agent_id === $localAgent->id, 404);

        $validated = $request->validate([
            'local_path' => 'required|string|max:1000',
            'git_branch' => 'nullable|string|max:255',
            'remote_name' => 'nullable|string|max:255',
        ]);

        $mapping->update([
            'local_path' => $validated['local_path'],
            'git_branch' => $validated['git_branch'] ?? null,
            'remote_name' => $validated['remote_name'] ?? 'origin',
        ]);

        return redirect()->route('local-agents.show', $localAgent)
            ->with('status', "Project mapping for «{$mapping->project->name}» updated.");
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
