<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\PublishJob;
use App\Services\Publish\PublishJobService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 5: One-Click Publish — the dashboard side of the flow described
 * in PublishJobService's docblock. `local_project_mapping_id` identifies
 * which Local Agent to target when a Project has more than one mapping
 * (e.g. two developers' machines); when there is exactly one, the
 * project show view submits it automatically.
 */
class PublishController extends Controller
{
    public function __construct(
        private readonly PublishJobService $publishJobs,
    ) {
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('local_agents.publish');

        $mapping = $this->resolveMapping($request, $project);

        $job = $this->publishJobs->queue($mapping->localAgent, $project, PublishJob::ACTION_STATUS_CHECK, $request->user()->email);

        return redirect()->route('projects.publish.show', [$project, $job])
            ->with('status', 'در حال بررسی تغییرات محلی — کارگزار به‌زودی وضعیت را گزارش می‌کند.');
    }

    public function approve(Request $request, Project $project, PublishJob $publishJob): RedirectResponse
    {
        Gate::authorize('local_agents.publish');

        abort_unless($publishJob->project_id === $project->id, 404);

        $validated = $request->validate([
            'commit_message' => ($publishJob->files_changed_count === 0 ? 'nullable' : 'required') . '|string|max:500',
        ]);

        $this->publishJobs->approve($publishJob, $validated['commit_message'] ?? null, $request->user()->email);

        return redirect()->route('projects.publish.show', [$project, $publishJob])
            ->with('status', 'انتشار تایید شد — کارگزار به‌زودی commit و push را انجام می‌دهد.');
    }

    public function refreshReviewEvidence(Request $request, Project $project, PublishJob $publishJob): RedirectResponse
    {
        Gate::authorize('local_agents.manage');
        abort_unless($publishJob->project_id === $project->id, 404);

        $this->publishJobs->refreshNoSourceChangeReviewEvidence($publishJob, $request->user()->email);

        return redirect()->route('projects.publish.show', [$project, $publishJob])
            ->with('status', 'Review evidence refreshed. Explicit approval is still required.');
    }

    public function rollback(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('local_agents.publish');

        $mapping = $this->resolveMapping($request, $project);

        $job = $this->publishJobs->queue($mapping->localAgent, $project, PublishJob::ACTION_ROLLBACK, $request->user()->email);
        $this->publishJobs->approveRollback($job, $request->user()->email);

        return redirect()->route('projects.publish.show', [$project, $job])
            ->with('status', 'درخواست Rollback ثبت شد — کارگزار به‌زودی آخرین commit را revert می‌کند.');
    }

    public function show(Project $project, PublishJob $publishJob)
    {
        Gate::authorize('local_agents.view');

        abort_unless($publishJob->project_id === $project->id, 404);

        // Phase 5.5: PublishJob and Deployment are deliberately independent
        // (a publish only ever pushes — the GitHub webhook is what actually
        // creates a Deployment for a push, completely unrelated code).
        // Root-cause fix (no-source-change publish gap): a publish that
        // succeeded WITHOUT a push has no webhook, so PublishJobService's
        // bridge created exactly one Deployment and correlated it here
        // (deployment_id). For a no-source-change publish the explicit
        // correlation is the ONLY valid display — falling back to a
        // commit-hash guess would surface an unrelated historical
        // deployment for the same SHA (e.g. the old failed attempt that
        // this publish is re-deploying), which is exactly the misleading
        // association this fix exists to eliminate. Push-based publishes
        // have no correlation and keep the read-only commit-hash lookup so
        // the operator sees the webhook-created deployment.
        $resultingDeployment = null;
        if ($publishJob->status === PublishJob::STATUS_SUCCEEDED && $publishJob->result_commit_hash) {
            $resultingDeployment = $publishJob->no_source_change
                ? $publishJob->deployment
                : ($publishJob->deployment
                    ?? Deployment::where('project_id', $project->id)
                        ->where('commit_hash', $publishJob->result_commit_hash)
                        ->latest()
                        ->first());
        }

        // Live evidence validity for an awaiting_approval review: null for
        // every other state, false when the reviewed evidence no longer
        // matches the current LocalProjectMapping (the view then hides the
        // approval button and points the operator at a new review).
        $reviewEvidenceValid = $publishJob->action === PublishJob::ACTION_STATUS_CHECK && $publishJob->status === PublishJob::STATUS_AWAITING_APPROVAL
            ? $this->publishJobs->reviewEvidenceIsStillValid($publishJob)
            : null;

        return view('publish.show', compact('project', 'publishJob', 'resultingDeployment', 'reviewEvidenceValid'));
    }

    /**
     * Phase 5.5: a failed `publish` can fail at the push step after the
     * commit already succeeded locally (see agent.php's publish branch,
     * fixed this phase to skip re-committing when there's nothing new) —
     * Retry just queues the exact same commit message again via the
     * existing queueApprovedPublish(), identical to what an auto-publish
     * or a change-detection approval already does. No new git logic here.
     */
    public function retry(Request $request, Project $project, PublishJob $publishJob): RedirectResponse
    {
        Gate::authorize('local_agents.publish');

        abort_unless($publishJob->project_id === $project->id, 404);
        abort_unless(
            $publishJob->action === PublishJob::ACTION_PUBLISH && in_array($publishJob->status, [
                PublishJob::STATUS_FAILED,
                PublishJob::STATUS_TIMED_OUT,
                PublishJob::STATUS_ABANDONED,
            ], true),
            409,
            'Only a failed, timed-out, or abandoned publish can be retried.'
        );

        $newJob = $this->publishJobs->queueApprovedPublish(
            $publishJob->localAgent,
            $project,
            (string) $publishJob->commit_message,
            $request->user()->email,
            'retry',
            $publishJob->no_source_change ? [
                'head_sha' => $publishJob->reviewed_head_sha,
                'branch' => $publishJob->reviewed_branch,
                'mapping_id' => $publishJob->reviewed_mapping_id,
            ] : null,
        );

        return redirect()->route('projects.publish.show', [$project, $newJob])
            ->with('status', 'تلاش مجدد ثبت شد — کارگزار به‌زودی دوباره تلاش می‌کند.');
    }

    /**
     * Phase 5.4: a cross-project audit view — everything else already
     * shows PublishJobs scoped to one project (publish.show) or one
     * agent (local-agents.show's "recent publishes" table). Rollback
     * eligibility here mirrors exactly what the project page's own
     * Rollback button already assumes (the project's most recent
     * succeeded publish) — no new rollback logic, just precomputed so
     * the view doesn't run a query per row.
     */
    public function history(Request $request)
    {
        Gate::authorize('local_agents.view');

        $publishJobs = PublishJob::with(['project', 'localAgent'])
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $latestSucceededPublishIds = PublishJob::query()
            ->where('action', PublishJob::ACTION_PUBLISH)
            ->where('status', PublishJob::STATUS_SUCCEEDED)
            ->selectRaw('MAX(id) as id')
            ->groupBy('project_id')
            ->pluck('id')
            ->all();

        return view('publish.history', compact('publishJobs', 'latestSucceededPublishIds'));
    }

    private function resolveMapping(Request $request, Project $project): LocalProjectMapping
    {
        $mappings = LocalProjectMapping::where('project_id', $project->id)
            ->whereHas('localAgent', fn ($query) => $query->where('status', 'approved'))
            ->get();

        abort_if($mappings->isEmpty(), 422, 'This project has no approved Local Agent mapped to it yet.');

        if ($mappings->count() === 1) {
            return $mappings->first();
        }

        $validated = $request->validate([
            'local_project_mapping_id' => 'required|exists:local_project_mappings,id',
        ]);

        $mapping = $mappings->firstWhere('id', (int) $validated['local_project_mapping_id']);
        abort_if($mapping === null, 422, 'The selected local agent is not mapped to this project.');

        return $mapping;
    }
}
