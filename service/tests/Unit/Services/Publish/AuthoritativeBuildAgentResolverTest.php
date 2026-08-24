<?php

namespace Tests\Unit\Services\Publish;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Services\Publish\AuthoritativeBuildAgentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuthoritativeBuildAgentResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_agents_always_resolve_to_the_configured_authoritative_agent(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $development = LocalAgent::factory()->approved()->create(['last_seen_at' => now()]);
        $this->mapping($project, $development, '/opt/deployment-agent/workspaces/dev');
        $authoritative = LocalAgent::factory()->productionBuild()->create();
        $expected = $this->mapping($project, $authoritative, '/opt/deployment-agent/workspaces/project');
        $project->update(['authoritative_build_local_agent_id' => $authoritative->id]);

        $resolver = app(AuthoritativeBuildAgentResolver::class);
        $this->assertSame($expected->id, $resolver->resolve($project, 'main')->id);
        $this->assertSame($expected->id, $resolver->resolve($project->fresh(), 'main')->id);
    }

    public function test_offline_authoritative_agent_blocks_without_falling_back_to_online_desktop(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $desktop = LocalAgent::factory()->approved()->create(['last_seen_at' => now()]);
        $this->mapping($project, $desktop, '/opt/deployment-agent/workspaces/desktop');
        $authoritative = LocalAgent::factory()->productionBuild()->create(['last_seen_at' => now()->subHour()]);
        $this->mapping($project, $authoritative, '/opt/deployment-agent/workspaces/project');
        $project->update(['authoritative_build_local_agent_id' => $authoritative->id]);

        $this->assertResolverRejected($project, 'offline');
    }

    public function test_development_agent_cannot_build_even_when_explicitly_selected(): void
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $desktop = LocalAgent::factory()->approved()->create(['last_seen_at' => now()]);
        $this->mapping($project, $desktop, '/opt/deployment-agent/workspaces/desktop');
        $project->update(['authoritative_build_local_agent_id' => $desktop->id]);

        $this->assertResolverRejected($project, 'production_build role');
    }

    public function test_old_agent_version_is_rejected(): void
    {
        [$project, $agent] = $this->authoritativeProject();
        $agent->update(['agent_version' => '1.4.5']);

        $this->assertResolverRejected($project, 'version');
    }

    public function test_stale_report_and_production_like_source_are_rejected(): void
    {
        [$staleProject, , $staleMapping] = $this->authoritativeProject();
        $staleMapping->update(['reported_at' => now()->subHour()]);
        $this->assertResolverRejected($staleProject, 'stale');

        [$unsafeProject, , $unsafeMapping] = $this->authoritativeProject();
        $unsafeMapping->update([
            'local_path' => '/var/www/customer-site',
            'source_workspace_path' => '/var/www/customer-site',
            'production_path' => '/var/www/customer-site',
        ]);
        $this->assertResolverRejected($unsafeProject, 'production-like');
    }

    /** @return array{Project, LocalAgent, LocalProjectMapping} */
    private function authoritativeProject(): array
    {
        $project = Project::factory()->create(['branch' => 'main']);
        $agent = LocalAgent::factory()->productionBuild()->create();
        $mapping = $this->mapping($project, $agent, '/opt/deployment-agent/workspaces/project');
        $project->update(['authoritative_build_local_agent_id' => $agent->id]);

        return [$project, $agent, $mapping];
    }

    private function mapping(Project $project, LocalAgent $agent, string $source): LocalProjectMapping
    {
        return LocalProjectMapping::factory()->create([
            'project_id' => $project->id,
            'local_agent_id' => $agent->id,
            'local_path' => $source,
            'source_workspace_path' => $source,
            'production_path' => '/var/www/project',
            'git_branch' => 'main',
            'reported_branch' => 'main',
            'local_head_sha' => str_repeat('a', 40),
            'remote_tracking_sha' => str_repeat('a', 40),
            'working_tree_dirty' => false,
            'reported_at' => now(),
        ]);
    }

    private function assertResolverRejected(Project $project, string $messageFragment): void
    {
        try {
            app(AuthoritativeBuildAgentResolver::class)->resolve($project->fresh(), 'main');
            $this->fail('Expected authoritative Agent resolution to be blocked.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertStringContainsString($messageFragment, $exception->getMessage());
        }
    }
}
