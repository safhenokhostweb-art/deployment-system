<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

class LocalAgentProjectStateHeartbeatTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    public function test_an_agent_can_report_only_its_mapped_project_git_state(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create(['project_key' => 'saas-platform']);
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);
        $body = [
            'project_states' => [[
                'project_key' => 'saas-platform',
                'local_head_sha' => str_repeat('a', 40),
                'branch' => 'main',
                'working_tree_dirty' => true,
                'remote_tracking_sha' => str_repeat('b', 40),
            ]],
        ];
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->localAgentHeaders('POST', '/api/local-agent/heartbeat', $json, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson('/api/local-agent/heartbeat', $body, $headers)->assertOk();

        $mapping->refresh();
        $this->assertSame(str_repeat('a', 40), $mapping->local_head_sha);
        $this->assertSame(str_repeat('b', 40), $mapping->remote_tracking_sha);
        $this->assertSame('main', $mapping->reported_branch);
        $this->assertTrue($mapping->working_tree_dirty);
        $this->assertNotNull($mapping->reported_at);
    }

    public function test_an_agent_cannot_report_state_for_an_unmapped_project(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create(['project_key' => 'unmapped-project']);
        $body = ['project_states' => [[
            'project_key' => $project->project_key,
            'working_tree_dirty' => false,
        ]]];
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->localAgentHeaders('POST', '/api/local-agent/heartbeat', $json, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson('/api/local-agent/heartbeat', $body, $headers)->assertStatus(422);
    }
}
