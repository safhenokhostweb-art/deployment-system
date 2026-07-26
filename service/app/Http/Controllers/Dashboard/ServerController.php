<?php

namespace App\Http\Controllers\Dashboard;

use App\Events\CredentialRotated;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\DiscoveryJob;
use App\Models\Project;
use App\Models\Server;
use App\Services\Security\AgentCredentialService;
use App\Support\Operations\OperationRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ServerController extends Controller
{
    /**
     * Bumped whenever buildOneClickInstallerContent()'s actual output
     * changes in a way worth being able to tell apart on a real server —
     * embedded in every generated install-agent.sh (see §16 of
     * docs/ONE_CLICK_AGENT_INSTALLER_REPORT.md) so "which version is
     * actually running on this box" is answerable by reading the
     * installer's own log instead of guessing.
     */
    private const INSTALLER_VERSION = 'devops-hub-v1-ready-4';

    public function __construct(
        private readonly AgentCredentialService $credentials,
    ) {
    }

    public function index()
    {
        Gate::authorize('servers.view');

        $servers = Server::withCount('projects')->latest()->get();

        return view('servers.index', compact('servers'));
    }

    public function create()
    {
        Gate::authorize('servers.create');

        return view('servers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('servers.create');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            // Phase 4-D Step 1: informational only — nothing in the
            // deploy/execution path reads this column.
            'type' => 'nullable|string|in:vps,cpanel,shared_hosting,docker',
        ]);

        $plainToken = $this->credentials->generateToken();
        $plainSecret = $this->credentials->generateSigningSecret();

        $server = Server::create([
            ...$validated,
            'agent_token_hash' => $this->credentials->hashToken($plainToken),
            'signing_secret' => $plainSecret,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'server.registered',
            subjectType: Server::class,
            subjectId: $server->id,
        );

        return redirect()
            ->route('servers.show', $server)
            ->with('generated_token', $plainToken)
            ->with('generated_secret', $plainSecret)
            ->with('status', 'Server registered. Copy the credentials below now — the token and secret are shown only once.');
    }

    public function show(Server $server)
    {
        Gate::authorize('servers.view');

        $server->load([
            'deploymentTargets.environment.project',
        ]);

        // Phase 4-D Steps 3-4: the Wizard's downloadable config.php and
        // install instructions only ever use the SAME one-time
        // session-flashed plaintext the credentials box below already
        // displays — no new storage, no new route, no new way to recover
        // these values after this one page load. Built client-side from
        // this string (see servers/show.blade.php) rather than a separate
        // download endpoint, so there is no second URL that could ever be
        // requested outside this exact session/request scope.
        $agentConfigContent = null;
        $oneClickInstallerContent = null;
        $oneClickInstallerBlockedReason = null;
        if (session()->has('generated_token') && session()->has('generated_secret')) {
            $agentConfigContent = $this->buildAgentConfigContent(
                $server,
                (string) session('generated_token'),
                (string) session('generated_secret'),
            );

            // One-Click Agent Installer: same one-time gate as
            // agentConfigContent above — it embeds the exact same
            // plaintext token/secret, so it can only ever exist on this
            // same one page load. Rotate credentials to get a fresh one
            // if this one is lost before it's used.
            //
            // Generation itself refuses to run for a Hub whose own
            // configured URL is not production-safe (loopback/HTTP)
            // UNLESS this Hub is genuinely running in a local/dev
            // environment — a real server's install-agent.sh must never
            // silently embed a broken HUB_URL it can never actually
            // reach; that failure belongs here, at generation time on
            // the panel, not discovered later on the target server.
            $hubUrl = url('/');
            if ($this->installerHubUrlIsSafe($hubUrl)) {
                $oneClickInstallerContent = $this->buildOneClickInstallerContent(
                    $server,
                    (string) session('generated_token'),
                    (string) session('generated_secret'),
                );
            } else {
                $oneClickInstallerBlockedReason = __('messages.errors.installer_hub_url_not_safe', ['url' => $hubUrl]);
            }
        }

        // Phase 4-L Task 3: Server Setup Wizard. Contains no secret of any
        // kind (unlike agentConfigContent above), so — unlike that one —
        // it's always available, not just on the one page load right
        // after registration/rotation; a "ready install command" is only
        // useful if it's still there the next time the operator comes
        // back to actually run it.
        $agentServiceFileContent = $this->buildAgentServiceFileContent($server);

        // Persistent step tracker (Task 3's own wording: the wizard should
        // always show where setup currently stands, not just once right
        // after creation) — steps 1-2 (info + credentials) are already
        // complete the instant the row exists; step 3 (install) is the
        // first one that can actually still be pending.
        $setupStep = $server->isOnline() ? 5 : ($server->last_seen_at !== null ? 4 : 3);

        // Phase 4-H Task 8: Discovery is scoped to a specific Agent, not
        // the Server row itself — only offered when there's exactly one
        // approved Agent to run it, same "sole approved agent" rule
        // ProjectController's wizard already uses for auto-picking a
        // DeploymentTarget's agent.
        $discoveryAgent = $server->agents()->where('status', Agent::STATUS_APPROVED)->first();
        $latestDiscoveryJob = $discoveryAgent
            ? DiscoveryJob::where('agent_id', $discoveryAgent->id)->latest()->first()
            : null;

        // Phase 4-K Task 7: same "sole approved agent" pattern Discovery
        // already uses above — operations run through exactly one agent.
        $operationsAgent = $discoveryAgent;
        $operationsProject = $operationsAgent ? $server->soleProject() : null;
        $laravelOperationTypes = OperationRegistry::laravelGroupTypes();
        $serverOperationTypes = OperationRegistry::serverGroupTypes();
        $recentOperations = $server->operationJobs()->latest()->take(10)->get();

        return view('servers.show', compact(
            'server', 'agentConfigContent', 'agentServiceFileContent', 'oneClickInstallerContent', 'oneClickInstallerBlockedReason', 'setupStep', 'discoveryAgent', 'latestDiscoveryJob',
            'operationsAgent', 'operationsProject', 'laravelOperationTypes', 'serverOperationTypes', 'recentOperations',
        ));
    }

    public function rotateToken(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('servers.rotate-credentials');

        $plainToken = $this->credentials->generateToken();
        $plainSecret = $this->credentials->generateSigningSecret();

        $server->update([
            'agent_token_hash' => $this->credentials->hashToken($plainToken),
            'signing_secret' => $plainSecret,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'server.credentials_rotated',
            subjectType: Server::class,
            subjectId: $server->id,
        );

        // Phase 4-J: pure instrumentation for NotificationEventSubscriber.
        // No credential value is ever passed — see CredentialRotated's own
        // docblock (Task 11).
        event(new CredentialRotated($server, $request->user()->email));

        return redirect()
            ->route('servers.show', $server)
            ->with('generated_token', $plainToken)
            ->with('generated_secret', $plainSecret)
            ->with('status', 'Credentials rotated. Update config.php on that server now — the old credentials stop working immediately.');
    }

    public function destroy(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('servers.delete');

        // projects/deployments/agents/deployment_targets all
        // ->restrictOnDelete() this server at the schema level — this
        // check exists to turn that into a friendly, translated redirect
        // instead of a raw QueryException, same convention as
        // DeploymentTargetController::destroy(). operation_jobs and
        // agent_request_nonces cascadeOnDelete and need no such check.
        abort_if(
            $server->projects()->exists()
                || $server->deployments()->exists()
                || $server->agents()->exists()
                || $server->deploymentTargets()->exists(),
            409,
            __('messages.errors.server_has_dependents')
        );

        $name = $server->name;

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'server.deleted',
            subjectType: Server::class,
            subjectId: $server->id,
        );

        $server->delete();

        return redirect()->route('servers.index')
            ->with('status', __('messages.flash.server_deleted', ['name' => $name]));
    }

    private function buildAgentConfigContent(Server $server, string $plainToken, string $plainSecret): string
    {
        // Deliberately mirrors agent/config.example.php's exact shape so
        // this file can be renamed straight to config.php on the target
        // server with no manual editing beyond the projects map.
        $lines = [
            "<?php",
            "",
            "return [",
            "    'service_base_url' => '" . addslashes(url('/')) . "',",
            "    'server_id' => '" . addslashes($server->public_id) . "',",
            "    'agent_token' => '" . addslashes($plainToken) . "',",
            "    'signing_secret' => '" . addslashes($plainSecret) . "',",
            "    'poll_interval_seconds' => 15,",
            "    'request_timeout_seconds' => 10,",
            "    'default_step_timeout_seconds' => 900,",
            "    'projects' => [",
            "        // 'project_key' => [",
            "        //     'working_directory' => '/home/example/public_html',",
            "        //     'php_binary' => 'php',",
            "        // ],",
            "    ],",
            "    'log_dir' => __DIR__ . '/storage/logs',",
            "];",
            "",
        ];

        return implode("\n", $lines);
    }

    /**
     * Phase 4-L Task 3: "give a ready install command" — mirrors
     * agent/install/deployment-agent.service.example's exact shape (kept
     * as a literal template here rather than reading that file at
     * runtime, same convention buildAgentConfigContent() above already
     * uses for config.example.php). Contains no secret — the token/
     * secret live only in the separately-downloaded config.php — so
     * unlike that method this one is safe to regenerate on every page
     * load, not just once right after registration.
     */
    private function buildAgentServiceFileContent(Server $server): string
    {
        $lines = [
            "[Unit]",
            "Description=Deployment Agent for {$server->name} (polls Deployment Service, no inbound access required)",
            "After=network-online.target",
            "Wants=network-online.target",
            "",
            "[Service]",
            "Type=simple",
            "# EDIT ME: unprivileged user that owns the project working directories",
            "User=CHANGE_ME_DEPLOY_USER",
            "# EDIT ME: absolute path where you placed the agent/ directory + config.php on this server",
            "WorkingDirectory=/CHANGE_ME/path/to/deployment-system/agent",
            "ExecStart=/usr/bin/php /CHANGE_ME/path/to/deployment-system/agent/agent.php",
            "Restart=always",
            "RestartSec=5",
            "",
            "NoNewPrivileges=true",
            "PrivateTmp=true",
            "",
            "[Install]",
            "WantedBy=multi-user.target",
            "",
        ];

        return implode("\n", $lines);
    }

    /**
     * A real server's install-agent.sh embeds HUB_URL verbatim and the
     * Agent will try to reach it forever — a loopback/HTTP address is
     * only ever meaningful on the machine that's actually running the
     * Hub itself (this app's own local development), never on a real
     * target server. `app.env` (not app()->environment(), which is
     * cached on the container at boot and won't reflect a test
     * overriding config() at runtime) is this app's own signal for
     * "this really is a local/dev Hub" — in every other environment, a
     * loopback or non-HTTPS URL means the operator's real APP_URL isn't
     * configured yet, and generating a broken installer for it would
     * just move the failure from here (with a clear reason) to the
     * target server (as a mysterious unreachable-Hub timeout).
     */
    private function installerHubUrlIsSafe(string $hubUrl): bool
    {
        if (in_array(config('app.env'), ['local', 'development', 'testing'], true)) {
            return true;
        }

        $isHttps = str_starts_with($hubUrl, 'https://');
        $isLoopbackHost = (bool) preg_match(
            '#^https?://(localhost|127(?:\.\d{1,3}){3}|0\.0\.0\.0|\[::1\])(:\d+)?/?$#i',
            $hubUrl
        );

        return $isHttps && !$isLoopbackHost;
    }

    /**
     * One-Click Agent Installer. Everything below is generation of a
     * plain bash script string — no new Hub route, no new Agent Protocol,
     * no change to how an Agent authenticates. The script embeds:
     *  (a) the Agent's own current source (agent.php + lib/*.php), read
     *      straight from the sibling agent/ directory so it can never
     *      drift from what this exact Hub build actually ships, each
     *      with a sha256 checksum computed here and verified after the
     *      script writes it out on the target server;
     *  (b) a config.php built the same way buildAgentConfigContent()
     *      already does, except the 'projects' map is pre-filled with
     *      this server's real project_keys — the Hub still never learns
     *      or stores a real filesystem path (see collectProjectKeysFor()),
     *      so the installer creates a placeholder working_directory per
     *      project under /opt/deployment-agent/projects/{key} and the
     *      operator drops real code there later; that's Task 5/6's actual
     *      deployment concern, out of scope for "install the Agent".
     * Only ever built alongside agentConfigContent above (same one-time
     * session-flash gate) since it contains the exact same plaintext
     * credentials.
     */
    private function buildOneClickInstallerContent(Server $server, string $plainToken, string $plainSecret): string
    {
        $agentSourceDir = base_path('../agent');
        abort_unless(is_dir($agentSourceDir), 500, 'Agent source directory not found next to the Hub — cannot build the one-click installer.');

        $sourceFiles = ['agent.php' => $agentSourceDir . '/agent.php'];
        foreach (glob($agentSourceDir . '/lib/*.php') ?: [] as $libFile) {
            $sourceFiles['lib/' . basename($libFile)] = $libFile;
        }

        $fileBlocks = '';
        foreach ($sourceFiles as $relativePath => $absolutePath) {
            // Bug found via real-Ubuntu testing: this template always
            // appended its own "\n" before the closing delimiter. Every
            // real source file already ends with a trailing newline, so
            // the heredoc bash actually captures was the file's own
            // content PLUS one extra blank line — a file reconstructed
            // one byte longer than the one the checksum below was
            // computed from, so verify_checksum failed by construction,
            // on every install, regardless of platform or line endings.
            // Fixed by normalizing to a single well-defined trailing
            // newline (and CRLF -> LF, in case a Windows checkout of
            // this repo ever has them) BEFORE computing the checksum, so
            // the checksum always describes the exact bytes the heredoc
            // below will actually reproduce — not whatever the source
            // file's own incidental whitespace happens to be.
            $contents = (string) file_get_contents($absolutePath);
            $contents = str_replace(["\r\n", "\r"], "\n", $contents);
            $contents = rtrim($contents, "\n") . "\n";

            $checksum = hash('sha256', $contents);
            $delimiter = 'AGENT_SRC_' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $relativePath)) . '_EOF';

            // Practically impossible for real PHP source to contain this
            // exact line, but a silently-mangled embed is worse than a
            // loud build-time failure — never trust it blindly.
            abort_if(str_contains($contents, $delimiter), 500, "Cannot safely embed {$relativePath} in the installer.");

            // $contents already ends in exactly one "\n", so the heredoc
            // body is content-then-delimiter-line with no extra blank
            // line in between — what verify_checksum() checks below is
            // exactly what gets written, byte for byte.
            $fileBlocks .= "cat > \"\$INSTALL_DIR/{$relativePath}\" <<'{$delimiter}'\n{$contents}{$delimiter}\n";
            $fileBlocks .= "verify_checksum \"\$INSTALL_DIR/{$relativePath}\" \"{$checksum}\" \"{$relativePath}\"\n\n";
        }

        $projectKeys = $this->collectProjectKeysFor($server);
        $projectConfigLines = '';
        $projectDirLines = '';
        foreach ($projectKeys as $projectKey) {
            $projectConfigLines .= "        '{$projectKey}' => ['working_directory' => '/opt/deployment-agent/projects/{$projectKey}', 'php_binary' => '__DETECTED_PHP_BIN__'],\n";
            $projectDirLines .= "mkdir -p \"\$INSTALL_DIR/projects/{$projectKey}\"\n";
        }

        $configPhp = "<?php\n\nreturn [\n"
            . "    'service_base_url' => '" . addslashes(url('/')) . "',\n"
            . "    'server_id' => '" . addslashes($server->public_id) . "',\n"
            . "    'agent_token' => '" . addslashes($plainToken) . "',\n"
            . "    'signing_secret' => '" . addslashes($plainSecret) . "',\n"
            . "    'poll_interval_seconds' => 15,\n"
            . "    'request_timeout_seconds' => 10,\n"
            . "    'default_step_timeout_seconds' => 900,\n"
            . "    'projects' => [\n"
            . $projectConfigLines
            . "    ],\n"
            . "    'log_dir' => __DIR__ . '/storage/logs',\n"
            . "];\n";

        $safeServerName = str_replace(["\n", "\r"], ' ', $server->name);

        $script = <<<'BASH'
