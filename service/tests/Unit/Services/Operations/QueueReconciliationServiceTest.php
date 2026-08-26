<?php

namespace Tests\Unit\Services\Operations;

use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Models\PublishJob;
use App\Services\Operations\QueueReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_publish_job_is_rejected_as_not_stale(): void
    {
        $job = PublishJob::factory()->create(['status' => PublishJob::STATUS_APPROVED, 'updated_at' => now()]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(QueueReconciliationService::class)->reconcile('publish', $job->id, 'admin@example.test', 'stale review');
        $this->assertSame(PublishJob::STATUS_APPROVED, $job->fresh()->status);
    }

    public function test_stale_agent_control_job_becomes_terminal_failed_without_deletion(): void
    {
        $job = LocalAgentControlJob::factory()->create(['local_agent_id' => LocalAgent::factory()->create()->id, 'status' => LocalAgentControlJob::STATUS_RUNNING, 'updated_at' => now()->subHours(4)]);
        app(QueueReconciliationService::class)->reconcile('agent_control', $job->id, 'admin@example.test', 'agent retired');
        $this->assertDatabaseHas('local_agent_control_jobs', ['id' => $job->id, 'status' => LocalAgentControlJob::STATUS_FAILED]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'queue_record.reconciled', 'subject_id' => $job->id]);
    }
}
