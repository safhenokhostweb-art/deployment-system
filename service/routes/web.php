<?php

use App\Http\Controllers\Dashboard\AgentController;
use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\DeploymentController;
use App\Http\Controllers\Dashboard\DeploymentTargetController;
use App\Http\Controllers\Dashboard\DeploymentTaskController;
use App\Http\Controllers\Dashboard\DiscoveryController;
use App\Http\Controllers\Dashboard\EnvironmentController;
use App\Http\Controllers\Dashboard\GitHubAccountController;
use App\Http\Controllers\Dashboard\NotificationController;
use App\Http\Controllers\Dashboard\OperationController;
use App\Http\Controllers\Dashboard\ProjectController;
use App\Http\Controllers\Dashboard\ProjectMemberController;
use App\Http\Controllers\Dashboard\ReadinessController;
use App\Http\Controllers\Dashboard\ServerController;
use App\Http\Controllers\Dashboard\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard.index');

    Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');
    Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
    Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');
    Route::get('/servers/{server}', [ServerController::class, 'show'])->name('servers.show');
    Route::post('/servers/{server}/rotate-token', [ServerController::class, 'rotateToken'])->name('servers.rotate-token');
    Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
    Route::post('/servers/{server}/agents', [AgentController::class, 'createRegistration'])->name('agents.create-registration');
    Route::post('/servers/{server}/discovery-scans', [DiscoveryController::class, 'store'])->name('discovery.scans.store');

    Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
    Route::get('/agents/pending', [AgentController::class, 'pending'])->name('agents.pending');
    Route::get('/agents/{agent:public_id}', [AgentController::class, 'show'])->name('agents.show');
    Route::post('/agents/{agent:public_id}/approve', [AgentController::class, 'approve'])->name('agents.approve');
    Route::post('/agents/{agent:public_id}/reject', [AgentController::class, 'reject'])->name('agents.reject');
    Route::post('/agents/{agent:public_id}/disable', [AgentController::class, 'disable'])->name('agents.disable');
    Route::post('/agents/{agent:public_id}/reenable', [AgentController::class, 'reenable'])->name('agents.reenable');
    Route::delete('/agents/{agent:public_id}', [AgentController::class, 'destroy'])->name('agents.destroy');

    Route::get('/github/accounts', [GitHubAccountController::class, 'index'])->name('github.accounts.index');
    Route::get('/github/connect', [GitHubAccountController::class, 'connect'])->name('github.connect');
    Route::get('/github/callback', [GitHubAccountController::class, 'callback'])->name('github.callback');
    Route::post('/github/accounts/{account}/disconnect', [GitHubAccountController::class, 'disconnect'])->name('github.accounts.disconnect');
    Route::delete('/github/accounts/{account}', [GitHubAccountController::class, 'destroy'])->name('github.accounts.destroy');
    Route::post('/github/accounts/{account}/test-connection', [GitHubAccountController::class, 'testConnection'])->name('github.accounts.test-connection');
    Route::get('/github/accounts/{account}/repositories', [GitHubAccountController::class, 'repositories'])->name('github.accounts.repositories');
    Route::get('/github/accounts/{account}/branches', [GitHubAccountController::class, 'branches'])->name('github.accounts.branches');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
    Route::post('/projects/{project}/rotate-webhook-secret', [ProjectController::class, 'rotateWebhookSecret'])->name('projects.rotate-webhook-secret');
    Route::post('/projects/{project}/deploy', [ProjectController::class, 'deployManual'])->name('projects.deploy');
    Route::post('/projects/{project}/connect-github', [ProjectController::class, 'connectGithub'])->name('projects.connect-github');

    Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])->name('projects.members.store');
    Route::delete('/projects/{project}/members/{member}', [ProjectMemberController::class, 'destroy'])->name('projects.members.destroy');

    Route::post('/projects/{project}/environments', [EnvironmentController::class, 'store'])->name('environments.store');
    Route::post('/environments/{environment}/toggle-status', [EnvironmentController::class, 'toggleStatus'])->name('environments.toggle-status');

    Route::post('/environments/{environment}/targets', [DeploymentTargetController::class, 'store'])->name('deployment-targets.store');
    Route::post('/deployment-targets/{deploymentTarget}/toggle-status', [DeploymentTargetController::class, 'toggleStatus'])->name('deployment-targets.toggle-status');
    Route::delete('/deployment-targets/{deploymentTarget}', [DeploymentTargetController::class, 'destroy'])->name('deployment-targets.destroy');

    Route::get('/deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
    Route::post('/deployments/{deployment}/approve', [DeploymentController::class, 'approve'])->name('deployments.approve');
    Route::post('/deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])->name('deployments.cancel');
    Route::post('/deployments/{deployment}/rollback', [DeploymentController::class, 'rollback'])->name('deployments.rollback');

    Route::get('/deployment-tasks/{deploymentTask}', [DeploymentTaskController::class, 'show'])->name('deployment-tasks.show');
    Route::post('/deployment-tasks/{deploymentTask}/rollback', [DeploymentTaskController::class, 'rollback'])->name('deployment-tasks.rollback');
    Route::post('/deployment-tasks/{deploymentTask}/health-check', [DeploymentTaskController::class, 'runHealthCheck'])->name('deployment-tasks.health-check');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.update-role');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences'])->name('notifications.preferences');
    Route::post('/notifications/preferences', [NotificationController::class, 'updatePreferences'])->name('notifications.preferences.update');

    Route::get('/operations', [OperationController::class, 'index'])->name('operations.index');
    Route::post('/servers/{server}/operations', [OperationController::class, 'store'])->name('operations.store');
    Route::get('/operations/{operationJob}', [OperationController::class, 'show'])->name('operations.show');

    Route::get('/readiness', [ReadinessController::class, 'index'])->name('readiness.index');
});
