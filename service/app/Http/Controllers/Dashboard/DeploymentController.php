<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Deployment\RetryDeploymentService;
use App\Services\Deployment\RollbackService;
use App\Support\Deployment\FailureCodeGuidance;
use App\Support\Deployment\PipelineStepPresenter;
use App\Support\Deployment\RecipeStepPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeploymentController extends Controller
{
    public function __construct(
        private readonly DeploymentDispatcher $dispatcher,
        private readonly RetryDeploymentService $retryService,
        private readonly RollbackService $rollbackService,
        private readonly PipelineStepPresenter $steps,
        private readonly RecipeStepPresenter $recipeSteps,
    ) {
    }

    public function show(Deployment $deployment)
    {
        Gate::authorize('deployments.view');
        Gate::authorize('view', $deployment->project);

        $deployment->load([
            'project', 'server', 'artifact', 'logEntries', 'rollbackOf',
            'tasks.deploymentTarget.server',
            'tasks.deploymentTarget.environment',
            'tasks.agentJobs.agent',
        ]);

        // Root-cause fix (sandogh deployment overhaul, Phase 4): a
        // recipe-driven Project's steps come from deployment_recipe, not
        // pipeline, and its log entries carry an explicit status/
        // failure_code the legacy presenter doesn't know about. Every
        // Project without a recipe (every Project that existed before
        // this phase) is completely unaffected — usesDeploymentRecipe()
        // is false and this falls straight through to the exact
        // Phase 4-I behavior as before.
        $usesRecipe = $deployment->project->usesDeploymentRecipe();
        $steps = match (true) {
            $usesRecipe => $this->recipeSteps->steps($deployment->project->deployment_recipe['steps'], $deployment->logEntries, $deployment->status),
            $deployment->tasks->isEmpty() => $this->steps->steps($deployment->project->pipeline, $deployment->logEntries, $deployment->status),
            default => [],
        };
        $currentStep = $usesRecipe ? $this->recipeSteps->currentStep($steps) : $this->steps->currentStep($steps);

        // Task 8: what a rollback triggered from this page would actually
        // do — the exact same "from/to" RollbackService::rollbackTo()
        // itself computes, shown before the fact instead of only after.
        $currentLive = $deployment->project->latestSuccessfulDeployment();

        return view('deployments.show', compact('deployment', 'steps', 'currentStep', 'currentLive', 'usesRecipe'));
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

    public function retry(Request $request, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('deployments.create');
        Gate::authorize('deploy', $deployment->project);

        $this->retryService->retry($deployment, $request->user()->email);

        return redirect()->route('deployments.show', $deployment)->with('status', 'Deployment requeued. The agent will pick it up on its next poll.');
    }

    public function rollback(Request $request, Deployment $deployment): RedirectResponse
    {
        Gate::authorize('deployments.rollback');
        Gate::authorize('rollback', $deployment->project);

        $rollback = $this->rollbackService->rollbackTo($deployment, $request->user()->email);

        return redirect()->route('deployments.show', $rollback)->with('status', 'Rollback queued.');
    }
}
