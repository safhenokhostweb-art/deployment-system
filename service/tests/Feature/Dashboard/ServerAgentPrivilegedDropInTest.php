<?php

namespace Tests\Feature\Dashboard;

use App\Http\Controllers\Dashboard\ServerController;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression coverage for the permanent NoNewPrivileges fix. The Server
 * Agent runs unprivileged as `deployment-agent` but its deployment pipeline
 * must invoke two tightly constrained root-owned sudo helpers
 * (/usr/local/sbin/deployment-runtime-permissions and
 * /usr/local/sbin/deployment-service-reload). With `NoNewPrivileges=true`
 * sudo refuses to work at all, so every fresh-install path writes the same
 * dedicated drop-in (10-privileged-helpers.conf, the exact architecture
 * already used by agent/install/upgrade-runtime.sh and applied manually in
 * production) that flips only NoNewPrivileges to false while every other
 * hardening (PrivateTmp, unprivileged User/Group, constrained sudoers
 * allowlists) stays intact.
 *
 * There is no real systemd/Linux target in this suite, so — same convention
 * as agent/tests/run_privileged_helpers.php — the platform-dependent
 * installer behavior is proven from the exact generated/shipped source
 * content, byte for byte, not by shelling out.
 */
class ServerAgentPrivilegedDropInTest extends TestCase
{
    use RefreshDatabase;

    /** The exact drop-in file every installer path must produce (quoted, as written by the installers). */
    private const DROPIN_PATH = '"/etc/systemd/system/${SERVICE_NAME}.service.d/10-privileged-helpers.conf"';

    /** The exact drop-in body (identical across every installer path). */
    private const DROPIN_BODY = "[Service]\n# sudo is constrained to two literal root-owned helpers in /etc/sudoers.d.\nNoNewPrivileges=false\n";

    private function generateInstallerScript(Server $server): string
    {
        $method = new ReflectionMethod(ServerController::class, 'buildOneClickInstallerContent');
        $method->setAccessible(true);

        return $method->invoke(app(ServerController::class), $server, 'agt_test', 'sec_test');
    }

    /**
     * Reads a repo file relative to the monorepo root, normalizing any
     * Windows CRLF checkout so multi-line assertions are platform-stable.
     */
    private function readRepoSource(string $relativePath): string
    {
        $path = base_path('../' . $relativePath);
        $this->assertFileExists($path, "Expected repo source file to exist: {$path}");

        return str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
    }

    // ------------------------------------------------------------------
    // CASE 1 + CASE 7: fresh one-click install results in an effective
    // NoNewPrivileges=false, via the exact dedicated drop-in.
    // ------------------------------------------------------------------

    public function test_one_click_installer_writes_the_privileged_helpers_drop_in_with_no_new_privileges_false(): void
    {
        $script = $this->generateInstallerScript(Server::factory()->create());

        $dropInBlock = 'mkdir -p "/etc/systemd/system/${SERVICE_NAME}.service.d"' . "\n"
            . 'cat > ' . self::DROPIN_PATH . " <<'PRIVILEGED_HELPERS_EOF'\n"
            . self::DROPIN_BODY
            . "PRIVILEGED_HELPERS_EOF\n"
            . 'chmod 0644 ' . self::DROPIN_PATH . "\n";

        $this->assertStringContainsString($dropInBlock, $script);
        $this->assertStringContainsString('NoNewPrivileges=false', $script);
    }

    public function test_drop_in_is_created_after_the_unit_write_and_before_the_single_daemon_reload(): void
    {
        $script = $this->generateInstallerScript(Server::factory()->create());

        $unitWrite = strpos($script, 'SYSTEMD_UNIT_EOF');
        // The rollback helper also mentions the drop-in path earlier in the
        // script, so start the search after the unit heredoc opening.
        $dropIn = strpos($script, '10-privileged-helpers.conf', $unitWrite);
        $daemonReload = strpos($script, "systemctl daemon-reload\n", $dropIn);

        $this->assertNotFalse($unitWrite, 'Expected the unit heredoc write in the generated script.');
        $this->assertNotFalse($dropIn, 'Expected the privileged-helper drop-in in the generated script.');
        $this->assertNotFalse($daemonReload, 'Expected a daemon-reload in the generated script.');

        $this->assertTrue($unitWrite < $dropIn, 'Drop-in must be created after the unit is written.');
        $this->assertTrue($dropIn < $daemonReload, 'Drop-in must be created before daemon-reload so the effective config includes it.');
        $this->assertSame(1, substr_count($script, "systemctl daemon-reload\n"), 'Exactly one daemon-reload must pick up unit + drop-in together.');
    }

