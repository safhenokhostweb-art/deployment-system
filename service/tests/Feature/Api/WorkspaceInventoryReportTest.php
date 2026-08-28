<?php

namespace Tests\Feature\Api;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Database\Factories\LocalAgentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsLocalAgentRequests;
use Tests\TestCase;

/**
 * Phase 2 (Workspace Inventory): the Hub-side ingest/persistence side of
 * WorkspaceInventoryScanner's report — same signed channel and identity
 * resolution as every other Local Agent endpoint, see
 * LocalAgentWorkspaceInventoryController and WorkspaceInventoryReportService.
 */
class WorkspaceInventoryReportTest extends TestCase
{
    use RefreshDatabase;
    use SignsLocalAgentRequests;

    private const PATH = '/api/local-agent/workspace-inventory';

    /** @return array{0: LocalAgent, 1: Project, 2: LocalProjectMapping} */
    private function mappedAgentAndProject(): array
    {
        $agent = LocalAgent::factory()->approved()->create();
        $project = Project::factory()->create();
        $mapping = LocalProjectMapping::factory()->create([
            'local_agent_id' => $agent->id,
            'project_id' => $project->id,
        ]);

        return [$agent, $project, $mapping];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<int, array<string, mixed>> $files
     * @return array<string, mixed>
     */
    private function validBody(LocalProjectMapping $mapping, array $overrides = [], array $files = []): array
    {
        $filesCount = count($files);

        return array_merge([
            'schema_version' => 1,
            'project_id' => $mapping->project_id,
            'mapping_id' => $mapping->id,
            'scanned_at' => now()->toIso8601String(),
            'branch' => 'main',
            'head_sha' => str_repeat('a', 40),
            'upstream_ref' => 'refs/remotes/origin/main',
            'upstream_sha' => str_repeat('b', 40),
            'comparison_basis' => 'upstream_tracking',
            'ahead_count' => 0,
            'behind_count' => 0,
            'tracked_modified_count' => $filesCount,
            'untracked_count' => 0,
            'staged_count' => 0,
            'deleted_count' => 0,
            'renamed_count' => 0,
            'conflicted_count' => 0,
            'total_changed_count' => $filesCount,
            'is_clean' => $filesCount === 0,
            'is_truncated' => false,
            'omitted_file_count' => 0,
            'scan_error' => null,
            'snapshot_hash' => str_repeat('c', 64),
            'files' => $files,
        ], $overrides);
    }

    /**
     * Named postInventory(), not post() — post() collides with a method
     * Illuminate\Foundation\Testing\TestCase itself declares (must stay
     * public there), which made this whole class fatal to even load.
     */
    private function postInventory(array $body, LocalAgent $agent, ?string $token = null, ?string $secret = null): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->localAgentHeaders('POST', self::PATH, $json, $agent->public_id, $token ?? LocalAgentFactory::$lastPlainToken, $secret ?? LocalAgentFactory::$lastPlainSecret);

        return $this->postJson(self::PATH, $body, $headers);
    }

    // -----------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------

    public function test_a_valid_signed_inventory_report_is_accepted_and_persisted(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();

        $body = $this->validBody($mapping, [], [
            ['path' => 'app/Foo.php', 'old_path' => null, 'status' => 'modified', 'index_status' => null, 'worktree_status' => 'M', 'staged' => false, 'tracked' => true, 'ownership' => 'unknown'],
        ], );
        $body['tracked_modified_count'] = 1;
        $body['total_changed_count'] = 1;
        $body['is_clean'] = false;

        $this->postInventory($body, $agent)->assertOk()->assertJson(['status' => 'accepted', 'mapping_id' => $mapping->id]);

        $mapping->refresh();
        $this->assertSame(1, $mapping->workspace_inventory_schema_version);
        $this->assertSame('main', $mapping->workspace_branch);
        $this->assertSame(str_repeat('a', 40), $mapping->workspace_head_sha);
        $this->assertSame('upstream_tracking', $mapping->workspace_comparison_basis);
        $this->assertSame(0, $mapping->workspace_ahead_count);
        $this->assertFalse($mapping->workspace_is_clean);
        $this->assertNotNull($mapping->workspace_inventory_received_at);
        $this->assertCount(1, $mapping->workspace_inventory_files);
        $this->assertSame('app/Foo.php', $mapping->workspace_inventory_files[0]['path']);
        $this->assertSame('unknown', $mapping->workspace_inventory_files[0]['ownership']);
    }

    // -----------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------

