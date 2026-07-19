<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\DeploymentTask;

class DeploymentTaskController extends Controller
{
    public function show(DeploymentTask $deploymentTask)
    {
        $deploymentTask->load([
            'deployment.project',
            'deploymentTarget.server',
            'deploymentTarget.environment',
            'agentJobs.agent',
            'agentJobs.logEntries',
        ]);

        return view('deployment-tasks.show', ['task' => $deploymentTask]);
    }
}
