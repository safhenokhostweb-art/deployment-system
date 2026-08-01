<?php

namespace Tests\Feature\Dashboard;

use App\Models\PublishJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Root-cause fix, section 7: "بازیابی انتشارهای معلق" — deliberately
 * separate from LocalAgentControlTest's restart-agent coverage; this
 * button cleans up Hub-side PublishJob/lock state via
 * StalePublishJobRecoveryService, regardless of any agent restart.
 */
class PublishRecoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_trigger_recovery_and_sees_a_summary(): void
    {
        $admin = User::factory()->create();
        $job = PublishJob::factory()->create([
            'status' => PublishJob::STATUS_RUNNING,
            'started_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->subMinute(),
        ]);

        $response = $this->actingAs($admin)->post(route('publish-jobs.recover-stale'));

        $response->assertRedirect(route('local-agents.index'));
        $response->assertSessionHas('status');
        $this->assertSame(PublishJob::STATUS_ABANDONED, $job->fresh()->status);
    }

    public function test_developer_cannot_trigger_recovery(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);

        $this->actingAs($developer)->post(route('publish-jobs.recover-stale'))->assertStatus(403);
    }

    public function test_records_an_audit_log_entry(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('publish-jobs.recover-stale'));

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'publish_job.manual_recovery_triggered',
            'actor_identifier' => $admin->email,
        ]);
    }

    public function test_never_touches_a_healthy_running_job(): void
    {
        $admin = User::factory()->create();
        $job = PublishJob::factory()->create([
            'status' => PublishJob::STATUS_RUNNING,
            'lease_expires_at' => now()->addMinutes(5),
        ]);

        $this->actingAs($admin)->post(route('publish-jobs.recover-stale'));

        $this->assertSame(PublishJob::STATUS_RUNNING, $job->fresh()->status);
    }
}
