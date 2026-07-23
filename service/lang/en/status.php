<?php

return [
    // Deployment::STATUS_* / DeploymentTask::STATUS_* / AgentJob::STATUS_*
    'pending_approval' => 'Pending approval',
    'queued' => 'Queued',
    'running' => 'Running',
    'succeeded' => 'Succeeded',
    'failed' => 'Failed',
    'cancelled' => 'Cancelled',
    'partial_success' => 'Partial success',
    // Phase 4-I pipeline step status only (PipelineStepPresenter) — a step
    // the run stopped short of reaching, distinct from "pending" (which
    // still might run).
    'skipped' => 'Skipped',

    // Agent::STATUS_*
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'disabled' => 'Disabled',

    // Health check status
    'pending' => 'Pending',
    'success' => 'Success',

    // Generic words reused across pages
    'online' => 'Online',
    'offline' => 'Offline',
    'active' => 'Active',
    'never' => 'Never',
    'yes' => 'Yes',
    'no' => 'No',

    // Phase 4-L Readiness page
    'connected' => 'Connected',
    'not_connected' => 'Not connected',
    'ready' => 'Ready',
    'not_ready' => 'Not ready',
    'configured' => 'Configured',
    'not_configured' => 'Not configured',
    'no_agent' => 'No agent',
];
