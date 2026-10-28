<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace agent runtime
    |--------------------------------------------------------------------------
    |
    | The Visual Agent Management Dashboard drives the workspace agent runtime
    | (agents/bin/agent, agents/agents.json, agents/var/bus/journal.jsonl).
    | It lives next to deployment-system in the workspace, not inside it, so
    | the default resolves <workspace>/agents from this service's base path.
    | On a host without that checkout the dashboard reports the runtime as
    | unavailable instead of failing.
    |
    */
    'runtime_path' => env('AGENT_DASHBOARD_RUNTIME_PATH', dirname(base_path(), 2).DIRECTORY_SEPARATOR.'agents'),

    /*
    | PHP CLI used to start `bin/agent run`. Empty means "the CLI binary next
    | to the PHP running this request". The agent runtime requires PHP 8.4.
    */
    'php_binary' => env('AGENT_DASHBOARD_PHP_BINARY'),

    /*
    | Run state (PID files) and per-run output logs. Hub-owned, never written
    | into the agent runtime's own var/ directory.
    */
    'state_path' => storage_path('app/agent-dashboard'),

    'journal_tail_bytes' => (int) env('AGENT_DASHBOARD_JOURNAL_TAIL_BYTES', 1048576),
    'logs_journal_limit' => 60,
    'artifact_preview_bytes' => 262144,
    'log_tail_bytes' => 65536,
    'kept_run_logs' => 10,

];
