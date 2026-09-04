<?php

use App\Http\Controllers\Api\AgentArtifactController;
use App\Http\Controllers\Api\AgentDiscoveryController;
use App\Http\Controllers\Api\AgentJobController;
use App\Http\Controllers\Api\AgentOperationController;
use App\Http\Controllers\Api\AgentRegistrationController;
use App\Http\Controllers\Api\GitHubWebhookController;
use App\Http\Controllers\Api\LocalAgentArtifactController;
use App\Http\Controllers\Api\LocalAgentChangeDetectionController;
use App\Http\Controllers\Api\LocalAgentControlJobController;
use App\Http\Controllers\Api\LocalAgentJobController;
use App\Http\Controllers\Api\LocalAgentProjectSyncController;
use App\Http\Controllers\Api\LocalAgentRegistrationController;
use App\Http\Controllers\Api\LocalAgentWorkspaceInventoryController;
use App\Http\Controllers\Api\ProjectReleaseController;
use App\Http\Controllers\Api\ServerAgentControlJobController;
use App\Http\Controllers\Api\ServerAgentPackageBuildJobController;
use App\Http\Controllers\Api\ServerAgentPackageController;
use Illuminate\Support\Facades\Route;

// GitHub -> Deployment Service. Authenticated by per-project HMAC signature
// (X-Hub-Signature-256), not by session/token, so no auth middleware here.
Route::post('/webhooks/github/{projectKey}', [GitHubWebhookController::class, 'handle'])
    ->name('webhooks.github');
Route::post('/projects/{projectKey}/releases', [ProjectReleaseController::class, 'store'])
    ->middleware('throttle:30,1')
    ->name('projects.releases.store');

// A pending agent has no signing_secret yet, so this cannot sit behind
// 'agent.signed' — it verifies its own, narrower scheme keyed by the
// one-time registration token. See AgentRegistrationController.
Route::post('/agent/register', [AgentRegistrationController::class, 'register'])
    ->name('agent.register');

// Deployment Agent -> Deployment Service. Bearer token + HMAC request
// signature required on every call (see VerifyAgentRequestSignature).
Route::middleware('agent.signed')->prefix('agent')->group(function () {
    Route::post('/heartbeat', [AgentJobController::class, 'heartbeat'])->name('agent.heartbeat');
    Route::get('/jobs/next', [AgentJobController::class, 'next'])->name('agent.jobs.next');
    // Plain {id} rather than implicit {deployment:public_id} model binding
    // — Phase 3-C.2 needs to resolve either a legacy Deployment or a
    // new-domain AgentJob by the same public_id-shaped string, tried in
    // that order inside AgentJobController.
    Route::post('/jobs/{id}/status', [AgentJobController::class, 'updateStatus'])->name('agent.jobs.status');
    Route::post('/jobs/{id}/log', [AgentJobController::class, 'appendLog'])->name('agent.jobs.log');
    // Root-cause fix (sandogh deployment overhaul, Phase 2): a distinct
    // mid-step lease renewal, same rationale as
    // local-agent/publish-jobs/{id}/heartbeat — see AgentJobController::heartbeatJob().
    Route::post('/jobs/{id}/heartbeat', [AgentJobController::class, 'heartbeatJob'])->name('agent.jobs.heartbeat');

    // Phase 4-H: Local Discovery. Strictly opt-in on the agent side (only
    // polled when the agent's own config sets workspace_root) and a no-op
    // for legacy Server-only credentials — see AgentDiscoveryController.
    Route::get('/discovery-jobs/next', [AgentDiscoveryController::class, 'next'])->name('agent.discovery-jobs.next');
    Route::post('/discovery-jobs/{id}/report', [AgentDiscoveryController::class, 'report'])->name('agent.discovery-jobs.report');

    // Phase 4-K: Operations Command Center. Same signed channel, same
    // Agent-identity-only rule as Discovery — see AgentOperationController.
    Route::get('/operation-jobs/next', [AgentOperationController::class, 'next'])->name('agent.operation-jobs.next');
    Route::post('/operation-jobs/{id}/report', [AgentOperationController::class, 'report'])->name('agent.operation-jobs.report');

    // Root-cause fix (customer-site direct-GitHub-dependency removal):
    // downloads an artifact the Local Agent already uploaded — see
    // AgentArtifactController and ArtifactManager on the agent side.
    Route::get('/artifacts/{id}/download', [AgentArtifactController::class, 'download'])->name('agent.artifacts.download');

    // Root-cause fix (Deployment #184 — no channel from the Hub to the
    // Server Agent runtime at all): the Server Agent's own exact-commit
    // self-upgrade contract, deliberately separate from every
    // local-agent/* route above — see ServerAgentControlJobService and
    // ServerAgentPackageController.
    Route::get('/control-jobs/next', [ServerAgentControlJobController::class, 'next'])->name('agent.control-jobs.next');
    Route::post('/control-jobs/{id}/report', [ServerAgentControlJobController::class, 'report'])->name('agent.control-jobs.report');
    Route::get('/server-agent-packages/{id}/download', [ServerAgentPackageController::class, 'download'])->name('agent.server-agent-packages.download');
});

