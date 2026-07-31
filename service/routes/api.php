<?php

use App\Http\Controllers\Api\AgentDiscoveryController;
use App\Http\Controllers\Api\AgentJobController;
use App\Http\Controllers\Api\AgentOperationController;
use App\Http\Controllers\Api\AgentRegistrationController;
use App\Http\Controllers\Api\GitHubWebhookController;
use App\Http\Controllers\Api\LocalAgentChangeDetectionController;
use App\Http\Controllers\Api\LocalAgentControlJobController;
use App\Http\Controllers\Api\LocalAgentJobController;
use App\Http\Controllers\Api\LocalAgentProjectSyncController;
use App\Http\Controllers\Api\LocalAgentRegistrationController;
use Illuminate\Support\Facades\Route;

// GitHub -> Deployment Service. Authenticated by per-project HMAC signature
// (X-Hub-Signature-256), not by session/token, so no auth middleware here.
Route::post('/webhooks/github/{projectKey}', [GitHubWebhookController::class, 'handle'])
    ->name('webhooks.github');

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

    // Phase 4-H: Local Discovery. Strictly opt-in on the agent side (only
    // polled when the agent's own config sets workspace_root) and a no-op
    // for legacy Server-only credentials — see AgentDiscoveryController.
    Route::get('/discovery-jobs/next', [AgentDiscoveryController::class, 'next'])->name('agent.discovery-jobs.next');
    Route::post('/discovery-jobs/{id}/report', [AgentDiscoveryController::class, 'report'])->name('agent.discovery-jobs.report');

    // Phase 4-K: Operations Command Center. Same signed channel, same
    // Agent-identity-only rule as Discovery — see AgentOperationController.
    Route::get('/operation-jobs/next', [AgentOperationController::class, 'next'])->name('agent.operation-jobs.next');
    Route::post('/operation-jobs/{id}/report', [AgentOperationController::class, 'report'])->name('agent.operation-jobs.report');
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

    // Phase 5.2: the file watcher's own report — see agent.php's
    // pollAndDetectChanges() and LocalAgentChangeDetectionController.
    Route::post('/change-detections', [LocalAgentChangeDetectionController::class, 'report'])->name('local-agent.change-detections.report');

    // Phase 5.3: the Hub's only channel to remotely restart an agent or
    // pull its recent log output — see agent.php's pollAndHandleControl().
    Route::get('/control-jobs/next', [LocalAgentControlJobController::class, 'next'])->name('local-agent.control-jobs.next');
    Route::post('/control-jobs/{id}/report', [LocalAgentControlJobController::class, 'report'])->name('local-agent.control-jobs.report');
});
