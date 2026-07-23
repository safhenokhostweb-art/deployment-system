<?php

namespace Tests\Feature\Dashboard;

use App\Models\Agent;
use App\Models\DiscoveryJob;
use App\Models\GitHubAccount;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Final Phase: only the *connecting* logic added this phase — Local
 * Discovery's own detection (agent-side scanner, DiscoveryJobFlowTest)
 * and the Project Wizard's pre-existing behavior (LocalDiscoveryTest,
 * Phase 4-H) are unchanged and not re-tested here.
 */
class LocalImportAutoConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function serverWithScanResult(array $resultEntry): Server
    {
        $server = Server::factory()->create();
        $agent = Agent::factory()->approved()->create(['server_id' => $server->id]);
        DiscoveryJob::create([
            'agent_id' => $agent->id,
            'workspace_path' => '/home/example/www',
            'status' => DiscoveryJob::STATUS_SUCCEEDED,
            'result' => [$resultEntry],
        ]);

        return $server;
    }

    public function test_discovery_results_show_path_and_last_commit_columns(): void
    {
        $admin = $this->admin();
        $server = $this->serverWithScanResult([
            'name' => 'saas-platform',
            'path' => '/home/example/www/saas-platform',
            'type' => 'laravel',
            'framework_version' => '^11.0',
            'git' => ['repository' => 'acme/saas-platform', 'branch' => 'main', 'commit_hash' => str_repeat('a', 40)],
        ]);

        $response = $this->actingAs($admin)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertSee('/home/example/www/saas-platform', false);
        $response->assertSee(substr(str_repeat('a', 40), 0, 10), false);
    }

    public function test_discovery_results_render_safely_when_commit_hash_is_absent(): void
    {
        // Compatibility: a scan result predating this phase (or one where
        // the project has no commits yet) has no git.commit_hash key at
        // all — must render '—', never a 500.
        $admin = $this->admin();
        $server = $this->serverWithScanResult([
            'name' => 'saas-platform',
            'path' => '/home/example/www/saas-platform',
            'type' => 'laravel',
            'framework_version' => '^11.0',
            'git' => ['repository' => 'acme/saas-platform', 'branch' => 'main'],
        ]);

        $this->actingAs($admin)->get(route('servers.show', $server))->assertOk();
    }

    public function test_use_this_project_link_carries_path_commit_and_framework_version(): void
    {
        $admin = $this->admin();
        $server = $this->serverWithScanResult([
            'name' => 'saas-platform',
            'path' => '/home/example/www/saas-platform',
            'type' => 'laravel',
            'framework_version' => '^11.0',
            'git' => ['repository' => 'acme/saas-platform', 'branch' => 'main', 'commit_hash' => str_repeat('b', 40)],
        ]);

        $response = $this->actingAs($admin)->get(route('servers.show', $server));

        $response->assertSee(route('projects.create', [
            'discovered_name' => 'saas-platform',
            'discovered_path' => '/home/example/www/saas-platform',
            'discovered_repository' => 'acme/saas-platform',
            'discovered_branch' => 'main',
            'discovered_commit' => str_repeat('b', 40),
            'discovered_framework_version' => '^11.0',
            'discovered_type' => 'laravel',
        ]));
    }

    public function test_wizard_shows_a_detected_notice_with_path_commit_and_framework(): void
    {
        $admin = $this->admin();
        Server::factory()->create();

        $response = $this->actingAs($admin)->get(route('projects.create', [
            'discovered_name' => 'saas-platform',
            'discovered_path' => '/home/example/www/saas-platform',
            'discovered_repository' => 'acme/saas-platform',
            'discovered_branch' => 'main',
            'discovered_commit' => str_repeat('c', 40),
            'discovered_framework_version' => '^11.0',
            'discovered_type' => 'laravel',
        ]));

        $response->assertOk();
        $response->assertSee(__('ui.discovery.detected_notice'));
        $response->assertSee('/home/example/www/saas-platform', false);
        $response->assertSee('^11.0', false);
        $response->assertSee(substr(str_repeat('c', 40), 0, 10), false);
    }

    public function test_wizard_shows_no_detected_notice_without_discovery_query_parameters(): void
    {
        // Compatibility: every pre-Final-Phase caller (a plain GET,
        // including every existing test) must not see this new notice.
        $admin = $this->admin();
        Server::factory()->create();

        $response = $this->actingAs($admin)->get(route('projects.create'));

        $response->assertOk();
        $response->assertDontSee(__('ui.discovery.detected_notice'));
    }

    public function test_github_picker_auto_selects_the_sole_connected_account_when_a_repository_was_discovered(): void
    {
        $admin = $this->admin();
        Server::factory()->create();
        GitHubAccount::factory()->create(['account_login' => 'acme-org']);

        $response = $this->actingAs($admin)->get(route('projects.create', [
            'discovered_repository' => 'acme/saas-platform',
            'discovered_branch' => 'main',
        ]));

        $response->assertOk();
        // The sole account's <option> is rendered with `selected` — scoped
        // to the github_account_id <select> itself, since the page's
        // unrelated pipeline-preset <select> also legitimately uses
        // @selected() and would otherwise false-positive this check.
        $this->assertStringContainsString('selected', $this->githubAccountSelectHtml($response->getContent()));
        $response->assertSee('"acme/saas-platform"', false);
    }

    public function test_github_picker_does_not_auto_select_with_multiple_connected_accounts(): void
    {
        $admin = $this->admin();
        Server::factory()->create();
        GitHubAccount::factory()->create(['account_login' => 'acme-org']);
        GitHubAccount::factory()->create(['account_login' => 'acme-personal']);

        $response = $this->actingAs($admin)->get(route('projects.create', [
            'discovered_repository' => 'acme/saas-platform',
            'discovered_branch' => 'main',
        ]));

        $response->assertOk();
        // Neither <option> should carry `selected` — ambiguous, so nothing
        // is guessed (mirrors Server::soleProject()'s own convention).
        $this->assertStringNotContainsString('selected', $this->githubAccountSelectHtml($response->getContent()));
        // The manual-entry fallback still carries the discovered values.
        $response->assertSee('value="acme/saas-platform"', false);
    }

    /**
     * Isolates just the github_account_id <select>...</select> markup —
     * the page's unrelated pipeline-preset <select> also legitimately
     * carries a `selected` option and would otherwise false-positive a
     * page-wide substring check.
     */
    private function githubAccountSelectHtml(string $html): string
    {
        preg_match('/<select id="github_account_id".*?<\/select>/s', $html, $matches);

        return $matches[0] ?? '';
    }

    public function test_no_secret_leakage_on_the_server_show_discovery_display(): void
    {
        $admin = $this->admin();
        // Confirms the display layer never surfaces anything beyond the
        // structured fields it explicitly reads — a defense-in-depth check
        // on top of DiscoveryJobFlowTest's write-time redaction test. Even
        // if a credential somehow ended up in a field this view doesn't
        // read (remote_url is stored but never displayed — only
        // repository/branch/commit are), it must never reach the page.
        $server = $this->serverWithScanResult([
            'name' => 'saas-platform',
            'path' => '/home/example/www/saas-platform',
            'type' => 'laravel',
            'framework_version' => '^11.0',
            'git' => [
                'repository' => 'acme/saas-platform',
                'branch' => 'main',
                'commit_hash' => str_repeat('d', 40),
                'remote_url' => 'https://oper_ator:sup3r-s3cret-tok3n@github.com/acme/saas-platform.git',
            ],
        ]);

        $response = $this->actingAs($admin)->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertDontSee('sup3r-s3cret-tok3n', false);
        $response->assertDontSee('oper_ator:sup3r-s3cret-tok3n', false);
    }
}
