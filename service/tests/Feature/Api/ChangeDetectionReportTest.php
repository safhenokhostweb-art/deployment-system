<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Phase 5.2: the agent-facing side of the file watcher — same signed
 * channel as every other Local Agent endpoint, see
 * LocalAgentChangeDetectionController.
 */
class ChangeDetectionReportTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    private function mappedAgentAndProject(string $mode = LocalProjectMapping::MODE_APPROVAL): array
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'deployment_mode' => $mode,
        ]);

        return [$agent, $project];
    }

    public function test_agent_reports_a_change_and_it_is_persisted(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();

        $body = ['project_key' => $project->project_key, 'changed_files' => ['app/Foo.php']];
        $headers = $this->localAgentHeaders('POST', '/api/local-agent/change-detections', json_encode($body), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson('/api/local-agent/change-detections', $body, $headers)
            ->assertOk()
            ->assertJson(['status' => LocalChangeDetectionJob::STATUS_WAITING_APPROVAL]);

        $this->assertDatabaseHas('local_change_detection_jobs', [
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
            'files_changed_count' => 1,
        ]);
    }

    public function test_repeated_reports_update_the_same_row(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject();

        $path = '/api/local-agent/change-detections';
        $firstBody = ['project_key' => $project->project_key, 'changed_files' => ['app/Foo.php']];
        $this->postJson($path, $firstBody, $this->localAgentHeaders('POST', $path, json_encode($firstBody), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret))->assertOk();

        $secondBody = ['project_key' => $project->project_key, 'changed_files' => ['app/Foo.php', 'app/Bar.php']];
        $this->postJson($path, $secondBody, $this->localAgentHeaders('POST', $path, json_encode($secondBody), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret))->assertOk();

        $this->assertSame(1, LocalChangeDetectionJob::count());
        $this->assertSame(2, LocalChangeDetectionJob::first()->files_changed_count);
    }

    public function test_unknown_project_key_is_rejected(): void
    {
        $agent = LocalAgent::factory()->approved()->create();

        $body = ['project_key' => 'does-not-exist', 'changed_files' => ['app/Foo.php']];
        $headers = $this->localAgentHeaders('POST', '/api/local-agent/change-detections', json_encode($body), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson('/api/local-agent/change-detections', $body, $headers)->assertStatus(422);
    }

    public function test_unmapped_project_is_rejected(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create(); // no mapping to this agent

        $body = ['project_key' => $project->project_key, 'changed_files' => ['app/Foo.php']];
        $headers = $this->localAgentHeaders('POST', '/api/local-agent/change-detections', json_encode($body), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson('/api/local-agent/change-detections', $body, $headers)->assertStatus(422);
    }

    public function test_manual_mode_report_stays_detected(): void
    {
        [$agent, $project] = $this->mappedAgentAndProject(LocalProjectMapping::MODE_MANUAL);

        $body = ['project_key' => $project->project_key, 'changed_files' => ['app/Foo.php']];
        $headers = $this->localAgentHeaders('POST', '/api/local-agent/change-detections', json_encode($body), $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson('/api/local-agent/change-detections', $body, $headers)
            ->assertOk()
            ->assertJson(['status' => LocalChangeDetectionJob::STATUS_DETECTED]);
    }
}