#!/usr/bin/env bash
# One-Click Agent Installer — generated by the Deployment Service for
# "__SERVER_NAME__" (server_id: __SERVER_PUBLIC_ID__)
# Generated: __GENERATED_AT__
# Installer version: __INSTALLER_VERSION__
#
# This file embeds one-time credentials for this one server. It is only
# ever shown once on the panel and cannot be re-downloaded with the same
# credentials — if you lose it before running it, rotate this server's
# credentials on the panel to get a fresh one. It deletes itself
# automatically after a successful install.
#
# Expected usage (exactly these two commands, nothing else):
#   chmod +x install-agent.sh
#   sudo ./install-agent.sh

set -euo pipefail

INSTALLER_VERSION="__INSTALLER_VERSION__"
INSTALL_DIR="/opt/deployment-agent"
SERVICE_NAME="deployment-agent"
SERVICE_USER="deployment-agent"
INSTALLER_LOG="/tmp/deployment-agent-install.log"
SCRIPT_PATH="$(readlink -f "$0" 2>/dev/null || echo "$0")"
HUB_URL="__SERVICE_BASE_URL__"

# --- Phase A: prerequisite checks (nothing created yet, plain exit) ---

if [ "$(id -u)" -ne 0 ]; then
    echo "این installer باید با sudo اجرا شود: sudo ./install-agent.sh"
    exit 1