    public function test_base_unit_hardening_is_preserved_and_only_no_new_privileges_is_relaxed(): void
    {
        $script = $this->generateInstallerScript(Server::factory()->create());

        // Base unit keeps its hardening…
        $this->assertStringContainsString("NoNewPrivileges=true\nPrivateTmp=true", $script);
        $this->assertStringContainsString('PrivateTmp=true', $script);
        $this->assertSame(1, substr_count($script, 'NoNewPrivileges=true'), 'Base unit must keep exactly one NoNewPrivileges=true.');
        // …and the drop-in is the ONLY place NoNewPrivileges=false appears
        // as a directive (the installer's explanatory comments mention it too).
        $this->assertSame(1, substr_count($script, "\nNoNewPrivileges=false\n"), 'The NoNewPrivileges=false directive must appear exactly once, inside the drop-in.');
        $this->assertStringContainsString("\nNoNewPrivileges=false\nPRIVILEGED_HELPERS_EOF", $script);
        $this->assertSame(1, substr_count($script, 'cat > ' . self::DROPIN_PATH), 'The drop-in must be written exactly once (overwrite, not duplicate).');
    }

    // ------------------------------------------------------------------
    // CASE 6: the Server Agent still runs as deployment-agent, not root.
    // ------------------------------------------------------------------

    public function test_installer_runs_the_service_as_deployment_agent_and_never_as_root(): void
    {
        $script = $this->generateInstallerScript(Server::factory()->create());

        $this->assertStringContainsString('SERVICE_USER="deployment-agent"', $script);
        $this->assertStringContainsString('User=$SERVICE_USER', $script);
        $this->assertStringContainsString('Group=$SERVICE_USER', $script);
        $this->assertStringNotContainsString('User=root', $script);
    }

    // ------------------------------------------------------------------
    // CASE 4: existing unrelated systemd drop-ins are never removed.
    // ------------------------------------------------------------------

    public function test_installer_never_removes_drop_ins_or_drop_in_directories(): void
    {
        $script = $this->generateInstallerScript(Server::factory()->create());

        // No recursive removal and no directory removal anywhere under systemd.
        $this->assertStringNotContainsString('rm -rf "/etc/systemd/system', $script);
        $this->assertStringNotContainsString('rmdir "/etc/systemd/system', $script);
        $this->assertStringNotContainsString('rm -f "/etc/systemd/system/${SERVICE_NAME}.service.d/*"', $script);
        $this->assertStringNotContainsString('rm -f "/etc/systemd/system/${SERVICE_NAME}.service.d/99', $script);

        // The rollback removes exactly two things it created: the unit and
        // the exact drop-in file — never any other drop-in.
        $this->assertSame(1, substr_count($script, 'rm -f "/etc/systemd/system/${SERVICE_NAME}.service"'), 'Rollback must remove the unit exactly once.');
        $this->assertSame(1, substr_count($script, 'rm -f "/etc/systemd/system/${SERVICE_NAME}.service.d/'), 'Rollback must remove only the exact drop-in file it created.');
    }

    // ------------------------------------------------------------------
    // CASE 3: re-running the installer is idempotent — the generated
    // systemd install section is deterministic and overwrites (never
    // appends or duplicates).
    // ------------------------------------------------------------------

    public function test_one_click_installer_generation_is_deterministic_for_the_systemd_install_section(): void
    {
        $server = Server::factory()->create();
        $first = $this->generateInstallerScript($server);
        $second = $this->generateInstallerScript($server);

        $startNeedle = 'cat > "/etc/systemd/system/${SERVICE_NAME}.service" <<SYSTEMD_UNIT_EOF';
        $start = strpos($first, $startNeedle);
        $this->assertNotFalse($start, 'Expected the systemd install section to start in the generated script.');
        $end = strpos($first, "\nfi\n", $start);
        $this->assertNotFalse($end, 'Expected the systemd install section to end in the generated script.');
        $sectionLength = $end - $start;

        $this->assertSame(
            substr($first, $start, $sectionLength),
            substr($second, strpos($second, $startNeedle), $sectionLength),
            'The systemd install section must be byte-identical across regenerations — rerunning the installer is deterministic.'
        );
    }

