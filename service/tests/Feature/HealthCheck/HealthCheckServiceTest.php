<?php

namespace Tests\Feature\HealthCheck;

use App\Models\DeploymentTask;
use App\Services\HealthCheck\HealthCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Task 5: health check framework. Http::fake() avoids any real network
 * call — this is testing the framework's wiring, not live infrastructure.
 */
class HealthCheckServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_status_code_is_recorded_as_success(): void
    {
        Http::fake(['https://example.test/health' => Http::response('ok', 200)]);

        $task = DeploymentTask::factory()->create();
        $result = app(HealthCheckService::class)->run($task, 'http_status', [
            'url' => 'https://example.test/health',
            'expected_status' => 200,
        ]);

        $this->assertSame('success', $result->status);
        $this->assertStringContainsString('200', $result->result_message);
        $this->assertNotNull($result->checked_at);
    }

    public function test_mismatched_status_code_is_recorded_as_failed(): void
    {
        Http::fake(['https://example.test/health' => Http::response('error', 500)]);

        $task = DeploymentTask::factory()->create();
        $result = app(HealthCheckService::class)->run($task, 'http_status', [
            'url' => 'https://example.test/health',
            'expected_status' => 200,
        ]);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('500', $result->result_message);
    }

    public function test_unreachable_host_is_recorded_as_failed_not_an_exception(): void
    {
        Http::fake(['https://example.test/health' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host')]);

        $task = DeploymentTask::factory()->create();
        $result = app(HealthCheckService::class)->run($task, 'http_status', [
            'url' => 'https://example.test/health',
        ]);

        $this->assertSame('failed', $result->status);
    }

    public function test_unknown_check_type_is_rejected(): void
    {
        $task = DeploymentTask::factory()->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(HealthCheckService::class)->run($task, 'nonexistent_type', []);
    }

    public function test_result_is_persisted_and_associated_with_the_task(): void
    {
        Http::fake(['https://example.test/health' => Http::response('ok', 200)]);

        $task = DeploymentTask::factory()->create();
        app(HealthCheckService::class)->run($task, 'http_status', ['url' => 'https://example.test/health']);

        $this->assertCount(1, $task->fresh()->healthChecks);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'deployment_health_check.completed']);
    }
}
