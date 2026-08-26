<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Releases\ReleaseBackfillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Admin-only bootstrap path: record release metadata for an already-pushed,
 * already-built commit that predates the project ever running the
 * provider-neutral release-preparation tool. See ReleaseBackfillService's
 * own docblock for the trust boundary — commit/base SHA/changed files are
 * always re-derived from GitHub here, never accepted from the request.
 */
class ReleaseBackfillController extends Controller
{
    public function __construct(private readonly ReleaseBackfillService $backfill)
    {
    }

    public function show(Project $project)
    {
        Gate::authorize('releases.backfill');
        Gate::authorize('deploy', $project);

        $state = $this->backfill->preview($project);

        return view('publish.release-backfill', compact('project', 'state'));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('releases.backfill');
        Gate::authorize('deploy', $project);

        $validated = $request->validate([
            'confirmed_checks' => 'array',
            'confirmed_checks.*' => 'string',
            'notes' => 'nullable|string|max:2000',
            'confirm' => 'accepted',
        ]);

        $release = $this->backfill->record(
            $project,
            $validated['confirmed_checks'] ?? [],
            $validated['notes'] ?? null,
            $request->user()->email,
        );

        return redirect()->route('projects.deploy-latest.show', $project)
            ->with('status', $release->isReady()
                ? 'Release metadata recorded — this commit is now READY TO DEPLOY.'
                : 'Release metadata recorded, but it is not yet fully ready — review the missing requirements below.');
    }
}
