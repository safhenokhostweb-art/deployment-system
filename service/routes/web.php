<?php

use App\Http\Controllers\Dashboard\AgentController;
use App\Http\Controllers\Dashboard\AuthController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\DeploymentController;
use App\Http\Controllers\Dashboard\DeploymentTaskController;
use App\Http\Controllers\Dashboard\ProjectController;
use App\Http\Controllers\Dashboard\ServerController;
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
    Route::post('/servers/{server}/agents', [AgentController::class, 'createRegistration'])->name('agents.create-registration');

    Route::get('/agents', [AgentController::class, 'index'])->name('agents.index');
    Route::get('/agents/pending', [AgentController::class, 'pending'])->name('agents.pending');
    Route::get('/agents/{agent:public_id}', [AgentController::class, 'show'])->name('agents.show');
    Route::post('/agents/{agent:public_id}/approve', [AgentController::class, 'approve'])->name('agents.approve');
    Route::post('/agents/{agent:public_id}/reject', [AgentController::class, 'reject'])->name('agents.reject');
    Route::post('/agents/{agent:public_id}/disable', [AgentController::class, 'disable'])->name('agents.disable');
    Route::post('/agents/{agent:public_id}/reenable', [AgentController::class, 'reenable'])->name('agents.reenable');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('/projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::post('/projects/{project}/deploy', [ProjectController::class, 'deployManual'])->name('projects.deploy');

    Route::get('/deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
    Route::post('/deployments/{deployment}/approve', [DeploymentController::class, 'approve'])->name('deployments.approve');
    Route::post('/deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])->name('deployments.cancel');
    Route::post('/deployments/{deployment}/rollback', [DeploymentController::class, 'rollback'])->name('deployments.rollback');

    Route::get('/deployment-tasks/{deploymentTask}', [DeploymentTaskController::class, 'show'])->name('deployment-tasks.show');
});
