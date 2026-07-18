<?php

namespace Tests\Feature\Dashboard;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_user_can_approve_a_pending_deployment(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_PENDING_APPROVAL,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
        ]);

        $this->actingAs($user)
            ->post(route('deployments.approve', $deployment))
            ->assertRedirect(route('deployments.show', $deployment));

        $this->assertDatabaseHas('deployments', [
            'id' => $deployment->id,
            'status' => Deployment::STATUS_QUEUED,
            'approved_by' => $user->email,
        ]);
    }

    public function test_cannot_approve_a_deployment_that_is_already_queued(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_WEBHOOK,
            'queued_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('deployments.approve', $deployment))
            ->assertStatus(409);
    }

    public function test_user_can_roll_back_to_a_successful_deployment(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $successful = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'finished_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)->post(route('deployments.rollback', $successful));
        $response->assertRedirect();

        $this->assertDatabaseHas('deployments', [
            'project_id' => $project->id,
            'action' => Deployment::ACTION_ROLLBACK,
            'commit_hash' => str_repeat('a', 40),
            'rollback_of_deployment_id' => $successful->id,
            'status' => Deployment::STATUS_QUEUED,
        ]);
    }

    public function test_cannot_roll_back_to_a_deployment_that_never_succeeded(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $failed = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_FAILED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
        ]);

        $this->actingAs($user)
            ->post(route('deployments.rollback', $failed))
            ->assertStatus(409);
    }
}