    public function test_one_click_installer_writes_the_drop_in_by_overwrite_never_append(): void
    {
        $script = $this->generateInstallerScript(Server::factory()->create());

        $this->assertStringContainsString('cat > ' . self::DROPIN_PATH . " <<'PRIVILEGED_HELPERS_EOF'", $script);
        $this->assertStringNotContainsString('cat >> ' . self::DROPIN_PATH, $script);
    }

    // ------------------------------------------------------------------
    // CASE 1 + CASE 7: the bootstrap (one-click full-system) installer
    // produces the same effective configuration.
    // ------------------------------------------------------------------

    public function test_bootstrap_installer_creates_the_same_drop_in_before_daemon_reload(): void
    {
        $install = $this->readRepoSource('deployment-bootstrap/install.sh');

        $dropInBlock = 'install -d -o root -g root -m 0755 "/etc/systemd/system/${SERVICE_NAME}.service.d"' . "\n"
            . 'cat > "/etc/systemd/system/${SERVICE_NAME}.service.d/10-privileged-helpers.conf" <<\'EOF\'' . "\n"
            . self::DROPIN_BODY
            . "EOF\n"
            . 'chmod 0644 "/etc/systemd/system/${SERVICE_NAME}.service.d/10-privileged-helpers.conf"' . "\n";

        $this->assertStringContainsString($dropInBlock, $install);

        $unitWrite = strpos($install, '> "$UNIT_PATH"');
        $dropIn = strpos($install, '10-privileged-helpers.conf');
        $daemonReload = strpos($install, 'systemctl daemon-reload');
        $this->assertNotFalse($unitWrite);
        $this->assertNotFalse($dropIn);
        $this->assertNotFalse($daemonReload);
        $this->assertTrue($unitWrite < $dropIn, 'Drop-in must be created after the unit is written.');
        $this->assertTrue($dropIn < $daemonReload, 'Drop-in must be created before daemon-reload so the effective config includes it.');

        // Bootstrap template itself keeps the hardened base (drop-in overrides it).
        $template = $this->readRepoSource('deployment-bootstrap/files/systemd/deployment-agent.service.example');
        $this->assertStringContainsString("NoNewPrivileges=true\nPrivateTmp=true", $template);
    }

    public function test_bootstrap_installer_is_idempotent_and_never_removes_drop_ins(): void
    {
        $install = $this->readRepoSource('deployment-bootstrap/install.sh');

        // Overwrite, never append, never duplicate (the explanatory comment
        // above also mentions NoNewPrivileges=false, so count the directive).
        $this->assertStringContainsString("cat > \"/etc/systemd/system/\${SERVICE_NAME}.service.d/10-privileged-helpers.conf\" <<'EOF'", $install);
        $this->assertSame(1, substr_count($install, "\nNoNewPrivileges=false\n"));
        $this->assertStringNotContainsString('cat >> "/etc/systemd/system/${SERVICE_NAME}.service.d', $install);

        // The installer never removes anything under /etc/systemd/system.
        $this->assertStringNotContainsString('rm -f "/etc/systemd/system', $install);
        $this->assertStringNotContainsString('rm -rf "/etc/systemd/system', $install);
        $this->assertStringNotContainsString('rmdir "/etc/systemd/system', $install);
    }

    public function test_bootstrap_installer_keeps_the_unprivileged_service_user(): void
    {
        $install = $this->readRepoSource('deployment-bootstrap/install.sh');

        $this->assertStringContainsString('INSTALL_DIR="/opt/deployment-server-agent"', $install);
        $this->assertStringContainsString('SERVICE_NAME="deployment-server-agent"', $install);
        $this->assertStringContainsString('SERVICE_USER="deployment-agent"', $install);
        $this->assertStringContainsString('s#CHANGE_ME_DEPLOY_USER#${SERVICE_USER}#g', $install);
        $this->assertStringNotContainsString('User=root', $install);
    }

    // ------------------------------------------------------------------
    // CASE 4: uninstall removes only our exact drop-in and leaves any
    // user-created unrelated drop-ins untouched.
    // ------------------------------------------------------------------

