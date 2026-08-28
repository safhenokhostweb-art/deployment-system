<?php

namespace Tests\Unit\Support\Deployment;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use App\Support\Deployment\WorkspaceStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceStatusResolverTest extends TestCase
{
    use RefreshDatabase;


    private function onlineAgent(): LocalAgent
    {
        return LocalAgent::factory()->approved()->create(['last_seen_at' => now()]);
    }

    private function mapping(LocalAgent $agent, array $overrides = []): LocalProjectMapping
    {
        $project = Project::factory()->create();

        return LocalProjectMapping::factory()->create(array_merge([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
        ], $overrides));
    }

    /** No mapping at all — UNMAPPED, independent of everything else. */
    public function test_null_mapping_is_unmapped(): void
    {
        $this->assertSame(WorkspaceStatusResolver::UNMAPPED, WorkspaceStatusResolver::resolve(null));
    }

    public function test_offline_agent_is_agent_offline_even_with_a_fresh_clean_report(): void
    {
        $agent = LocalAgent::factory()->approved()->create(['last_seen_at' => now()->subDays(2)]);
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'unavailable',
        ]);

        $this->assertSame(WorkspaceStatusResolver::AGENT_OFFLINE, WorkspaceStatusResolver::resolve($mapping));
    }

    // G. stale report status
    public function test_never_reported_is_stale(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent);

        $this->assertFalse($mapping->hasReportedWorkspaceInventory());
        $this->assertSame(WorkspaceStatusResolver::STALE, WorkspaceStatusResolver::resolve($mapping));
    }

    public function test_old_report_is_stale(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now()->subHours(2),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'unavailable',
        ]);

        $this->assertSame(WorkspaceStatusResolver::STALE, WorkspaceStatusResolver::resolve($mapping));
    }

    // H. clean repo status
    public function test_clean_report_is_clean(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_is_clean' => true,
            'workspace_comparison_basis' => 'unavailable',
            'workspace_ahead_count' => null,
            'workspace_behind_count' => null,
        ]);

        $this->assertSame(WorkspaceStatusResolver::CLEAN, WorkspaceStatusResolver::resolve($mapping));
    }

    public function test_clean_with_known_zero_ahead_behind_is_still_clean(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'upstream_tracking',
            'workspace_ahead_count' => 0,
            'workspace_behind_count' => 0,
        ]);

        $this->assertSame(WorkspaceStatusResolver::CLEAN, WorkspaceStatusResolver::resolve($mapping));
    }

    // I. dirty repo status
    public function test_uncommitted_changes_with_no_known_comparison_is_dirty(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 3,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'unavailable',
        ]);

        $this->assertSame(WorkspaceStatusResolver::DIRTY, WorkspaceStatusResolver::resolve($mapping));
    }

    // J. conflicted status
    public function test_conflicted_files_report_conflicted(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 1,
            'workspace_conflicted_count' => 1,
            'workspace_comparison_basis' => 'unavailable',
        ]);

        $this->assertSame(WorkspaceStatusResolver::CONFLICTED, WorkspaceStatusResolver::resolve($mapping));
    }

    public function test_a_scan_error_reports_conflicted_even_with_zero_counts(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_scan_error' => 'git status timed out after 30s.',
            'workspace_comparison_basis' => 'unavailable',
        ]);

        $this->assertSame(WorkspaceStatusResolver::CONFLICTED, WorkspaceStatusResolver::resolve($mapping));
    }

    // K. ahead-only status
    public function test_ahead_only_is_local_commits_ahead(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'upstream_tracking',
            'workspace_ahead_count' => 2,
            'workspace_behind_count' => 0,
        ]);

        $this->assertSame(WorkspaceStatusResolver::LOCAL_COMMITS_AHEAD, WorkspaceStatusResolver::resolve($mapping));
    }

    // L. behind-only status
    public function test_behind_only_is_behind_remote(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'upstream_tracking',
            'workspace_ahead_count' => 0,
            'workspace_behind_count' => 3,
        ]);

        $this->assertSame(WorkspaceStatusResolver::BEHIND_REMOTE, WorkspaceStatusResolver::resolve($mapping));
    }

    // M. diverged status
    public function test_ahead_and_behind_with_clean_tree_is_diverged(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'upstream_tracking',
            'workspace_ahead_count' => 2,
            'workspace_behind_count' => 1,
        ]);

        $this->assertSame(WorkspaceStatusResolver::DIVERGED, WorkspaceStatusResolver::resolve($mapping));
    }

    public function test_ahead_and_behind_with_dirty_tree_is_mixed(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 4,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'upstream_tracking',
            'workspace_ahead_count' => 2,
            'workspace_behind_count' => 1,
        ]);

        $this->assertSame(WorkspaceStatusResolver::MIXED, WorkspaceStatusResolver::resolve($mapping));
    }

    public function test_never_fakes_ahead_behind_when_comparison_basis_is_unavailable(): void
    {
        $agent = $this->onlineAgent();
        // Even if ahead/behind columns somehow held stale non-null values,
        // an 'unavailable' comparison_basis must never be allowed to
        // produce DIVERGED/MIXED/LOCAL_COMMITS_AHEAD/BEHIND_REMOTE.
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'unavailable',
            'workspace_ahead_count' => null,
            'workspace_behind_count' => null,
        ]);

        $this->assertSame(WorkspaceStatusResolver::CLEAN, WorkspaceStatusResolver::resolve($mapping));
    }

    public function test_the_model_convenience_method_delegates_to_the_resolver(): void
    {
        $agent = $this->onlineAgent();
        $mapping = $this->mapping($agent, [
            'workspace_inventory_schema_version' => 1,
            'workspace_inventory_received_at' => now(),
            'workspace_total_changed_count' => 0,
            'workspace_conflicted_count' => 0,
            'workspace_comparison_basis' => 'unavailable',
        ]);

        $this->assertSame(WorkspaceStatusResolver::CLEAN, $mapping->workspaceStatus());
    }
}
