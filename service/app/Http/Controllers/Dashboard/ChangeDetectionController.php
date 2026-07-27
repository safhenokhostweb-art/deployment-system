<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\LocalChangeDetectionJob;
use App\Models\Project;
use App\Services\Publish\LocalChangeDetectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 5.2: the dashboard side of a Local Agent's file-watch report —
 * reuses the exact same 'local_agents.publish' permission Phase 5's
 * PublishController already gates manual Publish/Rollback with, since
 * approving/ignoring a detected change is the same trust level as
 * manually triggering a publish.
 */
class ChangeDetectionController extends Controller
{
    public function __construct(
        private readonly LocalChangeDetectionService $changeDetection,
    ) {
    }

    public function show(Project $project, LocalChangeDetectionJob $changeDetection)
    {
        Gate::authorize('local_agents.view');

        abort_unless($changeDetection->project_id === $project->id, 404);

        return view('changes.show', compact('project', 'changeDetection'));
    }

    public function approve(Request $request, Project $project, LocalChangeDetectionJob $changeDetection): RedirectResponse
    {
        Gate::authorize('local_agents.publish');

        abort_unless($changeDetection->project_id === $project->id, 404);

        $validated = $request->validate([
            'commit_message' => 'nullable|string|max:500',
        ]);

        $publishJob = $this->changeDetection->approve($changeDetection, $validated['commit_message'] ?? null, $request->user()->email);

        return redirect()->route('projects.publish.show', [$project, $publishJob])
            ->with('status', 'انتشار تایید شد — کارگزار به‌زودی commit و push را انجام می‌دهد.');
    }

    public function ignore(Request $request, Project $project, LocalChangeDetectionJob $changeDetection): RedirectResponse
    {
        Gate::authorize('local_agents.publish');

        abort_unless($changeDetection->project_id === $project->id, 404);

        $this->changeDetection->ignore($changeDetection, $request->user()->email);

        return redirect()->route('projects.show', $project)->with('status', 'تغییرات نادیده گرفته شد.');
    }
}
