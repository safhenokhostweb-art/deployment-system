<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Deployment\RollbackService;
use App\Support\Deployment\PipelineStepPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeploymentController extends Controller
{
    public function __construct(
        private readonly DeploymentDispatcher $dispatcher,
        private readonly RollbackService $rollbackService,
        private readonly PipelineStepPresenter $steps,
    ) {
    }

    public function show(Deployment $deployment)
    {
        Gate::authorize('deployments.view');
        Gate::authorize('view', $deployment->project);

        $deployment->load([
            'project', 'server', 'logEntries', 'rollbackOf',
            'tasks.deploymentTarget.server',
            'tasks.deploymentTarget.environment',
            'tasks.agentJobs.agent',
        ]);

        // Phase 4-I Tasks 3-5: only meaningful for the legacy single-target
        // path, whose pipeline log lives on $deployment->logEntries — a
        // multi-target deployment's per-step truth lives per-task instead
        // (see deployment-tasks.show), so this is simply empty there and
        // the view renders nothing for it.
        $steps = $deployment->tasks->isEmpty()
            ? $this->steps->steps($deployment->project->pipeline, $deployment->logEntries, $deployment->status)
            : [];
        $currentStep = $this->steps->currentStep($steps);

        // Task 8: what a rollback triggered from this page would actually
        // do — the exact same "from/to" RollbackService::rollbackTo()
        // itself computes, shown before the fact instead of only after.
        $currentLive = $deployment->project->latestSuccessfulDeployment();

        return view('deployments.show', compact('deployment', 'steps', 'currentStep', 'currentLive'));
    }

    public function approve(Request $request, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('deployments.create');
        Gate::authorize('deploy', $deployment->project);

        $this->dispatcher->approve($deployment, $request->user()->email);

        return redirect()->route('deployments.show', $deployment)->with('status', 'Deployment approved and queued.');
    }

    public function cancel(Request $request, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('deployments.create');
        Gate::authorize('deploy', $deployment->project);

        $this->dispatcher->cancel($deployment, $request->user()->email);

        return redirect()->route('deployments.show', $deployment)->with('status', 'Deployment cancelled.');
    }

    public function rollback(Request $request, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('deployments.rollback');
        Gate::authorize('rollback', $deployment->project);

        $rollback = $this->rollbackService->rollbackTo($deployment, $request->user()->email);

        return redirect()->route('deployments.show', $rollback)->with('status', 'Rollback queued.');
    }
}
