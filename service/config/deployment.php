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
    | guard against that). An out-of-date agent just logs it and the
    | developer runs the shipped upgrade.ps1 themselves.
    |
    | Derived from the LOCAL_AGENT_VERSION actually shipped in this release's
    | packaged deployment-local-agent/agent/agent.php — never a separately
    | hand-maintained literal, so a normal release cannot drift from what it
    | ships, and nothing here requires a production .env edit when the
    | Agent's own version bumps. A DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION
    | override remains possible for a genuine emergency but is only honored
    | at or above the shipped version, so a stale override from an old
    | release can never again silently pin the Hub below the version it is
    | actually running — see LocalAgentVersionResolver and
    | tests/Unit/LocalAgentVersionSyncTest.php for the regression guard.
    |
    */
    'latest_local_agent_version' => \App\Support\Deployment\LocalAgentVersionResolver::effective(),

    /*
    |--------------------------------------------------------------------------
    | PublishJob lease (root-cause fix for stuck "running" publishes)
    |--------------------------------------------------------------------------
    |
    | A claimed PublishJob's lease_expires_at is set this many seconds out
    | on claim, then renewed by the agent's own Job heartbeat (a channel
    | distinct from its general online heartbeat — see
    | LocalAgentJobController::heartbeatJob()). Comfortably above the
    | agent's own job_heartbeat_interval_seconds (config.php on the agent
    | side) so one slow-but-alive cycle doesn't expire the lease.
    | publish_lock_ttl_seconds mirrors this for the per-Project publish
    | lock (see Project::isPublishLocked()) with a small safety margin.
    |
    */
    'publish_job_lease_seconds' => (int) env('DEPLOYMENT_PUBLISH_JOB_LEASE_SECONDS', 120),
    'publish_lock_ttl_seconds' => (int) env('DEPLOYMENT_PUBLISH_LOCK_TTL_SECONDS', 150),

    /*
    |--------------------------------------------------------------------------
    | PublishJob hard runtime ceiling
    |--------------------------------------------------------------------------
    |
    | A second, independent safety net on top of the lease above:
    | deployments:recover-stale marks a running PublishJob `timed_out`
    | (not `abandoned`) once it has run longer than this, even if the
    | agent's heartbeats kept arriving on schedule — catching a runaway
    | command the agent's own per-command timeout somehow didn't.
    |
    */
    'publish_job_max_runtime_seconds' => (int) env('DEPLOYMENT_PUBLISH_JOB_MAX_RUNTIME_SECONDS', 1800),

    /*
    |--------------------------------------------------------------------------
    | Publish review TTL (stale awaiting_approval reviews)
    |--------------------------------------------------------------------------
    |
    | A status_check review sitting at `awaiting_approval` is only as good as
    | the evidence it captured (mapping, branch, HEAD, working-tree
    | cleanliness). The approvals guard re-validates that evidence at
    | approval time, and a newer review supersedes older ones — but a review
    | nobody acts on must not stay approvable forever either. This is the
    | ceiling deployments:recover-stale enforces: an awaiting_approval
    | review older than this is finalized as `rejected`
    | (publish_job.review_expired). Deliberately far longer than the
    | running-job lease (publish_job_lease_seconds): a human approval review
    | legitimately stays open far longer than a running git command, but not
    | indefinitely.
    |
    */
    'publish_review_ttl_minutes' => (int) env('DEPLOYMENT_PUBLISH_REVIEW_TTL_MINUTES', 10080),

    /*
    |--------------------------------------------------------------------------
    | Deployment lease (sandogh deployment overhaul, Phase 2)
    |--------------------------------------------------------------------------
    |
    | Mirrors publish_job_lease_seconds/publish_job_max_runtime_seconds above
    | for the separate Deployment/AgentJob domain. A recipe-based deploy
    | legitimately runs far longer than a git publish (npm install, two
    | builds, a migration) — deployment_job_lease_seconds is renewed by the
    | agent's per-step heartbeat tick (see agent/lib/Executor.php), not just
    | on step boundaries, so one slow-but-alive step (a cold-cache `npm ci`)
    | doesn't expire the lease. deployment_job_max_runtime_seconds is the
    | hard ceiling deployments:detect-stalled enforces even if heartbeats
    | kept arriving on schedule; it defaults well above
    | RecipeValidator::DEFAULT_TOTAL_TIMEOUT_SECONDS so a well-behaved
    | recipe's own timeout always fires first, with this as the backstop
    | for a recipe that set an unusually large timeout_seconds of its own.
    |
    */
    'deployment_job_lease_seconds' => (int) env('DEPLOYMENT_JOB_LEASE_SECONDS', 180),
    'deployment_job_max_runtime_seconds' => (int) env('DEPLOYMENT_JOB_MAX_RUNTIME_SECONDS', 10800),

    /*
    |--------------------------------------------------------------------------
    | Artifact-based deployment (customer-site direct-GitHub-dependency removal)
    |--------------------------------------------------------------------------
    |
    | Root cause: the production server (Iran) cannot reach github.com
    | directly (direct connect and SSH on 22/443 both time out after
    | ~135s). The Local Agent — which does have working GitHub access —
    | now builds a `git archive` artifact right after a successful push
    | and uploads it here; the Server Agent downloads it from the Hub
    | instead, a connection it already needs for polling. See
    | ArtifactService and agent/lib/ArtifactManager.php.
    |
    | artifact_retention_count_per_project: how many artifacts (including
    | the newest) ArtifactService::store() keeps per project — enforced
    | immediately on every upload, independent of the scheduled command.
    | artifact_retention_days: how far out expires_at is set at upload
    | time; artifacts:cleanup (scheduled) deletes anything past it.
    |
    */
    'artifact_retention_count_per_project' => (int) env('DEPLOYMENT_ARTIFACT_RETENTION_COUNT', 3),
    'artifact_retention_days' => (int) env('DEPLOYMENT_ARTIFACT_RETENTION_DAYS', 14),
    'artifact_max_size_mb' => (int) env('DEPLOYMENT_ARTIFACT_MAX_SIZE_MB', 500),

    // Versioned, Hub-owned allowlist. Build-request callers cannot supply a
    // repository URL or package path; they can only receive this policy.
    'artifact_dependency_policies' => [
        'customer-site' => [
            // Rollout switch: remains off through mixed-version operation and
            // is enabled only after both capable agents are heartbeating.
            'required' => (bool) env('DEPLOYMENT_CUSTOMER_SITE_DEPENDENCY_PINNING', false),
            'dependencies' => array_map(static fn (array $package): array => [
                'project_id' => 'saas-platform',
                'repository_mapping' => 'saas-platform',
                'branch' => 'main',
                'commit_sha' => '977b88f9009c4b7c881b14334884a0860f717f3a',
                'package_name' => $package[0],
                'source_path' => $package[1],
                'destination_path' => '../saas-platform/' . $package[1],
            ], [
                ['saas-platform/modules-tickets', 'packages/saas-platform/modules/tickets'],
                ['saas-platform/modules-forms', 'packages/saas-platform/modules/forms'],
                ['saas-platform/modules-crm', 'packages/saas-platform/modules/crm'],
                ['saas-platform/modules-seo-advanced', 'packages/saas-platform/modules/seo-advanced'],
                ['saas-platform/core-utils', 'packages/saas-platform/core-utils'],
                ['saas-platform/modules-theme-runtime', 'packages/saas-platform/modules/theme-runtime'],
            ]),
        ],
    ],
    'min_local_agent_version_for_dependency_pinning' => (string) env('DEPLOYMENT_MIN_LOCAL_AGENT_VERSION_FOR_DEPENDENCY_PINNING', '1.5.0'),
    'min_server_agent_version_for_dependency_pinning' => (string) env('DEPLOYMENT_MIN_SERVER_AGENT_VERSION_FOR_DEPENDENCY_PINNING', '1.4.0'),

    /*
    |--------------------------------------------------------------------------
    | Laravel release completeness (permanent fix)
    |--------------------------------------------------------------------------
    |
    | The fail-closed Laravel release gate (agent/lib/ReleaseValidator.php)
    | is enforced by the Server Agent itself, so an artifact-based
    | deployment must never be dispatched to an agent build older than the
    | one that carries that gate. AgentJobService::claimNextJob() fails the
    | deployment at claim time (failure_code = agent_protocol_incompatible)
    | when the server's heartbeated Agent version is missing or below this
    | value.
    |
    */
    'min_server_agent_version_for_artifact' => (string) env('DEPLOYMENT_MIN_SERVER_AGENT_VERSION_FOR_ARTIFACT', '1.2.0'),

    // First Server Agent release that executes and reports the explicit
    // project-level customer-facing HTTP health contract.
    'min_server_agent_version_for_http_health' => (string) env('DEPLOYMENT_MIN_SERVER_AGENT_VERSION_FOR_HTTP_HEALTH', '1.3.0'),

    // First Server Agent release that validates served persistence links and
    // PHP-FPM write access, rolling back before reporting activation success.
    'min_server_agent_version_for_persistent_runtime' => (string) env('DEPLOYMENT_MIN_SERVER_AGENT_VERSION_FOR_PERSISTENT_RUNTIME', '1.3.4'),

    // SaaS persistence has a narrower shared/runtime permission contract than
    // the customer-site implementation introduced in 1.3.4. Keep this gate
    // project-specific so existing customer-site deployments remain valid
    // while saas-platform fails closed until the matching helper is active.
    'min_server_agent_version_for_saas_persistent_runtime' => (string) env('DEPLOYMENT_MIN_SERVER_AGENT_VERSION_FOR_SAAS_PERSISTENT_RUNTIME', '1.3.5'),

    // Root-cause fix (Deployment #183/#184): the first Server Agent
    // release that correctly gives before_activation maintenance and
    // artifact activation their own log sequences and fails closed when
    // a required before_activation step fails (agent/lib/PipelineExecutor.php,
    // commit bcc7670f). Deliberately defaulted to the CURRENT '1.4.0'
    // baseline, not '1.4.1', and deliberately NOT yet consulted by any
    // deploy-blocking gate (see AgentJobService/AgentTaskService — no
    // call site references this key) — activating it as an actual block
    // before every existing 1.4.0 Server Agent has had the chance to
    // complete the one-time exact-commit self-upgrade would recreate
    // exactly the kind of unrecoverable bootstrap deadlock this whole
    // mechanism exists to avoid. See
    // Agent::enforcesPreActivationMaintenanceOrdering() for the
    // capability check this key backs; wiring it into the deploy-claim
    // path is a deliberate, separate follow-up once the transition is
    // confirmed safe in production.
    'min_server_agent_version_for_pre_activation_maintenance' => (string) env('DEPLOYMENT_MIN_SERVER_AGENT_VERSION_FOR_PRE_ACTIVATION_MAINTENANCE', '1.4.1'),

    /*
    |--------------------------------------------------------------------------
    | Laravel release completeness — Local Agent minimum (permanent fix)
    |--------------------------------------------------------------------------
    |
    | Artifacts must be produced by a Local Agent build that embeds the
    | in-artifact release manifest (.deployment/release-manifest.json),
    | runs the build-time vendor/build gate, and resolves input only from an
    | isolated source workspace. LocalAgentArtifactController rejects uploads
    | from an older/unknown Local Agent (fail-closed).
    |
    */
    'min_local_agent_version_for_artifact' => (string) env('DEPLOYMENT_MIN_LOCAL_AGENT_VERSION_FOR_ARTIFACT', '1.4.7'),

    // Current Local Agent release required for assigned artifact uploads.
    // This gate tracks the shipped Agent version; unassigned/manual artifact
    // uploads remain governed by the older release-manifest minimum above.
    'min_local_agent_version_for_assigned_artifact' => (string) env('DEPLOYMENT_MIN_LOCAL_AGENT_VERSION_FOR_ASSIGNED_ARTIFACT', '1.5.4'),

    // First Agent protocol that fetches and verifies an exact-commit Linux
    // upgrade package. Older capable Agents use the explicit bootstrap mode.
    'min_local_agent_version_for_exact_commit_upgrade' => (string) env('DEPLOYMENT_MIN_LOCAL_AGENT_VERSION_FOR_EXACT_COMMIT_UPGRADE', '1.5.2'),

    // Root-cause fix (commit 9f7adfd shipped the Server Agent
    // package-build poller and materializer without a matching Local
    // Agent version bump, leaving a real, already-queued
    // ServerAgentPackageBuildJob unclaimable by every existing 1.5.4
    // builder): first Local Agent release that polls
    // /api/local-agent/server-agent-package-jobs/next and reports
    // capabilities['server_agent_package_build']. See
    // LocalAgent::supportsServerAgentPackageBuild() for the version +
    // capability check this key backs, and
    // ServerAgentControlJobService::queueUpgrade() for the fail-closed
    // gate that consults it before queueing a new build job — never
    // consulted by LocalAgentControlJobService::queueUpgrade() itself,
    // so a stale builder can always still be offered its own exact-commit
    // self-upgrade to reach this version in the first place.
    'min_local_agent_version_for_server_agent_package_build' => (string) env('DEPLOYMENT_MIN_LOCAL_AGENT_VERSION_FOR_SERVER_AGENT_PACKAGE_BUILD', '1.5.5'),

    // A production artifact must be built from fresh workspace evidence
    // reported by the one authoritative Agent configured on the Project.
    'artifact_workspace_report_max_age_seconds' => (int) env('DEPLOYMENT_ARTIFACT_WORKSPACE_REPORT_MAX_AGE_SECONDS', 300),

];