// Phase 5: Local Development Publisher Agent. A pending local agent has
// no signing_secret yet, so registration cannot sit behind
// 'local-agent.signed' — see LocalAgentRegistrationController. Its own
// scheme, own headers, own replay table — fully independent of the
// Server Agent protocol above.
Route::post('/local-agent/register', [LocalAgentRegistrationController::class, 'register'])
    ->name('local-agent.register');

Route::middleware('local-agent.signed')->prefix('local-agent')->group(function () {
    Route::post('/heartbeat', [LocalAgentJobController::class, 'heartbeat'])->name('local-agent.heartbeat');

    // Project-config sync: the agent downloads its assigned projects
    // (project_key/local_path/remote_name/branch) here instead of them
    // being hand-maintained in config.php — see LocalAgentProjectSyncController.
    Route::get('/projects', [LocalAgentProjectSyncController::class, 'index'])->name('local-agent.projects.index');

    Route::get('/publish-jobs/next', [LocalAgentJobController::class, 'next'])->name('local-agent.publish-jobs.next');
    Route::post('/publish-jobs/{id}/report', [LocalAgentJobController::class, 'report'])->name('local-agent.publish-jobs.report');

    // Root-cause fix: a distinct, lightweight heartbeat for the ONE
    // publish job currently running — renews its lease so
    // deployments:recover-stale never mistakes a slow-but-alive command
    // for an abandoned one. Never conflated with /heartbeat above (the
    // agent's general online signal).
    Route::post('/publish-jobs/{id}/heartbeat', [LocalAgentJobController::class, 'heartbeatJob'])->name('local-agent.publish-jobs.heartbeat');

    // Phase 5.2: the file watcher's own report — see agent.php's
    // pollAndDetectChanges() and LocalAgentChangeDetectionController.
    Route::post('/change-detections', [LocalAgentChangeDetectionController::class, 'report'])->name('local-agent.change-detections.report');

    // Phase 2 (Workspace Inventory): the structured, read-only
    // WorkspaceInventoryScanner report — a dedicated channel from
    // /heartbeat (which stays lightweight) and from /change-detections
    // (files-only, no branch/ahead-behind/counts) — see
    // WorkspaceInventoryReporter on the agent side and
    // LocalAgentWorkspaceInventoryController here.
    Route::post('/workspace-inventory', [LocalAgentWorkspaceInventoryController::class, 'report'])->name('local-agent.workspace-inventory.report');

    // Phase 5.3: the Hub's only channel to remotely restart an agent or
    // pull its recent log output — see agent.php's pollAndHandleControl().
    Route::get('/control-jobs/next', [LocalAgentControlJobController::class, 'next'])->name('local-agent.control-jobs.next');
    // Watchdog-only, restart-scoped poll — see nextRestartOnly()'s own docblock.
    Route::get('/control-jobs/next-restart', [LocalAgentControlJobController::class, 'nextRestartOnly'])->name('local-agent.control-jobs.next-restart');
    Route::post('/control-jobs/{id}/report', [LocalAgentControlJobController::class, 'report'])->name('local-agent.control-jobs.report');

    // Root-cause fix (customer-site direct-GitHub-dependency removal):
    // uploaded right after a successful publish, in two signed phases —
    // see LocalAgentArtifactController's own docblock for why — and
    // ArtifactBuilder on the agent side.
    Route::post('/artifacts', [LocalAgentArtifactController::class, 'create'])->name('local-agent.artifacts.create');
    Route::post('/artifacts/{id}/content', [LocalAgentArtifactController::class, 'uploadContent'])->name('local-agent.artifacts.upload-content');

    // Root-cause fix (Deployment #184): the Hub asking this (the only
    // already-trusted, git-capable) Local Agent to materialize one
    // exact-commit Server Agent package — see
    // ServerAgentPackageBuildJobController and
    // LinuxUpgradePackageMaterializer::withPackage() on the agent side.
    Route::get('/server-agent-package-jobs/next', [ServerAgentPackageBuildJobController::class, 'next'])->name('local-agent.server-agent-package-jobs.next');
    Route::post('/server-agent-package-jobs/{id}/report', [ServerAgentPackageBuildJobController::class, 'report'])->name('local-agent.server-agent-package-jobs.report');
});
