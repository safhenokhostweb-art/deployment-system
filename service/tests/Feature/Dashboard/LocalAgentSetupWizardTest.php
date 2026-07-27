<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5.4: the Setup Wizard entry point — a guided page tying together
 * registration/approval/mapping, all of which already exist and are
 * unchanged. This only tests the new page itself and that the
 * already-tested registration flow now also surfaces a ready-to-copy
 * install command.
 */
class LocalAgentSetupWizardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_setup_wizard(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('local-agents.setup'))
            ->assertOk()
            ->assertSee(__('ui.local_agent_setup.title'));
    }

    public function test_developer_cannot_view_the_setup_wizard(): void
    {
        $developer = User::factory()->create(['role' => User::ROLE_DEVELOPER]);

        $this->actingAs($developer)->get(route('local-agents.setup'))->assertStatus(403);
    }

    public function test_viewer_cannot_view_the_setup_wizard(): void
    {
        $viewer = User::factory()->create(['role' => User::ROLE_VIEWER]);

        $this->actingAs($viewer)->get(route('local-agents.setup'))->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('local-agents.setup'))->assertRedirect(route('login'));
    }

    public function test_registration_card_shows_a_ready_to_copy_install_command(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('local-agents.create-registration'));
        $response->assertSessionHas('generated_registration_id');
        $response->assertSessionHas('generated_registration_token');

        $registrationId = session('generated_registration_id');
        $registrationToken = session('generated_registration_token');

        $showResponse = $this->actingAs($admin)->get($response->headers->get('Location'));

        $showResponse->assertOk()
            ->assertSee('install.ps1', false)
            ->assertSee($registrationId, false)
            ->assertSee($registrationToken, false);
    }
}
