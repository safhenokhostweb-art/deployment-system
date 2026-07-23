<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\OperationJob;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Support\Operations\OperationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4-K Task 13. Deliberately does not re-test Agent auth or the
 * signed channel (OperationJobFlowTest covers that) — only the Dashboard
 * side: permission gating (Task 9), queueing, and rendering.
 */
class OperationsCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_viewer_can_see_the_command_center_but_not_execute(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);
        $server = Server::factory()->create();
        Agent::factory()->approved()->create(['server_id' => $server->id]);

        $this->actingAs($viewer)->get(route('operations.index'))->assertOk();
        $this->actingAs($viewer)->post(route('operations.store', $server), ['operation_type' => OperationRegistry::DISK_CHECK])
            ->assertForbidden();
    }

    public function test_operator_can_execute_a_server_group_operation(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $server = Server::factory()->create();
        Agent::factory()->approved()->create(['server_id' => $server->id]);

        $this->actingAs($operator)->post(route('operations.store', $server), ['operation_type' => OperationRegistry::DISK_CHECK])
            ->assertRedirect(route('operations.index'));

        $this->assertDatabaseHas('operation_jobs', [
            'server_id' => $server->id,
            'operation_type' => OperationRegistry::DISK_CHECK,
            'status' => OperationJob::STATUS_QUEUED,
            'requested_by' => $operator->email,
        ]);
    }

    public function test_developer_cannot_execute_an_operation(): void
    {
        // Phase 4-K Task 9: Developer gets operations.view, not .execute.
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $server = Server::factory()->create();
        Agent::factory()->approved()->create(['server_id' => $server->id]);

        $this->actingAs($developer)->post(route('operations.store', $server), ['operation_type' => OperationRegistry::DISK_CHECK])
            ->assertForbidden();
    }

    public function test_laravel_group_operation_requires_a_sole_resolvable_project(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();
        Agent::factory()->approved()->create(['server_id' => $server->id]);

        // No project on this server yet — cache_clear needs one.
        $this->actingAs($admin)->post(route('operations.store', $server), ['operation_type' => OperationRegistry::CACHE_CLEAR])
            ->assertStatus(422);

        Project::factory()->create(['server_id' => $server->id]);

        $this->actingAs($admin)->post(route('operations.store', $server), ['operation_type' => OperationRegistry::CACHE_CLEAR])
            ->assertRedirect();

        $this->assertDatabaseHas('operation_jobs', ['operation_type' => OperationRegistry::CACHE_CLEAR]);
    }

    public function test_unknown_operation_type_is_rejected(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();
        Agent::factory()->approved()->create(['server_id' => $server->id]);

        $this->actingAs($admin)->post(route('operations.store', $server), ['operation_type' => 'rm -rf /'])
            ->assertStatus(422);

        $this->assertDatabaseCount('operation_jobs', 0);
    }

    public function test_operation_is_rejected_when_server_has_no_approved_agent(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();

        $this->actingAs($admin)->post(route('operations.store', $server), ['operation_type' => OperationRegistry::DISK_CHECK])
            ->assertStatus(422);

        $this->assertDatabaseCount('operation_jobs', 0);
    }

    public function test_operation_detail_page_shows_timeline_and_output(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();
        $agent = Agent::factory()->approved()->create(['server_id' => $server->id]);
        $job = OperationJob::create([
            'server_id' => $server->id,
            'agent_id' => $agent->id,
            'operation_type' => OperationRegistry::DISK_CHECK,
            'status' => OperationJob::STATUS_SUCCEEDED,
            'output' => 'Filesystem 1K-blocks Used',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('operations.show', $job));

        $response->assertOk();
        $response->assertSee('Filesystem 1K-blocks Used');
        $response->assertSee(__('ui.operations.timeline_title'));
    }

    public function test_server_show_page_lists_recent_operations_and_health(): void
    {
        $admin = $this->admin();
        $server = Server::factory()->create();
        $agent = Agent::factory()->approved()->create([
            'server_id' => $server->id,
            'cpu_load_percent' => 12.0,
            'memory_usage_percent' => 40.0,
            'disk_usage_percent' => 60.0,
            'health_reported_at' => now(),
        ]);
        OperationJob::create([
            'server_id' => $server->id,
            'agent_id' => $agent->id,
            'operation_type' => OperationRegistry::SERVICE_STATUS,
            'status' => OperationJob::STATUS_SUCCEEDED,
        ]);

        $response = $this->actingAs($admin)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee(__('ui.operations.type_' . OperationRegistry::SERVICE_STATUS));
        $response->assertSee('12%', false);
    }

    public function test_direct_url_access_to_operations_is_blocked_for_a_guest(): void
    {
        $this->get(route('operations.index'))->assertRedirect(route('login'));
    }
}