fi

if [ "$(uname -s)" != "Linux" ]; then
    echo "این installer فقط از Linux پشتیبانی می‌کند."
    exit 1
fi

if ! command -v systemctl >/dev/null 2>&1; then
    echo "systemd روی این سیستم پیدا نشد. این installer فقط از توزیع‌های مبتنی بر systemd پشتیبانی می‌کند."
    exit 1
fi

PHP_BIN=""
for candidate in php php8.3 php8.4 php8.2 php8.1; do
    if command -v "$candidate" >/dev/null 2>&1; then
        PHP_BIN="$(command -v "$candidate")"
        break
    fi
done
if [ -z "$PHP_BIN" ]; then
    echo "PHP CLI پیدا نشد. لطفاً ابتدا PHP 8.1 یا بالاتر را نصب کنید (مثال روی Debian/Ubuntu: apt install php-cli php-curl) و دوباره ./install-agent.sh را اجرا کنید."
    exit 1
fi
# Captured into a variable first, not piped directly into `| grep -q`:
# under set -o pipefail, grep -q exits the instant it finds a match and
# closes its end of the pipe — if php -m is still writing later module
# names at that moment, it can get SIGPIPE (exit 141), which pipefail
# then reports as the pipeline's own failure even though grep DID find
# curl. Capturing to a variable runs php -m to completion with no
# reader at all, so there is nothing to race.
PHP_MODULES="$("$PHP_BIN" -m 2>/dev/null)"
if ! printf '%s\n' "$PHP_MODULES" | grep -qi '^curl$'; then
    echo "افزونه php-curl روی این سیستم فعال نیست. آن را نصب کنید (مثال: apt install php-curl) و دوباره اجرا کنید."
    exit 1
