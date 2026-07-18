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

];
