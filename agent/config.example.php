<?php

/**
 * Copy this file to config.php and fill in real values.
 * config.php must NEVER be committed to git or shipped in any repo image —
 * it holds this server's credentials. Keep it out of version control
 * (already covered by .gitignore) and restrict it to 0600 permissions.
 *
 * All values below are placeholders. Nothing here is a real server,
 * host, credential, or path.
 */

return [
    // Base URL of the Deployment Service this agent reports to.
    // Legacy installs may keep `hub_url` (the predecessor Local-Agent key) —
    // agent.php falls back to it when this canonical key is absent, so an
    // official in-place upgrade that preserves config.php never breaks an
    // existing installation. Canonical keys always win when both are present.
    'service_base_url' => 'https://CHANGE-ME.example.invalid',

    // Identifier for this server, issued by the Deployment Service when
    // the server was registered in its dashboard.
    // New Agent-authenticated installs use the approved Agent public_id.
    // Legacy installs may keep `server_id` (Server-agent installs) or
    // `local_agent_id` (Local-Agent installs) temporarily — agent.php falls
    // back to them, in that order, so an official in-place upgrade that
    // preserves config.php never breaks an existing installation. Those
    // legacy identities cannot claim DeploymentTasks; rotate this key to
    // the approved Agent public_id at the next opportunity.
    'agent_id' => 'CHANGE_ME_AGENT_PUBLIC_ID',

    // Bearer token issued by the Deployment Service for this server.
    // Used for authentication (identifies the caller).
    'agent_token' => 'CHANGE_ME_AGENT_TOKEN',

    // Shared HMAC secret issued by the Deployment Service for this server.
    // Used for request/response signing (proves the caller/payload was not
    // tampered with). Deliberately a separate secret from agent_token so a
    // leak of one does not compromise the other.
    'signing_secret' => 'CHANGE_ME_SIGNING_SECRET',

    // How often (seconds) to poll the service for new jobs.
    'poll_interval_seconds' => 15,

    // HTTP timeout for each request to the service.
    'request_timeout_seconds' => 10,

    // Max seconds a single pipeline step may run before being killed.
    'default_step_timeout_seconds' => 900,

    // Local filesystem mapping. The service only ever refers to a project
    // by its project_key — it never knows or stores this server's paths.
    // This keeps all server-specific filesystem/layout information local
    // to the server it describes, instead of centralized and hardcoded.
    'projects' => [
        // 'project_key' => [
        //     'working_directory' => '/home/example/public_html',
        //     'php_binary' => 'php',
        //
        //     // --- deployment_recipe-only settings below (see
        //     // RecipeStepRunner). A project still using the plain
        //     // `pipeline` form never reads any of these. ---
        //
        //     // For a recipe project, working_directory (above) means the
        //     // app ROOT, not a single checkout — the agent creates
        //     // working_directory/releases/<id> and working_directory/current
        //     // under it (see ReleaseManager). Never set by the Hub.
        //
        //     // How many seconds between heartbeat calls to the Hub while a
        //     // single recipe step is still running (renews the
        //     // deployment's lease — see deployments:detect-stalled).
        //     'job_heartbeat_interval_seconds' => 30,
        //
        //     // How many releases (including the currently active one) to
        //     // keep on disk — activate_release deletes anything older.
        //     'release_retention_count' => 5,
        //
        //     // Defense in depth for restart_service/verify_service_active
        //     // (ServiceAllowlist): even a compromised or buggy Hub can
        //     // never make this agent restart a systemd unit outside this
        //     // list, regardless of what a recipe asks for.
        //     'allowed_services' => ['example-api.service', 'example-web.service'],
        //
        //     // --- artifact-deploy-only settings below (see
        //     // ArtifactManager). Only read when the Hub's job payload
        //     // includes an `artifact` — i.e. the Local Agent already
        //     // uploaded one for this exact commit. A project not using
        //     // that flow never reads any of these; the agent runs the
        //     // exact legacy git-based pipeline as before. ---
        //
        //     // Project-relative paths copied from the currently-active
        //     // version into a new release before it's activated — the
        //     // runtime files a fresh `git archive` extraction never
        //     // contains at all (they're gitignored, so never tracked).
        //     // Defaults to ['.env', 'storage', 'public/storage'] when
        //     // omitted (see ArtifactManager::DEFAULT_PRESERVE_PATHS).
        //     'artifact_preserve_paths' => ['.env', 'storage', 'public/storage'],
        //
        //     // --- persistent state (see ArtifactManager::prepare() /
        //     // preparePersistentReferences) ---
        //     //
        //     // A path listed here is NOT copied per release. Instead it
        //     // becomes a symlink inside each staged release pointing at
        //     // {artifact_persistent_root}/{path} — the real runtime data
        //     // (e.g. a separately-mounted database or uploads volume)
        //     // stays outside the swappable release and survives every
        //     // swap/rollback untouched, and the release tree itself stays
        //     // small. A Hub-managed signed artifact_persistence job contract
        //     // takes precedence; these keys remain the compatibility fallback
        //     // for older Hubs. Every configured path must already exist at
        //     // {artifact_persistent_root}/{path} before the first deploy
        //     // (a missing target fails closed — a fresh release must
        //     // never silently start against empty state).
        //     //
        //     // Fail-closed contract (enforced in preparePersistentReferences
        //     // AND by the Hub's project validator, whichever is reached
        //     // first):
        //     //  - relative, non-traversing project paths only (same rules
        //     //    as artifact_preserve_paths);
        //     //  - a persistent path must NOT exactly overlap, contain, or
        //     //    be contained by any effective preserve path (that would
        //     //    be an ambiguous contract: copied AND symlinked);
        //     //  - artifact_persistent_root must be an existing absolute
        //     //    directory (fail-closed if missing).
        //     //
        //     // The runtime-permissions sudo helper normalizes the
        //     // persistent root's entries to www-data alongside the
        //     // in-release runtime directories (see
        //     // install/deployment-runtime-permissions).
        //     // Persist the narrowest runtime-owned path. Persisting a
        //     // source-bearing parent directory fails closed for new
        //     // artifacts whose manifest inventories tracked source files.
        //     // Laravel SQLite example (keeps database/migrations etc. from
        //     // the artifact while sharing only the runtime database file):
        //     // 'artifact_persistent_paths' => ['database/database.sqlite'],
        //     // 'artifact_persistent_root' => '/var/www/deployment-system/persistent',
        //     // Associative mapping is supported when layouts differ:
        //     // ['public/uploads' => 'uploads'].
        //
        //     // How many previous releases (renamed aside as
        //     // `working_directory.backup-*`, never deleted outright) to
        //     // keep on disk before the oldest are cleaned up.
        //     'artifact_retention_count' => 3,
        // ],
    ],

    // Where the agent writes its own operational log (not deployment logs,
    // which are streamed to the service).
    'log_dir' => __DIR__ . '/storage/logs',

    // Phase 4-H, Local Discovery — strictly opt-in. Leave commented out
    // (or empty) to keep this agent behaving exactly as it does today:
    // absent means the discovery poll never runs, no code path is
    // reachable, and nothing changes for legacy agents. Set to an
    // absolute path (e.g. '/home/example/www') to let this agent scan
    // that folder's immediate subdirectories on request and report back
    // only structured metadata — project name/path/type/framework
    // version and sanitized git info. Never reads or sends .env files,
    // passwords, tokens, or SSH keys.
    // 'workspace_root' => '/home/example/www',
];
