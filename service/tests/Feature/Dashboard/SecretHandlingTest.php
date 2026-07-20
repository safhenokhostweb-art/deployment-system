<?php

namespace Tests\Feature\Dashboard;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6: secret handling hardening. Confirms the gap found during this
 * phase's audit (the webhook secret being redisplayed in full on every
 * page load) is actually fixed, and that rotation behaves like the
 * existing Server/Agent credential pattern.
 */
class SecretHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_secret_is_masked_on_ordinary_page_loads(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['github_webhook_secret' => 'super-secret-webhook-value-1234']);

        $response = $this->actingAs($user)->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertDontSee('super-secret-webhook-value-1234');
        // First/last few characters may still appear as part of the mask —
        // the point is the full raw value never appears verbatim.
    }

    public function test_webhook_secret_is_shown_in_full_immediately_after_creation(): void
    {
        $user = User::factory()->create();
        $server = \App\Models\Server::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'project_key' => 'secret-test-project',
            'name' => 'Secret Test',
            'repository' => 'acme/secret-test',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
        ]);

        $project = Project::where('project_key', 'secret-test-project')->firstOrFail();
        $followUp = $this->actingAs($user)->get($response->headers->get('Location'));

        $followUp->assertSee($project->github_webhook_secret);
    }

    public function test_rotating_the_webhook_secret_shows_the_new_value_once_and_invalidates_the_old_one(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['github_webhook_secret' => 'old-secret-value']);

        $response = $this->actingAs($user)->post(route('projects.rotate-webhook-secret', $project));
        $response->assertRedirect(route('projects.show', $project));

        $this->assertNotSame('old-secret-value', $project->fresh()->github_webhook_secret);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'project.webhook_secret_rotated']);

        // The redirected page (session-flashed) shows the NEW secret in full.
        $followUp = $this->actingAs($user)->get(route('projects.show', $project));
        $followUp->assertSee($project->fresh()->github_webhook_secret);
    }

    public function test_agent_and_server_credentials_are_never_serialized_in_json_responses(): void
    {
        // Regression guard: the $hidden arrays on Server/Agent must keep
        // excluding credential fields from array/JSON casts.
        $server = \App\Models\Server::factory()->create();
        $agent = \App\Models\Agent::factory()->approved()->create();

        $serverArray = $server->fresh()->toArray();
        $agentArray = $agent->fresh()->toArray();

        $this->assertArrayNotHasKey('agent_token_hash', $serverArray);
        $this->assertArrayNotHasKey('signing_secret', $serverArray);
        $this->assertArrayNotHasKey('agent_token_hash', $agentArray);
        $this->assertArrayNotHasKey('signing_secret', $agentArray);
        $this->assertArrayNotHasKey('registration_token', $agentArray);
    }
}
