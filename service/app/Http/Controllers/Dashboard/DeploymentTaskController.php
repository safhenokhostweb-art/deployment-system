<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DeploymentTask;
use App\Services\Deployment\RollbackService;
use App\Services\HealthCheck\HealthCheckService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeploymentTaskController extends Controller
{
    public function __construct(
        private readonly RollbackService $rollbackService,
        private readonly HealthCheckService $healthCheckService,
    ) {
    }

    public function show(DeploymentTask $deploymentTask)
    {
        $deploymentTask->load([
            'deployment.project',
            'deploymentTarget.server',
            'deploymentTarget.environment',
            'agentJobs.agent',
            'agentJobs.logEntries',
            'healthChecks',
        ]);

        return view('deployment-tasks.show', ['task' => $deploymentTask]);
    }

    public function rollback(Request $request, DeploymentTask $deploymentTask): RedirectResponse
    {
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
