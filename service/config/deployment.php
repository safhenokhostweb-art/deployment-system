<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agent liveness
    |--------------------------------------------------------------------------
    |
    | A server is considered offline once its last authenticated call (poll,
    | heartbeat, log, or status update) is older than this many seconds.
    | Should comfortably exceed the agent's poll_interval_seconds so a
    | single slow cycle doesn't flip a healthy server to "offline".
    |
    */
    'server_offline_threshold_seconds' => (int) env('DEPLOYMENT_SERVER_OFFLINE_THRESHOLD_SECONDS', 90),

    /*
    |--------------------------------------------------------------------------
    | Stalled deployment detection
    |--------------------------------------------------------------------------
    |
    | A deployment stuck in "running" with no reported activity (no log
    | line, no status update) for this many minutes is assumed to belong to
    | an agent that crashed or lost connectivity mid-job, and is marked
    | failed by the deployments:detect-stalled scheduled command.
    |
    */
    'stalled_deployment_timeout_minutes' => (int) env('DEPLOYMENT_STALLED_TIMEOUT_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Replay-protection nonce retention
    |--------------------------------------------------------------------------
    |
    | Nonces only need to be kept as long as the request-signing timestamp
    | window (see VerifyAgentRequestSignature::MAX_CLOCK_SKEW_SECONDS, 300s)
    | could still accept them. This is set well above that window purely as
    | a safety margin, and pruned by the agent-nonces:prune command so the
    | table doesn't grow without bound.
    |
    */
    'nonce_retention_hours' => (int) env('DEPLOYMENT_NONCE_RETENTION_HOURS', 2),

    /*
    |--------------------------------------------------------------------------
    | Log & audit retention
    |--------------------------------------------------------------------------
    |
    | deployment-system:prune-logs (daily) purges the *output* of old,
    | finished deployment log entries — the step name, exit code, and
    | duration are kept for history, only the potentially large/sensitive
    | raw command output is dropped. Audit log entries are kept far longer
    | by default since they're the compliance/forensic trail.
    |
    */
    'deployment_log_output_retention_days' => (int) env('DEPLOYMENT_LOG_OUTPUT_RETENTION_DAYS', 90),
    'audit_log_retention_days' => (int) env('DEPLOYMENT_AUDIT_LOG_RETENTION_DAYS', 365),

    /*
    |--------------------------------------------------------------------------
    | Agent registration
    |--------------------------------------------------------------------------
    |
    | How long a one-time registration token (issued when an operator starts
    | registering a new agent for a Server) remains valid before it must be
    | reissued. Short by design — see docs/PHASE_3B_AGENT_IDENTITY_AUDIT.md §4.
    |
    */
    'agent_registration_token_ttl_minutes' => (int) env('DEPLOYMENT_AGENT_REGISTRATION_TOKEN_TTL_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | DeploymentTask retry policy (new domain only)
    |--------------------------------------------------------------------------
    |
    | Off by default (max attempts = 1, i.e. no auto-retry) — a deployment
    | pipeline (git reset + migrate + ...) is not guaranteed idempotent, so
    | blindly re-running a half-applied migration could do real harm. Set
    | above 1 to opt in; backoff seconds are consumed in order, then the
    | last value repeats for any further attempt. Legacy (single-target)
    | deployments are entirely unaffected by this — they have no concept
    | of attempts beyond the one AgentJobService already provides.
    |
    */
    'task_max_attempts' => (int) env('DEPLOYMENT_TASK_MAX_ATTEMPTS', 1),
    'task_retry_backoff_seconds' => array_map('intval', explode(',', (string) env('DEPLOYMENT_TASK_RETRY_BACKOFF_SECONDS', '30,120,300'))),

    /*
    |--------------------------------------------------------------------------
    | Auto Mode publish stability window (Phase 5.2)
    |--------------------------------------------------------------------------
    |
    | A LocalChangeDetectionJob on an 'auto' mode mapping is only turned into
    | an auto-approved PublishJob once it has gone this many minutes with no
    | newer report — i.e. the developer appears to have stopped editing.
    | Checked by the publish:auto-publish-stable-changes scheduled command,
    | matching the spec's own "wait ~5 minutes" example.
    |
    */
    'auto_publish_stability_minutes' => (int) env('DEPLOYMENT_AUTO_PUBLISH_STABILITY_MINUTES', 5),

    /*
    |--------------------------------------------------------------------------
    | Local Agent version check (Phase 5.3)
    |--------------------------------------------------------------------------
    |
    | Check-and-notify only, never auto-download/replace code (a compromised
    | or MITM'd Hub could otherwise push arbitrary code to every developer
    | machine, and there is no code-signing infrastructure in this project to
    | guard against that). Bump this whenever a new local-agent/ release is
    | cut; an out-of-date agent just logs it and the developer runs the
    | shipped upgrade.ps1 themselves. Must match LOCAL_AGENT_VERSION in
    | local-agent/agent.php at release time.
    |
    */
    'latest_local_agent_version' => env('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION', '1.1.0'),

];
