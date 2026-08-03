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

// Root-cause fix: PublishJob's own lease-expiry sweep — deliberately on a
// much tighter cadence than deployments:detect-stalled above since a
// developer waiting on their own publish notices a stuck status within
// a minute, not five. See RecoverStalePublishJobs/StalePublishJobRecoveryService.
Schedule::command('deployments:recover-stale')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Phase 4-J: same 5-minute cadence DetectOfflineAgents' own sweep-window
// math assumes (see its docblock) — changing this interval without
// updating SWEEP_MINUTES there would open a gap where a transition is
// missed entirely.
Schedule::command('agents:detect-offline')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('deployment-system:prune-logs')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer();

// Phase 5.2: checks for LocalChangeDetectionJob rows stable long enough
// to auto-publish. Every five minutes is coarser than the stability
// window's own math needs to be exact for (unlike DetectOfflineAgents'
// transition-detection, this command mutates status the moment it acts,
// so it can never reprocess the same row twice) — just frequent enough
// that Auto Mode doesn't feel sluggish.
Schedule::command('publish:auto-publish-stable-changes')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Root-cause fix (customer-site direct-GitHub-dependency removal):
// deletes artifacts past their retention window and uploads abandoned
// mid-flight (phase one done, phase two never happened) — see
// CleanupArtifacts and ArtifactService::cleanupOldArtifacts() (which
// already handles per-project retention count on every upload).
Schedule::command('artifacts:cleanup')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