    public function test_bootstrap_uninstaller_removes_only_our_drop_in_and_preserves_user_drop_ins(): void
    {
        $uninstall = $this->readRepoSource('deployment-bootstrap/uninstall.sh');

        $this->assertStringContainsString('DROPIN_PATH="/etc/systemd/system/${SERVICE_NAME}.service.d/10-privileged-helpers.conf"', $uninstall);
        $this->assertStringContainsString('rm -f "$DROPIN_PATH"', $uninstall);
        $this->assertStringContainsString('rmdir "/etc/systemd/system/${SERVICE_NAME}.service.d" 2>/dev/null || true', $uninstall);

        // No wildcard removal and no recursive removal of the drop-in directory.
        $this->assertStringNotContainsString('rm -rf "/etc/systemd/system', $uninstall);
        $this->assertStringNotContainsString('rm -f "/etc/systemd/system/${SERVICE_NAME}.service.d/', $uninstall);
        $this->assertStringNotContainsString('.service.d/"*', $uninstall);
    }

    // ------------------------------------------------------------------
    // CASE 5: the official Server Agent upgrader is isolated from the
    // separately installed Local Agent runtime and service.
    // ------------------------------------------------------------------

    public function test_runtime_upgrade_targets_only_the_server_agent_and_is_atomic(): void
    {
        $upgrade = $this->readRepoSource('agent/install/upgrade-runtime.sh');

        $this->assertStringContainsString('INSTALL_DIR=/opt/deployment-server-agent', $upgrade);
        $this->assertStringContainsString('SERVICE=deployment-server-agent', $upgrade);
        $this->assertStringContainsString('/opt/deployment-agent/workspaces/deployment-system/agent', $upgrade);
        $this->assertStringNotContainsString("INSTALL_DIR=/opt/deployment-agent\n", $upgrade);
        $this->assertStringNotContainsString("SERVICE=deployment-agent\n", $upgrade);
        $this->assertStringContainsString('mv "$INSTALL_DIR" "$BACKUP"', $upgrade);
        $this->assertStringContainsString('mv "$STAGING" "$INSTALL_DIR"', $upgrade);
        $this->assertStringContainsString('trap rollback ERR', $upgrade);
        $this->assertStringContainsString('mv "$BACKUP" "$INSTALL_DIR"', $upgrade);
        $this->assertGreaterThanOrEqual(2, substr_count($upgrade, 'sha256sum "$INSTALL_DIR/config.php"'));
        $this->assertStringContainsString('visudo -cf "$SCRIPT_DIR/deployment-runtime-permissions.sudoers"', $upgrade);
        $this->assertStringContainsString('install -o root -g root -m 0755 "$SCRIPT_DIR/deployment-runtime-permissions" "$RUNTIME_HELPER"', $upgrade);
        $this->assertStringContainsString('install -o root -g root -m 0440 "$SCRIPT_DIR/deployment-runtime-permissions.sudoers" "$RUNTIME_SUDOERS"', $upgrade);
        $this->assertStringContainsString('PRIVILEGED_BACKUP=', $upgrade);
        $this->assertStringContainsString('PRIVILEGED_ACTIVATED=true', $upgrade);
    }

    // ------------------------------------------------------------------
    // CASE 2: the privileged-helper sudo model stays constrained to the
    // exact literal helper commands — no generic sudo, no wildcards.
    // ------------------------------------------------------------------

    public function test_privileged_helper_sudo_model_remains_constrained_to_exact_commands(): void
    {
        $runtimeSudoers = $this->readRepoSource('agent/install/deployment-runtime-permissions.sudoers');
        $reloadSudoers = $this->readRepoSource('agent/install/deployment-service-reload.sudoers');

        // Run-as root is allowed only via NOPASSWD for the literal commands below.
        $this->assertStringContainsString('ALL=(root) NOPASSWD:', $runtimeSudoers);
        $this->assertStringContainsString('ALL=(root) NOPASSWD:', $reloadSudoers);

        $this->assertStringContainsString('/usr/local/sbin/deployment-runtime-permissions deployment-system', $runtimeSudoers);
        $this->assertStringContainsString('/usr/local/sbin/deployment-runtime-permissions saas-platform', $runtimeSudoers);
        $this->assertStringContainsString('/usr/local/sbin/deployment-runtime-permissions customer-site', $runtimeSudoers);
        $this->assertStringContainsString('/usr/local/sbin/deployment-service-reload deployment-system', $reloadSudoers);
        $this->assertStringContainsString('/usr/local/sbin/deployment-service-reload saas-platform', $reloadSudoers);

        // No wildcard command, no catch-all.
        $this->assertStringNotContainsString('*', $runtimeSudoers);
        $this->assertStringNotContainsString('*', $reloadSudoers);
    }
}