fi

case "$HUB_URL" in
    https://*) ;;
    http://localhost*|http://127.0.0.1*)
        echo "هشدار: آدرس Hub روی HTTP محلی است — این فقط برای محیط توسعه قابل قبول است، نه برای سرور واقعی." ;;
    *)
        echo "خطا: آدرس Hub باید HTTPS باشد ($HUB_URL). نصب برای حفظ امنیت اعتبارنامه‌های Agent متوقف شد."
        exit 1 ;;
esac

# --- Phase B: from here on state gets created, so failures roll back ---

FRESH_INSTALL=1
EXISTING_UNIT_FILES="$(systemctl list-unit-files 2>/dev/null)"
if printf '%s\n' "$EXISTING_UNIT_FILES" | grep -q "^${SERVICE_NAME}\.service"; then
    FRESH_INSTALL=0
fi

CREATED_USER=0
STARTED_NEW_INSTALL=0

say() {
    echo "$1"
    echo "$1" >> "$INSTALLER_LOG"
}

cleanup_on_failure() {
    if [ "$FRESH_INSTALL" = "1" ] && [ "$STARTED_NEW_INSTALL" = "1" ]; then
        say "در حال rollback نصب ناقص..."
        systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
        systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true
        rm -f "/etc/systemd/system/${SERVICE_NAME}.service"
        systemctl daemon-reload >/dev/null 2>&1 || true
        rm -rf "$INSTALL_DIR"
        if [ "$CREATED_USER" = "1" ]; then
            userdel "$SERVICE_USER" >/dev/null 2>&1 || true
        fi
        say "rollback کامل شد — سیستم به حالت قبل از اجرای این installer بازگشت."
    else
        say "یک نصب قبلی روی این سرور از قبل وجود داشت و دست‌نخورده باقی ماند — فقط این تلاش برای به‌روزرسانی آن ناموفق بود."
    fi
}

