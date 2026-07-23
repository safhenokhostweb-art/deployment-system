<?php

use App\Http\Controllers\Api\AgentDiscoveryController;
use App\Http\Controllers\Api\AgentJobController;
use App\Http\Controllers\Api\AgentOperationController;
use App\Http\Controllers\Api\AgentRegistrationController;
use App\Http\Controllers\Api\GitHubWebhookController;
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
