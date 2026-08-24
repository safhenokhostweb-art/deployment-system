<?php

namespace Tests\Feature\Dashboard;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHealthAndBuildAgentSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_a_customer_facing_http_health_contract(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'http_health_enabled' => '1',
            'http_health_base_url' => 'https://customer.example',
            'http_health_path' => '/',
            'http_health_expected_status' => 200,
            'http_health_timeout_seconds' => 12,
            'http_health_retries' => 4,
            'http_health_retry_interval_seconds' => 3,
            'http_health_allowed_redirects' => '1',
            'http_health_expected_content' => 'customer-homepage-marker',
        ]))->assertRedirect(route('projects.show', $project));

        $health = $project->fresh()->http_health_check;
        $this->assertSame('https://customer.example/', $health['url']);
        $this->assertSame(200, $health['expected_status']);
        $this->assertSame(12, $health['timeout_seconds']);
        $this->assertSame(4, $health['retries']);
        $this->assertSame(3, $health['retry_interval_seconds']);
        $this->assertTrue($health['allowed_redirects']);
        $this->assertSame('customer-homepage-marker', $health['expected_content']);
    }

    public function test_only_a_mapped_production_build_agent_can_be_authoritative(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();
        $development = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['project_id' => $project->id, 'local_agent_id' => $development->id]);

        $this->actingAs($admin)->from(route('projects.edit', $project))
            ->put(route('projects.update', $project), $this->payload($project, [
                'authoritative_build_local_agent_id' => $development->id,
            ]))->assertSessionHasErrors('authoritative_build_local_agent_id');

        $builder = LocalAgent::factory()->productionBuild()->create();
        LocalProjectMapping::factory()->create(['project_id' => $project->id, 'local_agent_id' => $builder->id]);
        $this->actingAs($admin)->put(route('projects.update', $project), $this->payload($project, [
            'authoritative_build_local_agent_id' => $builder->id,
        ]))->assertRedirect(route('projects.show', $project));

        $this->assertSame($builder->id, $project->fresh()->authoritative_build_local_agent_id);
    }

    public function test_edit_page_exposes_health_and_authoritative_agent_controls(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $project = Project::factory()->create();

        $this->actingAs($admin)->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('authoritative_build_local_agent_id', false)
            ->assertSee('http_health_base_url', false)
            ->assertSee('http_health_expected_status', false);
    }

    /** @return array<string, mixed> */
    private function payload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'name' => $project->name,
            'repository' => $project->repository,
            'branch' => $project->branch,
            'environment' => $project->environment,
            'server_id' => $project->server_id,
            'pipeline' => json_encode($project->pipeline, JSON_THROW_ON_ERROR),
        ], $overrides);
    }
}