fail() {
    say ""
    say "خطا: $1"
    cleanup_on_failure
    say "لاگ کامل نصب: $INSTALLER_LOG"
    say "برای تلاش مجدد: sudo ./install-agent.sh"
    exit 1
}

trap 'fail "خطای غیرمنتظره در خط ${LINENO}"' ERR

: > "$INSTALLER_LOG"
say "=== شروع نصب Agent — $(date '+%Y-%m-%d %H:%M:%S') ==="
say "نسخه Installer: $INSTALLER_VERSION"
say "PHP یافت شد: $PHP_BIN"
STARTED_NEW_INSTALL=1

if ! id "$SERVICE_USER" >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
    CREATED_USER=1
    say "کاربر سیستمی $SERVICE_USER ایجاد شد."
else
    say "کاربر سیستمی $SERVICE_USER از قبل وجود دارد."
fi

mkdir -p "$INSTALL_DIR/lib" "$INSTALL_DIR/storage/logs"

verify_checksum() {
    local path="$1" expected="$2" label="$3" actual
    actual="$(sha256sum "$path" | awk '{print $1}')"
    if [ "$actual" != "$expected" ]; then
        say "Expected SHA256 ($label): $expected"
        say "Actual SHA256   ($label): $actual"
        fail "بررسی صحت فایل $label ناموفق بود — فایل نصب‌کننده ممکن است در کپی خراب شده باشد. آن را دوباره از پنل دانلود کنید."
    fi
}

