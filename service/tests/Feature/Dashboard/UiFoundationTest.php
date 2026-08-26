<?php

namespace Tests\Feature\Dashboard;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_components_render_rtl_safe_accessible_markup(): void
    {
        app()->setLocale('en');

        $badge = Blade::render('<x-ui.status-badge domain="deployment" status="failed" />');
        $health = Blade::render('<x-ui.health-indicator status="verified" detail="Checked now" />');
        $alert = Blade::render('<x-ui.alert-panel level="danger" title="Failure">Details</x-ui.alert-panel>');
        $timeline = Blade::render('<x-ui.timeline label="Release events"><li>Queued</li></x-ui.timeline>');
        $header = Blade::render('<x-ui.page-header title="Deployments" description="Operational history" />');
        $stat = Blade::render('<x-ui.stat-card label="Healthy" value="12" level="success" />');
        $empty = Blade::render('<x-ui.empty-state title="No deployments" description="Nothing needs attention" />');
        $card = Blade::render('<x-ui.info-card title="Artifact">Details</x-ui.info-card>');

        $this->assertStringContainsString('ui-status--danger', $badge);
        $this->assertStringContainsString('data-actionable="true"', $badge);
        $this->assertStringContainsString('data-terminal="true"', $badge);
        $this->assertStringContainsString('Failed', $badge);
        $this->assertStringContainsString('ui-health--success', $health);
        $this->assertStringContainsString('role="status"', $health);
        $this->assertStringContainsString('role="alert"', $alert);
        $this->assertStringContainsString('aria-label="Release events"', $timeline);
        $this->assertStringContainsString('ui-page-header__title', $header);
        $this->assertStringContainsString('ui-stat-card--success', $stat);
        $this->assertStringContainsString('role="status"', $empty);
        $this->assertStringContainsString('<section', $card);
    }

    public function test_authenticated_layout_keeps_routes_and_adds_navigation_semantics(): void
    {
        $user = User::factory()->create();
        Server::factory()->create(['last_seen_at' => now()->subDay()]);

        $response = $this->actingAs($user)->get(route('dashboard.index'));

        $response->assertOk();
        $response->assertSee('href="#main-content"', false);
        $response->assertSee('id="main-content"', false);
        $response->assertSee('aria-current="page"', false);
        $response->assertSee('system-status--degraded', false);
        $response->assertSee(route('projects.index'), false);
        $response->assertSee(route('servers.index'), false);
        $response->assertSee(route('agents.index'), false);
    }
}
