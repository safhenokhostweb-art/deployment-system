<?php

use App\Http\Controllers\Api\AgentJobController;
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
    Route::post('/jobs/{deployment:public_id}/status', [AgentJobController::class, 'updateStatus'])->name('agent.jobs.status');
    Route::post('/jobs/{deployment:public_id}/log', [AgentJobController::class, 'appendLog'])->name('agent.jobs.log');
});
