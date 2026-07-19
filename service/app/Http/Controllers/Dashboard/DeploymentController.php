<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Deployment\RollbackService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeploymentController extends Controller
{
    public function __construct(
        private readonly DeploymentDispatcher $dispatcher,
        private readonly RollbackService $rollbackService,
    ) {
    }

    public function show(Deployment $deployment)
    {
        $deployment->load([
            'project', 'server', 'logEntries', 'rollbackOf',
            'tasks.deploymentTarget.server',
            'tasks.deploymentTarget.environment',
            'tasks.agentJobs.agent',
        ]);

        return view('deployments.show', compact('deployment'));
    }

    public function approve(Request $request, Deployment $deployment): RedirectResponse
    {
        $this->dispatcher->approve($deployment, $request->user()->email);

        return redirect()->route('deployments.show', $deployment)->with('status', 'Deployment approved and queued.');
    }

    public function cancel(Request $request, Deployment $deployment): RedirectResponse
    {
        $this->dispatcher->cancel($deployment, $request->user()->email);

        return redirect()->route('deployments.show', $deployment)->with('status', 'Deployment cancelled.');
    }

    public function rollback(Request $request, Deployment $deployment): RedirectResponse
    {
        $rollback = $this->rollbackService->rollbackTo($deployment, $request->user()->email);

        return redirect()->route('deployments.show', $rollback)->with('status', 'Rollback queued.');
    }
}