__AGENT_SOURCE_FILE_BLOCKS__

cat > "$INSTALL_DIR/config.php" <<'AGENT_CONFIG_EOF'
__AGENT_CONFIG_PHP__
AGENT_CONFIG_EOF
sed -i "s#__DETECTED_PHP_BIN__#$PHP_BIN#g" "$INSTALL_DIR/config.php"
chmod 600 "$INSTALL_DIR/config.php"
say "config.php ساخته شد."

__AGENT_PROJECT_DIR_LINES__

chown -R "$SERVICE_USER":"$SERVICE_USER" "$INSTALL_DIR"
chmod 750 "$INSTALL_DIR"

say "در حال اجرای یک poll آزمایشی برای تایید اتصال به Hub..."
VERIFY_OUTPUT="$(sudo -u "$SERVICE_USER" "$PHP_BIN" "$INSTALL_DIR/agent.php" --once 2>&1 || true)"
echo "$VERIFY_OUTPUT" >> "$INSTALLER_LOG"
if printf '%s\n' "$VERIFY_OUTPUT" | grep -qE '\] (ERROR|WARN) '; then
    say "خروجی آزمایشی:"
    say "$VERIFY_OUTPUT"
    fail "اتصال آزمایشی به Hub ناموفق بود — آدرس Hub یا اعتبارنامه‌ها را بررسی کنید."
