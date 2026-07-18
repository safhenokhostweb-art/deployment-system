<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires a single `php artisan schedule:run` cron entry in production
// (see docs/ops/scheduler.md). withoutOverlapping guards each command
// against a slow run still executing when the next tick fires;
// onOneServer guards against duplicate runs if this app is ever deployed
// to more than one web node sharing the same cache store.
Schedule::command('agent-nonces:prune')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('deployments:detect-stalled')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('deployment-system:prune-logs')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();
