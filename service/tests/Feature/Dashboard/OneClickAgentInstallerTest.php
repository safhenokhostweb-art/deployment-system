<?php

namespace Tests\Feature\Dashboard;

use App\Http\Controllers\Dashboard\ServerController;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * One-Click Agent Installer. Only Hub-side generation is testable here —
 * there is no real Linux/systemd target in this suite, so the actual
 * bash execution (rollback, idempotent re-run, live heartbeat) is
 * verified by hand (see docs/ONE_CLICK_AGENT_INSTALLER_REPORT.md §6),
 * not by PHPUnit. What IS verified: the script is only ever generated
 * on the same one-time session-flash window agentConfigContent already
 * uses (no new secret-retrieval path), it's built from THIS server's
 * real data with no cross-server leakage, and its content matches what
 * the installer logic is supposed to contain.
 */
class OneClickAgentInstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_installer_is_offered_on_the_one_time_page_load_after_registration(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $store = $this->actingAs($admin)->post(route('servers.store'), ['name' => 'Fresh Server']);
        $landing = $this->actingAs($admin)->get($store->headers->get('Location'));

        $landing->assertOk();
        $landing->assertSee(__('ui.servers.one_click_title'));
        $landing->assertSee('one-click-installer-content', false);
    }

    public function test_installer_is_not_offered_on_a_later_visit(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $store = $this->actingAs($admin)->post(route('servers.store'), ['name' => 'Later Visit Server']);
        $this->actingAs($admin)->get($store->headers->get('Location'));

        $secondVisit = $this->actingAs($admin)->get($store->headers->get('Location'));

        $secondVisit->assertOk();
        $secondVisit->assertDontSee(__('ui.servers.one_click_title'));
        $secondVisit->assertDontSee('one-click-installer-content', false);
    }

    public function test_installer_is_regenerated_after_credential_rotation(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();

        $rotate = $this->actingAs($admin)->post(route('servers.rotate-token', $server));
        $landing = $this->actingAs($admin)->get($rotate->headers->get('Location'));

        $landing->assertOk();
        $landing->assertSee(__('ui.servers.one_click_title'));
    }

    public function test_installer_content_embeds_this_servers_own_data_and_nothing_from_another_server(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherServer = Server::factory()->create();

        $store = $this->actingAs($admin)->post(route('servers.store'), ['name' => 'Isolated Installer Server']);
        $server = Server::where('name', 'Isolated Installer Server')->firstOrFail();
        $landing = $this->actingAs($admin)->get($store->headers->get('Location'));

        $landing->assertSee($server->public_id, false);
        $landing->assertDontSee($otherServer->public_id, false);
        $landing->assertDontSee((string) $otherServer->signing_secret, false);
    }

    public function test_installer_content_embeds_this_servers_real_project_keys(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();
        \App\Models\Project::factory()->create(['server_id' => $server->id, 'project_key' => 'my-real-project']);

        $rotate = $this->actingAs($admin)->post(route('servers.rotate-token', $server));
        $landing = $this->actingAs($admin)->get($rotate->headers->get('Location'));

        $landing->assertSee('my-real-project', false);
        $landing->assertSee('/opt/deployment-agent/projects/my-real-project', false);
    }

    /**
     * These checks need the raw script text, not the HTML page it's
     * rendered inside — Blade's {{ }} HTML-entity-encodes the double
     * quotes the script is full of, and the page also always includes
     * the older, always-visible "ready install command" card (which
     * deliberately still contains CHANGE_ME placeholders — Task 3's
     * simpler manual alternative, kept working on purpose). Reflection
     * isolates exactly what buildOneClickInstallerContent() produced.
     */
    private function generateInstallerScript(Server $server, string $token, string $secret): string
    {
        $method = new ReflectionMethod(ServerController::class, 'buildOneClickInstallerContent');
        $method->setAccessible(true);

        return $method->invoke(app(ServerController::class), $server, $token, $secret);
    }

    public function test_installer_script_contains_the_agents_real_source_with_matching_checksums(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        $realAgentPhp = file_get_contents(base_path('../agent/agent.php'));
        $expectedChecksum = hash('sha256', $realAgentPhp);

        $this->assertStringContainsString('verify_checksum "$INSTALL_DIR/agent.php" "' . $expectedChecksum . '"', $script);
        $this->assertStringContainsString('declare(strict_types=1);', $script);
    }

    public function test_installer_script_has_no_change_me_placeholders_in_its_systemd_unit(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        $this->assertStringContainsString('ExecStart=$PHP_BIN $INSTALL_DIR/agent.php', $script);
        $this->assertStringContainsString('WorkingDirectory=$INSTALL_DIR', $script);
        $this->assertStringNotContainsString('CHANGE_ME', $script);
    }

    public function test_installer_script_enforces_https_and_self_deletes_on_success(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        $this->assertStringContainsString('https://*', $script);
        $this->assertStringContainsString('shred -u "$SCRIPT_PATH"', $script);
    }

    public function test_plain_token_appears_exactly_once_in_the_generated_script(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_UNIQUE_TOKEN_VALUE', 'sec_UNIQUE_SECRET_VALUE');

        $this->assertSame(1, substr_count($script, 'agt_UNIQUE_TOKEN_VALUE'));
        $this->assertSame(1, substr_count($script, 'sec_UNIQUE_SECRET_VALUE'));
    }

    /**
     * Regression test for a real bug found via testing on a real Ubuntu
     * 24.04 box: verify_checksum() failed for agent.php on every real
     * install. The bug was in heredoc RECONSTRUCTION, not in the
     * checksum value itself — the template always appended its own
     * trailing "\n" before the closing delimiter, so a file that (like
     * every real source file) already ends in "\n" got reconstructed
     * one byte longer than what the checksum was computed from. A test
     * that only compares the embedded checksum STRING (see the test
     * above) can never catch this class of bug, because the checksum
     * was never wrong — only what bash actually reconstructs from the
     * heredoc was.
     *
     * A quoted bash heredoc (<<'DELIM' ... DELIM) passes its body
     * through completely unprocessed — byte for byte — so extracting
     * that exact substring here reproduces precisely what `cat` would
     * write to disk on a real server, with no need to actually shell
     * out to bash (which, on this Windows dev box, means writing
     * through Windows' AppData\Local\Temp — slow enough under real-time
     * antivirus scanning to make that approach an unreliable test).
     */
    public function test_heredoc_body_for_each_embedded_file_matches_its_own_checksum_exactly(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        preg_match_all(
            '/cat > "\$INSTALL_DIR\/([^"]+)" <<\'([A-Z0-9_]+)\'\n(.*?)\n\2\nverify_checksum "\$INSTALL_DIR\/[^"]+" "([0-9a-f]{64})"/s',
            $script,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($matches, 'Expected to find embedded source file blocks in the generated script.');
        $this->assertCount(10, $matches, 'Expected agent.php + 9 lib/*.php files to be embedded.');

        foreach ($matches as $match) {
            [, $relativePath, , $heredocBody, $expectedChecksum] = $match;

            $actualChecksum = hash('sha256', $heredocBody . "\n");
            $this->assertSame(
                $expectedChecksum,
                $actualChecksum,
                "Heredoc body for {$relativePath} does not match its own embedded checksum — bash would reconstruct a different file than what was verified."
            );
        }
    }

    public function test_installer_script_is_valid_bash_syntax(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        $path = tempnam(sys_get_temp_dir(), 'install-agent-') . '.sh';
        file_put_contents($path, $script);

        exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        unlink($path);

        $this->assertSame(0, $exitCode, "bash -n reported a syntax error:\n" . implode("\n", $output));
    }

    public function test_installer_is_hidden_from_a_non_admin_even_if_the_session_flash_is_present(): void
    {
        $operator = User::factory()->create(['role' => User::ROLE_OPERATOR]);
        $server = Server::factory()->create();

        $response = $this->actingAs($operator)
            ->withSession(['generated_token' => 'agt_test', 'generated_secret' => 'sec_test'])
            ->get(route('servers.show', $server));

        $response->assertOk();
        $response->assertDontSee(__('ui.servers.one_click_title'));
    }
}
