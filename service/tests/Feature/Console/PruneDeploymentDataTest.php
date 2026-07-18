<?php

namespace Tests\Feature\Console;

use App\Models\AuditLogEntry;
use App\Models\Deployment;
use App\Models\DeploymentLogEntry;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneDeploymentDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_purges_output_only_for_old_finished_deployments(): void
    {
        config(['deployment.deployment_log_output_retention_days' => 90]);
        $project = Project::factory()->create();

        $old = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'finished_at' => now()->subDays(120),
        ]);
        $oldLog = DeploymentLogEntry::create([
            'deployment_id' => $old->id,
            'sequence' => 1,
            'step_name' => 'build',
            'command' => 'npm run build',
            'output' => 'lots of build output here',
            'exit_code' => 0,
        ]);

        $recent = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('b', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'finished_at' => now()->subDays(1),
        ]);
        $recentLog = DeploymentLogEntry::create([
            'deployment_id' => $recent->id,
            'sequence' => 1,
            'step_name' => 'build',
            'command' => 'npm run build',
            'output' => 'recent build output',
            'exit_code' => 0,
        ]);

        $this->artisan('deployment-system:prune-logs')->assertSuccessful();

        $this->assertSame('[purged by retention policy]', $oldLog->fresh()->output);
        $this->assertSame('recent build output', $recentLog->fresh()->output);
        // Metadata survives the purge — only the raw text is dropped.
        $this->assertSame('build', $oldLog->fresh()->step_name);
        $this->assertSame(0, $oldLog->fresh()->exit_code);
    }

    public function test_deletes_audit_log_entries_past_retention(): void
    {
        config(['deployment.audit_log_retention_days' => 365]);

        $old = AuditLogEntry::record('system', null, 'server.registered');
        $old->forceFill(['created_at' => now()->subDays(400)])->saveQuietly();

        $recent = AuditLogEntry::record('system', null, 'server.registered');

        $this->artisan('deployment-system:prune-logs')->assertSuccessful();

        $this->assertDatabaseMissing('audit_log_entries', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_log_entries', ['id' => $recent->id]);
    }
}
