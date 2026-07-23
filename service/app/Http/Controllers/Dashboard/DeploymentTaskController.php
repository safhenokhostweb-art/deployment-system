<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Services\Deployment\RollbackService;
use App\Services\HealthCheck\HealthCheckService;
use App\Support\Deployment\PipelineStepPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeploymentTaskController extends Controller
{
    public function __construct(
        private readonly RollbackService $rollbackService,
        private readonly HealthCheckService $healthCheckService,
        private readonly PipelineStepPresenter $steps,
    ) {
    }

    public function show(DeploymentTask $deploymentTask)
    {
        Gate::authorize('deployments.view');
        Gate::authorize('view', $deploymentTask->deployment->project);

        $deploymentTask->load([
            'deployment.project',
            'deploymentTarget.server',
            'deploymentTarget.environment',
            'agentJobs.agent',
            'agentJobs.logEntries',
            'healthChecks',
        ]);

        // Phase 4-I Tasks 3-5: steps for the latest attempt only — a retried
        // task's earlier attempts each keep their own full history further
        // down the page (unchanged), but the step/timeline visualization at
        // the top reflects what's actually happening right now.
        $latestAgentJob = $deploymentTask->agentJobs->last();
        $steps = $latestAgentJob
            ? $this->steps->steps($deploymentTask->deployment->project->pipeline, $latestAgentJob->logEntries, $latestAgentJob->status)
            : [];
        $currentStep = $this->steps->currentStep($steps);

        // Task 8: mirrors RollbackService::rollbackTargetTo()'s own
        // "current commit for this target" computation exactly.
        $currentTargetCommit = $deploymentTask->deploymentTarget->deploymentTasks()
            ->where('status', DeploymentTask::STATUS_SUCCEEDED)
            ->latest('id')
            ->first()
            ?->deployment;

        return view('deployment-tasks.show', ['task' => $deploymentTask, 'steps' => $steps, 'currentStep' => $currentStep, 'currentTargetCommit' => $currentTargetCommit]);
    }

    public function rollback(Request $request, DeploymentTask $deploymentTask): RedirectResponse
    {
        Gate::authorize('deployments.rollback');
        Gate::authorize('rollback', $deploymentTask->deployment->project);

        $rollback = $this->rollbackService->rollbackTargetTo($deploymentTask, $request->user()->email);

        return redirect()->route('deployments.show', $rollback)->with('status', 'Rollback queued for this target.');
    }

    public function runHealthCheck(Request $request, DeploymentTask $deploymentTask): RedirectResponse
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'expected_status' => 'nullable|integer|min:100|max:599',
        ]);

        $this->healthCheckService->run($deploymentTask, 'http_status', [
            'url' => $validated['url'],
            'expected_status' => $validated['expected_status'] ?? 200,
        ]);

        return redirect()->route('deployment-tasks.show', $deploymentTask)->with('status', 'Health check completed.');
    }
}
