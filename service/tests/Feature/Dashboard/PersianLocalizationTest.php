<?php

namespace Tests\Feature\Dashboard;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Database\Factories\ServerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SignsAgentRequests;
use Tests\TestCase;

/**
 * Phase 4-B: Persian Localization + RTL Foundation. These tests exist to
 * lock in the two things the phase must never regress on: the dashboard
 * (web) locale renders Persian, and the API (agent-facing) locale/JSON
 * shape stays byte-for-byte English — since app.locale/fallback_locale are
 * never changed globally, only the 'web' middleware group flips display
 * locale to 'fa'.
 */
class PersianLocalizationTest extends TestCase
{
    use RefreshDatabase;
    use SignsAgentRequests;

    public function test_dashboard_renders_with_rtl_direction_and_persian_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('داشبورد');
        $response->assertSee('پروژه‌ها');
        $response->assertSee('سرورها');
        $response->assertSee('کارگزارها');
    }

    public function test_login_page_renders_persian(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('ورود');
    }

    public function test_deployment_status_badge_renders_persian_label(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('موفق');
        $response->assertDontSee('>succeeded<', false);
    }

    public function test_flash_status_message_is_translated_to_persian(): void
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

        $response = $this->actingAs($user)
            ->post(route('deployments.approve', $deployment))
            ->assertRedirect(route('deployments.show', $deployment));

        $followUp = $this->actingAs($user)->get($response->headers->get('Location'));
        $followUp->assertSee('استقرار تایید شد و به صف اضافه شد');
        $followUp->assertDontSee('Deployment approved and queued.');
    }

    public function test_guard_exception_message_is_translated_while_status_code_is_preserved(): void
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

        $response = $this->actingAs($user)->post(route('deployments.approve', $deployment));

        // Same behavioral contract as before this phase (still a 409) —
        // only the rendered body's text is now Persian.
        $response->assertStatus(409);
        $response->assertSee('فقط استقرارهای «در انتظار تایید» قابل تایید هستند');
        $response->assertDontSee('Only pending_approval deployments can be approved.');
    }

    public function test_project_creation_validation_errors_render_in_persian(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'project_key' => 'persian-validation-test',
            'name' => '',
            'repository' => 'acme/persian-validation-test',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertStringContainsString('فیلد نام الزامی است', session('errors')->first('name'));
    }

    public function test_api_agent_error_responses_stay_english_json_unaffected_by_dashboard_locale(): void
    {
        $server = Server::factory()->create();
        $token = ServerFactory::$lastPlainToken;
        $secret = ServerFactory::$lastPlainSecret;
        $project = Project::factory()->create(['server_id' => $server->id]);
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_RUNNING,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
        ]);

        $statusBody = ['status' => 'not_a_real_status'];
        $path = "/api/agent/jobs/{$deployment->public_id}/status";
        $headers = $this->agentHeaders('POST', $path, json_encode($statusBody), $server->public_id, $token, $secret);

        $response = $this->postJson($path, $statusBody, $headers);

        // Whichever guard rejects it (the route's own validation or the
        // service's abort_unless()), the API group never runs
        // SetDashboardLocale, so the message must stay plain English —
        // no Persian characters at all.
        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertNotSame('', $message);
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', $message);
    }
}
