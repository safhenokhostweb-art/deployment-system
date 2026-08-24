<?php

namespace Tests\Unit\Models;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The fingerprint ProjectConfigSyncScheduler (agent side) compares against
 * a previously-synchronized version to decide whether a heartbeat implies
 * "resync now" — must change on every field the sync endpoint actually
 * serves, and only those.
 */
class LocalAgentProjectConfigVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_is_stable_when_nothing_changes(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);

        $first = $agent->projectConfigVersion();
        $second = $agent->fresh()->projectConfigVersion();

        $this->assertSame($first, $second);
    }

    public function test_version_changes_when_a_mapping_is_added(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $before = $agent->projectConfigVersion();

        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);

        $this->assertNotSame($before, $agent->fresh()->projectConfigVersion());
    }

    public function test_version_ignores_legacy_local_path_when_an_explicit_source_workspace_exists(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'local_path' => 'C:\\old']);
        $before = $agent->fresh()->projectConfigVersion();

        $mapping->update(['local_path' => 'C:\\new']);

        $this->assertSame($before, $agent->fresh()->projectConfigVersion());
    }

    public function test_version_changes_when_the_safe_legacy_path_is_the_effective_source(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'local_path' => 'C:\\isolated\\old',
            'source_workspace_path' => null,
        ]);
        $before = $agent->fresh()->projectConfigVersion();

        DB::table('local_project_mappings')->where('id', $mapping->id)->update(['local_path' => 'C:\\isolated\\new']);

        $this->assertNotSame($before, $agent->fresh()->projectConfigVersion());
    }

    public function test_version_changes_when_source_workspace_path_changes_even_without_a_timestamp_change(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'local_path' => '/var/www/customer-site',
            'source_workspace_path' => '/opt/deployment-agent/workspaces/old-customer-site',
        ]);
        $before = $agent->fresh()->projectConfigVersion();

        DB::table('local_project_mappings')->where('id', $mapping->id)->update([
            'source_workspace_path' => '/opt/deployment-agent/workspaces/customer-site',
        ]);

        $this->assertNotSame($before, $agent->fresh()->projectConfigVersion());
    }

    public function test_version_changes_when_the_projects_branch_changes(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create(['branch' => 'main']);
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id, 'git_branch' => null]);
        $before = $agent->fresh()->projectConfigVersion();

        $project->update(['branch' => 'develop']);

        $this->assertNotSame($before, $agent->fresh()->projectConfigVersion());
    }

    public function test_version_changes_when_a_project_is_deactivated(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create(['is_active' => true]);
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);
        $before = $agent->fresh()->projectConfigVersion();

        $project->update(['is_active' => false]);

        $this->assertNotSame($before, $agent->fresh()->projectConfigVersion());
    }

    public function test_version_changes_when_a_mapping_is_removed(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);
        $before = $agent->fresh()->projectConfigVersion();

        $mapping->delete();

        $this->assertNotSame($before, $agent->fresh()->projectConfigVersion());
    }

    public function test_version_is_the_same_regardless_of_another_agents_mappings(): void
    {
        $agent = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id]);
        $before = $agent->fresh()->projectConfigVersion();

        $otherAgent = LocalAgent::factory()->approved()->create();
        LocalProjectMapping::factory()->create(['local_agent_id' => $otherAgent->id]);

        $this->assertSame($before, $agent->fresh()->projectConfigVersion());
    }
}
