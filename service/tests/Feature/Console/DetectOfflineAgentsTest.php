<?php

namespace Tests\Feature\Console;

use App\Models\Agent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-J: agents:detect-offline is pure instrumentation on top of
 * Agent::isOnline()'s existing, unmodified threshold — this only checks
 * the notification side effect, never Agent's own liveness logic.
 */
class DetectOfflineAgentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_that_just_crossed_the_offline_threshold_is_notified_once(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $thresholdSeconds = (int) config('deployment.server_offline_threshold_seconds');
        $agent = Agent::factory()->approved()->create([
            'name' => 'web-agent',
            'last_seen_at' => now()->subSeconds($thresholdSeconds + 60),
        ]);

        $this->artisan('agents:detect-offline')->assertExitCode(0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => Notification::TYPE_AGENT_OFFLINE,
            'related_type' => Agent::class,
            'related_id' => $agent->id,
        ]);
    }

    public function test_agent_still_within_the_online_threshold_is_not_notified(): void
    {
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        Agent::factory()->approved()->create(['last_seen_at' => now()->subSeconds(10)]);

        $this->artisan('agents:detect-offline')->assertExitCode(0);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_agent_offline_long_before_this_sweep_window_is_not_re_notified(): void
    {
        // Already offline well before this run's sweep window — a prior
        // run (if any) would have been the one to notify; this run must
        // not fire again.
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        Agent::factory()->approved()->create(['last_seen_at' => now()->subHours(6)]);

        $this->artisan('agents:detect-offline')->assertExitCode(0);

        $this->assertDatabaseCount('notifications', 0);
    }
}