    public function test_invalid_signature_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping);

        $this->postJson(self::PATH, $body, [
            'Authorization' => 'Bearer ' . LocalAgentFactory::$lastPlainToken,
            'X-Deployment-Local-Agent-Id' => $agent->public_id,
            'X-Deployment-Timestamp' => (string) time(),
            'X-Deployment-Nonce' => bin2hex(random_bytes(16)),
            'X-Deployment-Signature' => 'not-a-real-signature',
        ])->assertStatus(401);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping);
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->localAgentHeaders('POST', self::PATH, $json, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson(self::PATH, $body, $headers)->assertOk();
        $this->postJson(self::PATH, $body, $headers)->assertStatus(401);
    }

    public function test_an_unapproved_agent_is_rejected(): void
    {
        $agent = LocalAgent::factory()->create(); // pending_approval, no token/secret issued
        $project = Project::factory()->create();
        $mapping = LocalProjectMapping::factory()->create(['local_agent_id' => $agent->id, 'project_id' => $project->id]);
        $body = $this->validBody($mapping);

        $this->postJson(self::PATH, $body, [
            'Authorization' => 'Bearer whatever',
            'X-Deployment-Local-Agent-Id' => $agent->public_id,
            'X-Deployment-Timestamp' => (string) time(),
            'X-Deployment-Nonce' => bin2hex(random_bytes(16)),
            'X-Deployment-Signature' => 'irrelevant',
        ])->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Ownership
    // -----------------------------------------------------------------

    public function test_a_mapping_not_owned_by_the_authenticated_agent_is_rejected(): void
    {
        [, , $mapping] = $this->mappedAgentAndProject(); // owned by a DIFFERENT agent
        $otherAgent = LocalAgent::factory()->approved()->create();

        $this->postInventory($this->validBody($mapping), $otherAgent)->assertStatus(403);
    }

    public function test_project_id_not_matching_the_mapping_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $unrelatedProject = Project::factory()->create();

        $this->postInventory($this->validBody($mapping, ['project_id' => $unrelatedProject->id]), $agent)->assertStatus(403);
    }

    public function test_an_inactive_project_mapping_is_rejected(): void
    {
        [$agent, $project, $mapping] = $this->mappedAgentAndProject();
        $project->update(['is_active' => false]);

        $this->postInventory($this->validBody($mapping), $agent)->assertStatus(422);
    }

    public function test_a_nonexistent_mapping_id_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();

        $this->postInventory($this->validBody($mapping, ['mapping_id' => $mapping->id + 999]), $agent)->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // Schema / payload validation
    // -----------------------------------------------------------------

    public function test_malformed_schema_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping);
        unset($body['comparison_basis']);

        $this->postInventory($body, $agent)->assertStatus(422);
    }

    public function test_unsupported_schema_version_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();

        $this->postInventory($this->validBody($mapping, ['schema_version' => 2]), $agent)->assertStatus(422);
    }

    public function test_unsupported_ownership_value_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping, [], [
            ['path' => 'a.php', 'old_path' => null, 'status' => 'modified', 'index_status' => null, 'worktree_status' => 'M', 'staged' => false, 'tracked' => true, 'ownership' => 'ai'],
        ]);
        $body['total_changed_count'] = 1;
        $body['tracked_modified_count'] = 1;

        $this->postInventory($body, $agent)->assertStatus(422);
    }

    public function test_file_content_field_is_rejected_by_not_persisting_it(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping, [], [
            ['path' => 'a.php', 'old_path' => null, 'status' => 'modified', 'index_status' => null, 'worktree_status' => 'M', 'staged' => false, 'tracked' => true, 'ownership' => 'unknown', 'content' => "<?php\n// exfiltrated\n", 'diff' => '--- a/a.php'],
        ]);
        $body['total_changed_count'] = 1;
        $body['tracked_modified_count'] = 1;

        $this->postInventory($body, $agent)->assertOk();

        $mapping->refresh();
        $stored = $mapping->workspace_inventory_files[0];
        $this->assertArrayNotHasKey('content', $stored);
        $this->assertArrayNotHasKey('diff', $stored);
        $this->assertSame(['path', 'old_path', 'status', 'index_status', 'worktree_status', 'staged', 'tracked', 'ownership'], array_keys($stored));
    }

    public function test_file_count_over_the_hard_maximum_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $files = [];
        for ($i = 0; $i < 2001; $i++) {
            $files[] = ['path' => "file{$i}.txt", 'old_path' => null, 'status' => 'untracked', 'index_status' => null, 'worktree_status' => null, 'staged' => false, 'tracked' => false, 'ownership' => 'unknown'];
        }
        $body = $this->validBody($mapping, [], $files);
        $body['total_changed_count'] = 2001;
        $body['untracked_count'] = 2001;

        $this->postInventory($body, $agent)->assertStatus(422);
    }

    public function test_a_path_over_the_length_limit_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping, [], [
            ['path' => str_repeat('x', 1001), 'old_path' => null, 'status' => 'untracked', 'index_status' => null, 'worktree_status' => null, 'staged' => false, 'tracked' => false, 'ownership' => 'unknown'],
        ]);
        $body['total_changed_count'] = 1;
        $body['untracked_count'] = 1;

        $this->postInventory($body, $agent)->assertStatus(422);
    }

    public function test_a_payload_over_the_max_body_size_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        // Comfortably over LocalAgentWorkspaceInventoryController::MAX_BODY_BYTES
        // (6,000,000) via one oversized field — checked at the request
        // boundary, before Laravel's own field-level rules even run.
        $body = $this->validBody($mapping, ['scan_error' => str_repeat('x', 6_500_000)]);

        $this->postInventory($body, $agent)->assertStatus(413);
    }

    public function test_malformed_sha_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();

        $this->postInventory($this->validBody($mapping, ['head_sha' => 'not-a-sha']), $agent)->assertStatus(422);
    }

    public function test_count_inconsistency_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        // total_changed_count claims 5 but no files/omitted account for them.
        $body = $this->validBody($mapping, ['total_changed_count' => 5]);

        $this->postInventory($body, $agent)->assertStatus(422);
    }

    public function test_is_clean_true_with_nonzero_total_is_rejected(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping, [], [
            ['path' => 'a.php', 'old_path' => null, 'status' => 'modified', 'index_status' => null, 'worktree_status' => 'M', 'staged' => false, 'tracked' => true, 'ownership' => 'unknown'],
        ]);
        $body['total_changed_count'] = 1;
        $body['tracked_modified_count'] = 1;
        $body['is_clean'] = true; // inconsistent with total_changed_count = 1

        $this->postInventory($body, $agent)->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Nullable ahead/behind / truncated / error state preserved
    // -----------------------------------------------------------------

    public function test_nullable_ahead_behind_is_preserved_when_upstream_is_unavailable(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping, [
            'comparison_basis' => 'unavailable',
            'ahead_count' => null,
            'behind_count' => null,
            'upstream_ref' => null,
            'upstream_sha' => null,
        ]);

        $this->postInventory($body, $agent)->assertOk();

        $mapping->refresh();
        $this->assertSame('unavailable', $mapping->workspace_comparison_basis);
        $this->assertNull($mapping->workspace_ahead_count);
        $this->assertNull($mapping->workspace_behind_count);
    }

    public function test_truncated_and_error_state_is_preserved_not_papered_over(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $body = $this->validBody($mapping, [
            'is_truncated' => true,
            'omitted_file_count' => 3,
            'total_changed_count' => 3, // count(files)=0 + omitted_file_count=3, per the count-consistency invariant
            'tracked_modified_count' => 3, // + untracked_count=0 must also equal total_changed_count=3
            'scan_error' => 'git status timed out after 30s.',
            'is_clean' => false,
            'branch' => null,
            'head_sha' => null,
            'upstream_ref' => null,
            'upstream_sha' => null,
            'comparison_basis' => 'unavailable',
            'ahead_count' => null,
            'behind_count' => null,
        ]);

        $this->postInventory($body, $agent)->assertOk();

        $mapping->refresh();
        $this->assertTrue($mapping->workspace_is_truncated);
        $this->assertSame(3, $mapping->workspace_omitted_file_count);
        $this->assertSame('git status timed out after 30s.', $mapping->workspace_scan_error);
        $this->assertFalse($mapping->workspace_is_clean);
    }

    // -----------------------------------------------------------------
    // Stale / out-of-order protection
    // -----------------------------------------------------------------

    public function test_first_snapshot_is_persisted(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $this->assertFalse($mapping->hasReportedWorkspaceInventory());

        $this->postInventory($this->validBody($mapping), $agent)->assertOk()->assertJson(['status' => 'accepted']);

        $this->assertTrue($mapping->fresh()->hasReportedWorkspaceInventory());
    }

    public function test_a_newer_snapshot_replaces_an_older_one(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $earlier = now()->subMinute();
        $later = now();

        $this->postInventory($this->validBody($mapping, ['scanned_at' => $earlier->toIso8601String(), 'snapshot_hash' => str_repeat('1', 64)]), $agent)->assertOk();
        $this->postInventory($this->validBody($mapping, ['scanned_at' => $later->toIso8601String(), 'snapshot_hash' => str_repeat('2', 64), 'branch' => 'develop']), $agent)->assertOk();

        $mapping->refresh();
        $this->assertSame('develop', $mapping->workspace_branch);
        $this->assertSame(str_repeat('2', 64), $mapping->workspace_inventory_snapshot_hash);
    }

    public function test_an_older_scanned_at_cannot_overwrite_a_newer_one(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $earlier = now()->subMinute();
        $later = now();

        $this->postInventory($this->validBody($mapping, ['scanned_at' => $later->toIso8601String(), 'snapshot_hash' => str_repeat('2', 64), 'branch' => 'main']), $agent)->assertOk();
        $response = $this->postInventory($this->validBody($mapping, ['scanned_at' => $earlier->toIso8601String(), 'snapshot_hash' => str_repeat('1', 64), 'branch' => 'stale-branch']), $agent);

        $response->assertOk()->assertJson(['status' => 'ignored_stale']);

        $mapping->refresh();
        $this->assertSame('main', $mapping->workspace_branch); // untouched by the stale report
        $this->assertSame(str_repeat('2', 64), $mapping->workspace_inventory_snapshot_hash);
    }

    public function test_identical_timestamp_and_identical_hash_is_idempotently_accepted(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $scannedAt = now();
        $body = $this->validBody($mapping, ['scanned_at' => $scannedAt->toIso8601String(), 'snapshot_hash' => str_repeat('9', 64)]);

        $this->postInventory($body, $agent)->assertOk()->assertJson(['status' => 'accepted']);
        $receivedAtAfterFirst = $mapping->fresh()->workspace_inventory_received_at;

        $this->postInventory($body, $agent)->assertOk()->assertJson(['status' => 'accepted']);

        $mapping->refresh();
        $this->assertSame(str_repeat('9', 64), $mapping->workspace_inventory_snapshot_hash);
        // A pure no-op replay never mutates the stored received_at either.
        $this->assertTrue($receivedAtAfterFirst->equalTo($mapping->workspace_inventory_received_at));
    }

    public function test_identical_timestamp_with_a_different_hash_is_rejected_as_inconsistent(): void
    {
        [$agent, , $mapping] = $this->mappedAgentAndProject();
        $scannedAt = now();

        $this->postInventory($this->validBody($mapping, ['scanned_at' => $scannedAt->toIso8601String(), 'snapshot_hash' => str_repeat('a', 64)]), $agent)->assertOk();
        $response = $this->postInventory($this->validBody($mapping, ['scanned_at' => $scannedAt->toIso8601String(), 'snapshot_hash' => str_repeat('b', 64), 'branch' => 'tampered']), $agent);

        $response->assertStatus(422);

        $mapping->refresh();
        $this->assertSame(str_repeat('a', 64), $mapping->workspace_inventory_snapshot_hash);
        $this->assertNotSame('tampered', $mapping->workspace_branch);
    }

    // -----------------------------------------------------------------
    // Backward compatibility
    // -----------------------------------------------------------------

    public function test_missing_inventory_is_never_represented_as_clean(): void
    {
        [, , $mapping] = $this->mappedAgentAndProject();

        $this->assertFalse($mapping->hasReportedWorkspaceInventory());
        $this->assertNull($mapping->workspace_is_clean);
        $this->assertNotSame(false, $mapping->workspace_is_clean); // never coerced to a clean-looking false either
    }

    public function test_old_agent_heartbeat_without_workspace_inventory_still_works(): void
    {
        [$agent, $project, $mapping] = $this->mappedAgentAndProject();

        $body = ['project_states' => [[
            'project_key' => $project->project_key,
            'working_tree_dirty' => false,
        ]]];
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $headers = $this->localAgentHeaders('POST', '/api/local-agent/heartbeat', $json, $agent->public_id, LocalAgentFactory::$lastPlainToken, LocalAgentFactory::$lastPlainSecret);

        $this->postJson('/api/local-agent/heartbeat', $body, $headers)->assertOk();

        $mapping->refresh();
        $this->assertFalse($mapping->hasReportedWorkspaceInventory());
        $this->assertFalse($mapping->working_tree_dirty);
    }
}
