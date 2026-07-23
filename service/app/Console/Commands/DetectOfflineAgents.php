<?php

namespace App\Console\Commands;

use App\Events\AgentOffline;
use App\Models\Agent;
use Illuminate\Console\Command;

/**
 * Phase 4-J: Agent::isOnline() is computed, not stored (Agent::last_seen_at
 * only ever moves forward on a real authenticated call) — there is no
 * existing write-point where an Agent "goes offline" the way there is for
 * e.g. approve()/disable(). This sweep, run on the same cadence it checks
 * (SWEEP_MINUTES), finds approved Agents whose last_seen_at crossed the
 * existing server_offline_threshold_seconds cutoff during *this* window
 * only — not "is currently offline" (which would re-fire every run for as
 * long as the agent stays down) — so AgentOffline fires exactly once per
 * transition. Mirrors DetectStalledDeployments' existing sweep-command
 * shape and scheduling convention.
 */
class DetectOfflineAgents extends Command
{
    private const SWEEP_MINUTES = 5;

    protected $signature = 'agents:detect-offline';

    protected $description = 'Notify once for each approved agent that has just crossed the offline threshold';

    public function handle(): int
    {
        $thresholdSeconds = (int) config('deployment.server_offline_threshold_seconds');
        $justCrossedBefore = now()->subSeconds($thresholdSeconds);
        $justCrossedAfter = $justCrossedBefore->copy()->subMinutes(self::SWEEP_MINUTES);

        $newlyOffline = Agent::where('status', Agent::STATUS_APPROVED)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $justCrossedBefore)
            ->where('last_seen_at', '>=', $justCrossedAfter)
            ->get();

        foreach ($newlyOffline as $agent) {
            event(new AgentOffline($agent));
        }

        $this->info("Detected {$newlyOffline->count()} agent(s) that just went offline.");

        return self::SUCCESS;
    }
}