fi
say "اتصال آزمایشی موفق بود."

cat > "/etc/systemd/system/${SERVICE_NAME}.service" <<SYSTEMD_UNIT_EOF
[Unit]
Description=Deployment Agent for __SAFE_SERVER_NAME__ (one-click installer)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_USER
WorkingDirectory=$INSTALL_DIR
ExecStart=$PHP_BIN $INSTALL_DIR/agent.php
Restart=always
RestartSec=5

NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
SYSTEMD_UNIT_EOF

systemctl daemon-reload
systemctl enable "$SERVICE_NAME" >/dev/null 2>&1
systemctl restart "$SERVICE_NAME"

sleep 2
if ! systemctl is-active --quiet "$SERVICE_NAME"; then
    fail "سرویس $SERVICE_NAME بالا نیامد. journalctl -u $SERVICE_NAME -n 50 --no-pager را بررسی کنید."
fi

trap - ERR

echo ""
echo "Agent با موفقیت نصب و متصل شد."
echo "وضعیت سرویس: فعال"
echo "اتصال به Hub: موفق"
echo "اکنون به پنل برگردید."
echo ""
echo "این فایل نصب‌کننده اکنون حذف می‌شود (چون شامل اطلاعات محرمانه این سرور بود)."

shred -u "$SCRIPT_PATH" 2>/dev/null || rm -f "$SCRIPT_PATH"
BASH;

        return strtr($script, [
            '__SERVER_NAME__' => $safeServerName,
            '__SAFE_SERVER_NAME__' => $safeServerName,
            '__SERVER_PUBLIC_ID__' => $server->public_id,
            '__GENERATED_AT__' => now()->toDateTimeString(),
            '__INSTALLER_VERSION__' => self::INSTALLER_VERSION,
            '__SERVICE_BASE_URL__' => url('/'),
            '__AGENT_SOURCE_FILE_BLOCKS__' => rtrim($fileBlocks),
            '__AGENT_CONFIG_PHP__' => rtrim($configPhp),
            '__AGENT_PROJECT_DIR_LINES__' => $projectDirLines === '' ? ': # no projects on this server yet' : rtrim($projectDirLines),
        ]);
    }

    /**
     * Every project_key this server currently serves, via either the
     * legacy direct link or the new-domain Environment/DeploymentTarget
     * path — same two-source union Server::soleProject() already
     * resolves, except every match is wanted here, not just a sole one.
     *
     * @return array<int, string>
     */
    private function collectProjectKeysFor(Server $server): array
    {
        $legacyKeys = $server->projects()->pluck('project_key');
        $newDomainKeys = Project::whereHas(
            'environments.deploymentTargets',
            fn ($query) => $query->where('server_id', $server->id)
        )->pluck('project_key');

        return $legacyKeys->merge($newDomainKeys)->unique()->values()->all();
    }
}
