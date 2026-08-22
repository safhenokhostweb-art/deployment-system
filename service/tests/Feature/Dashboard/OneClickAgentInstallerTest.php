<?php

namespace Tests\Feature\Dashboard;

use App\Http\Controllers\Dashboard\ServerController;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use ReflectionClass;
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

    /**
     * Real bug reported after deploying to a real server: the panel's
     * OWN configured URL, not the operator's real production domain,
     * ends up embedded in install-agent.sh as HUB_URL — because that IS
     * correct, url('/') always reflects whatever this Hub's APP_URL
     * actually is. The real fault was the deployed Hub's own .env never
     * being pointed at its real public URL. This proves the embedded
     * value tracks the Hub's actual configured root, live, not a stale
     * or hardcoded string.
     */
    public function test_installer_hub_url_matches_the_hubs_own_configured_root_url(): void
    {
        // forceRootUrl() alone doesn't pin the scheme — url()/to() still
        // derive https vs http from the current request unless the
        // scheme is forced too, so a plain-HTTP test client would
        // otherwise silently downgrade this to http://.
        URL::forceScheme('https');
        URL::forceRootUrl('https://panel.safhenokhost.ir');

        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        $this->assertStringContainsString('HUB_URL="https://panel.safhenokhost.ir"', $script);
    }

    /**
     * The installer must never be generated with a loopback/HTTP URL for
     * anything that isn't this Hub's own local/dev environment — that
     * failure belongs here, with a clear reason, not discovered later on
     * a real target server as an unreachable-Hub timeout.
     */
    public function test_installer_is_not_generated_when_hub_url_is_loopback_in_production(): void
    {
        config(['app.env' => 'production']);
        URL::forceRootUrl('http://127.0.0.1:8000');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $store = $this->actingAs($admin)->post(route('servers.store'), ['name' => 'Prod Loopback Server']);
        $landing = $this->actingAs($admin)->get($store->headers->get('Location'));

        $landing->assertOk();
        $landing->assertDontSee(__('ui.servers.one_click_title'));
        $landing->assertDontSee('one-click-installer-content', false);
        $landing->assertSee(__('messages.errors.installer_hub_url_not_safe', ['url' => 'http://127.0.0.1:8000']));
    }

    /**
     * A real HTTPS production URL must still generate normally — the
     * guard only reacts to loopback/HTTP, never to HTTPS regardless of
     * environment.
     */
    public function test_installer_is_generated_when_hub_url_is_https_in_production(): void
    {
        config(['app.env' => 'production']);
        URL::forceScheme('https');
        URL::forceRootUrl('https://panel.safhenokhost.ir');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $store = $this->actingAs($admin)->post(route('servers.store'), ['name' => 'Prod Https Server']);
        $landing = $this->actingAs($admin)->get($store->headers->get('Location'));

        $landing->assertOk();
        $landing->assertSee(__('ui.servers.one_click_title'));
    }

    /**
     * This Hub's own local development use (APP_ENV=local/testing) must
     * keep working exactly as it always has — a loopback URL here is
     * not a misconfiguration, it's what running the Hub locally looks
     * like. The bash-level warning (case "$HUB_URL" in ...) is what
     * covers this case, not a generation-time block.
     */
    public function test_installer_still_generates_with_loopback_url_in_local_environment(): void
    {
        config(['app.env' => 'local']);
        URL::forceRootUrl('http://127.0.0.1:8000');

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $store = $this->actingAs($admin)->post(route('servers.store'), ['name' => 'Local Loopback Server']);
        $landing = $this->actingAs($admin)->get($store->headers->get('Location'));

        $landing->assertOk();
        $landing->assertSee(__('ui.servers.one_click_title'));
    }

    /**
     * Real bug reported: "php" candidates were tried newest-first
     * (php8.4 → php8.1 → php); the fix reorders to try the plain "php"
     * binary first, then the versioned ones. Also re-confirms the
     * pipefail-safe capture-then-check pattern (Round 3) survived this
     * change untouched.
     */
    public function test_installer_tries_php_candidates_in_the_correct_order(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        $this->assertStringContainsString('for candidate in php php8.3 php8.4 php8.2 php8.1; do', $script);
        $this->assertStringContainsString('PHP_MODULES="$("$PHP_BIN" -m 2>/dev/null)"', $script);
        $this->assertStringContainsString('printf \'%s\n\' "$PHP_MODULES" | grep -qi \'^curl$\'', $script);
    }

    /**
     * "Same version as what was built" — the embedded INSTALLER_VERSION
     * must always equal the actual source constant, so a downloaded
     * install-agent.sh's own header/log line is proof of exactly which
     * build produced it (see docs/ONE_CLICK_AGENT_INSTALLER_REPORT.md
     * §16 — this is what makes "is the panel still serving an old
     * installer" a one-line check instead of a guess).
     */
    public function test_installer_version_metadata_matches_the_current_source_constant(): void
    {
        $reflection = new ReflectionClass(ServerController::class);
        $expectedVersion = $reflection->getConstant('INSTALLER_VERSION');
        $this->assertNotFalse($expectedVersion);
        $this->assertIsString($expectedVersion);

        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        $this->assertStringContainsString("Installer version: {$expectedVersion}", $script);
        $this->assertStringContainsString("INSTALLER_VERSION=\"{$expectedVersion}\"", $script);
        $this->assertSame(2, substr_count($script, $expectedVersion), 'Expected the version string exactly twice: the header comment and the INSTALLER_VERSION= assignment.');
    }

    public function test_installer_script_contains_the_agents_real_source_with_matching_checksums(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        // Mirror ServerController::buildOneClickInstallerContent()'s exact
        // documented normalization (CRLF -> LF, one trailing newline) so the
        // expected checksum always describes the bytes the embedded heredoc
        // reproduces — a Windows checkout (CRLF on disk) must not fail this.
        $realAgentPhp = file_get_contents(base_path('../agent/agent.php'));
        $realAgentPhp = str_replace(["\r\n", "\r"], "\n", $realAgentPhp);
        $realAgentPhp = rtrim($realAgentPhp, "\n") . "\n";
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
        // agent/lib/ content count moves whenever a new lib/*.php file is
        // added (most recently PipelineExecutor.php, extracted out of
        // agent.php's own pollAndExecute() for testability) — this count
        // is a derived fact about the directory's contents, not a value
        // worth freezing in place.
        $this->assertCount(17, $matches, 'Expected agent.php + 16 lib/*.php files to be embedded.');

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

        exec('bash -n ' . escapeshellarg($this->toBashPath($path)) . ' 2>&1', $output, $exitCode);
        unlink($path);

        $this->assertSame(0, $exitCode, "bash -n reported a syntax error:\n" . implode("\n", $output));
    }

    /**
     * PHP's own file I/O (file_put_contents/chmod/unlink) always keeps
     * using the native path tempnam() returns — only the string actually
     * handed TO bash (a CLI argument, or substituted into script content
     * bash itself will run, e.g. PHP_BIN=...) needs translating, and
     * only on Windows.
     *
     * Which translation is correct depends on which `bash` actually
     * answers on PATH, which varies by machine/session — not just
     * Git Bash (MSYS2) anymore. WSL's own Linux userspace only ever
     * sees Windows drives under /mnt/<drive>/..., it has no concept of
     * `C:\...` at all; Git Bash's MSYS2 runtime auto-translates a
     * leading /<drive>/... segment back to the real Windows path (and
     * separately already tolerates a raw C:\... argument directly, via
     * its own path-detection heuristics). `bash --version` reliably
     * reports "...-pc-msys" for Git Bash and "...-pc-linux-gnu" for
     * WSL — the one signal used here to pick the right form, so this
     * keeps working whichever of the two happens to be on PATH.
     */
    private function toBashPath(string $windowsPath): string
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            // Native Linux/macOS (e.g. real CI) — tempnam() already
            // returned a plain POSIX path, nothing to translate.
            return $windowsPath;
        }

        $normalized = str_replace('\\', '/', $windowsPath);

        if (!preg_match('#^([A-Za-z]):/(.*)$#', $normalized, $matches)) {
            return $normalized;
        }

        $drive = strtolower($matches[1]);
        $rest = $matches[2];

        static $isWsl = null;
        if ($isWsl === null) {
            $version = strtolower((string) shell_exec('bash --version 2>&1'));
            $isWsl = !str_contains($version, 'msys');
        }

        return $isWsl ? "/mnt/{$drive}/{$rest}" : "/{$drive}/{$rest}";
    }

    /**
     * Regression test for a real bug found via testing on a real Ubuntu
     * 24.04 box: the php-curl check ("$PHP_BIN" -m | grep -qi '^curl$')
     * could false-negative under `set -o pipefail`. grep -q exits the
     * instant it finds "curl" and closes its end of the pipe; if php -m
     * is still writing later module names at that moment it can get
     * SIGPIPE (exit 141), which pipefail then reports as the pipeline's
     * own failure — even though grep DID find curl — so the installer
     * would wrongly claim php-curl isn't installed and abort.
     *
     * The race is about TIMING (a real external php process takes
     * measurably longer to start/flush than a shell builtin), not about
     * output SIZE — a fake "php" that sleeps between its first line and
     * the rest reproduces it deterministically with a small, realistic
     * payload. (An earlier version of this test tried to force the race
     * with a multi-megabyte payload instead; that also made the FIXED
     * pattern's own printf | grep step racy, since real php -m output is
     * only ever a couple KB — the fix relies on that, so the test's fake
     * output has to respect it too, not just be "big enough".) Runs both
     * the OLD unfixed pattern and the real, extracted, CURRENT detection
     * block from the generated script through this exact same fake php
     * under set -euo pipefail, via real bash (exec()), and asserts:
     * old = false negative (proves the bug is real), current = correct
     * detection (proves the fix works).
     */
    public function test_php_curl_detection_does_not_false_negative_under_pipefail(): void
    {
        $server = Server::factory()->create();
        $script = $this->generateInstallerScript($server, 'agt_x', 'sec_x');

        $start = strpos($script, 'PHP_MODULES="$("$PHP_BIN" -m 2>/dev/null)"');
        $this->assertNotFalse($start, 'Expected to find the PHP_MODULES capture line in the generated script.');
        $end = strpos($script, "\nfi\n", $start);
        $this->assertNotFalse($end, 'Expected to find the closing fi of the php-curl check.');
        $currentDetectionBlock = substr($script, $start, $end - $start + 4);
        $this->assertStringContainsString('grep -qi', $currentDetectionBlock);

        // Windows' AppData\Local\Temp is slow enough under real-time
        // antivirus scanning to make repeated small file writes here a
        // real bottleneck (see docs/ONE_CLICK_AGENT_INSTALLER_REPORT.md
        // §12) — use a fast path on the same disk as the repo instead.
        $fastTmpDir = storage_path('framework/testing/pipefail-regression');
        if (!is_dir($fastTmpDir)) {
            mkdir($fastTmpDir, 0777, true);
        }

        $fakePhpPath = tempnam($fastTmpDir, 'fake-php-') . '.sh';
        file_put_contents($fakePhpPath, <<<'SH'
#!/bin/sh
echo curl
sleep 0.3
echo mbstring
echo openssl
echo pdo_sqlite
echo json
echo session
SH
        );
        chmod($fakePhpPath, 0755);

        $oldBuggyPattern = <<<'BASH'
if ! "$PHP_BIN" -m 2>/dev/null | grep -qi '^curl$'; then
    echo "NOT_FOUND"
    exit 1
fi
echo "FOUND"
BASH;

        $harnessTemplate = <<<'BASH'
set -euo pipefail
PHP_BIN="__FAKE_PHP__"
__DETECTION__
BASH;

        // Embedded as PHP_BIN="..." inside script content bash will run
        // itself ("$PHP_BIN" -m) — this needs the bash-native form, not
        // PHP's own (Windows) view of the same file.
        $fakePhpBashPath = $this->toBashPath($fakePhpPath);

        $oldHarness = str_replace(['__FAKE_PHP__', '__DETECTION__'], [$fakePhpBashPath, $oldBuggyPattern], $harnessTemplate);
        $newHarness = str_replace(
            ['__FAKE_PHP__', '__DETECTION__'],
            [$fakePhpBashPath, $currentDetectionBlock . "\necho \"FOUND\""],
            $harnessTemplate
        );

        [$oldExit, $oldOutput] = $this->runBash($oldHarness, $fastTmpDir);
        [$newExit, $newOutput] = $this->runBash($newHarness, $fastTmpDir);

        unlink($fakePhpPath);

        $this->assertStringContainsString('NOT_FOUND', $oldOutput, 'Expected the OLD pattern to reproduce the false-negative bug under this harness — if it did not, the harness stopped proving anything.');
        $this->assertNotSame(0, $oldExit);

        $this->assertStringContainsString('FOUND', $newOutput, "Fixed detection block must report curl as found.\nOutput: {$newOutput}");
        $this->assertStringNotContainsString('افزونه php-curl', $newOutput);
        $this->assertSame(0, $newExit, "Fixed detection block must exit 0.\nOutput: {$newOutput}");
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runBash(string $script, ?string $dir = null): array
    {
        $path = tempnam($dir ?? sys_get_temp_dir(), 'pipefail-harness-') . '.sh';
        file_put_contents($path, $script);

        exec('bash ' . escapeshellarg($this->toBashPath($path)) . ' 2>&1', $output, $exitCode);
        unlink($path);

        return [$exitCode, implode("\n", $output)];
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

    /**
     * Regression test (agent credential mismatch after upgrade): once a
     * Server has an approved Agent, the Server page must NOT offer the
     * legacy Server-credential config.php / one-click installer download.
     * That config authenticates through the Agent identity path and would
     * always be rejected with HTTP 401 invalid_signature because it was
     * built from the Server's own signing_secret. The operator must use
     * the Agent page's credentials instead.
     */
    public function test_server_with_approved_agent_blocks_legacy_server_credential_download(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();
        \App\Models\Agent::factory()->approved()->create(['server_id' => $server->id]);

        $rotate = $this->actingAs($admin)->post(route('servers.rotate-token', $server));
        $landing = $this->actingAs($admin)->get($rotate->headers->get('Location'));

        $landing->assertOk();
        // The misleading Server-credential download is not offered.
        $landing->assertDontSee(__('ui.servers.one_click_title'));
        $landing->assertDontSee('one-click-installer-content', false);
        $landing->assertDontSee(__('ui.servers.registration_created_title'));
        // The operator is redirected to the Agent page for the right credentials.
        $landing->assertSee(__('ui.servers.agent_credentials_required_title'));
    }

    public function test_server_without_agent_still_offers_legacy_server_credential_download(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $server = Server::factory()->create();

        $rotate = $this->actingAs($admin)->post(route('servers.rotate-token', $server));
        $landing = $this->actingAs($admin)->get($rotate->headers->get('Location'));

        $landing->assertOk();
        $landing->assertSee(__('ui.servers.one_click_title'));
        $landing->assertSee('one-click-installer-content', false);
        $landing->assertDontSee(__('ui.servers.agent_credentials_required_title'));
    }
}
