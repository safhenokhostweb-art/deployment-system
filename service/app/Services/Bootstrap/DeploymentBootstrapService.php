<?php

namespace App\Services\Bootstrap;

use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubAppClient;
use App\Services\Security\AgentCredentialService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backs `php artisan deployment:bootstrap`. Every record this creates
 * goes through the exact same models/relationships/constants the
 * dashboard controllers already use (ServerController::store(),
 * AgentController::approve(), ProjectController::store()/
 * createEnvironmentAndTarget(), GitHubAppClient::createWebhook()) —
 * this class is an orchestration layer over that existing logic for a
 * console/non-interactive context, not a second way of writing these
 * rows. No raw SQL, no bypassing model events/casts.
 *
 * Idempotency contract (see docs/DEPLOYMENT_BOOTSTRAP_REPORT.md):
 *  - Server: found by `name`. Project: found by the unique `project_key`.
 *    Environment: found by the unique (project_id, key). DeploymentTarget:
 *    found by the unique (environment_id, name). Agent: the Server's
 *    existing agent, if any (preferring an approved one).
 *  - A secret (agent_token/signing_secret/github_webhook_secret) is
 *    generated ONLY the moment its owning row is first created, never
 *    regenerated on a later run that finds the row already existing —
 *    agent_token is a one-way hash the instant it's created, so
 *    "rotating" it on a second run would silently break an
 *    already-installed Agent with no way to recover the old value.
 *  - A handful of purely descriptive fields (Project name/description/
 *    repository/branch, DeploymentTarget.agent_id) ARE synced on every
 *    run and reported when they change — safe because none of them are
 *    secrets and all of them are meant to reflect config.env's current
 *    content.
 */
class DeploymentBootstrapService
{
    public function __construct(
        private readonly AgentCredentialService $credentials,
        private readonly GitHubAppClient $github,
        private readonly GitRepositoryInspector $git,
    ) {
    }

    /**
     * Deliberately runs BEFORE the DB transaction in run() even opens —
     * a dangerous working tree is a reason to touch nothing at all, not
     * a reason to roll back a transaction after the fact.
     */
    public function checkRepository(DeploymentBootstrapConfig $config, bool $nonInteractive): GitRepositoryStatus
    {
        $status = $this->git->inspect($config->repositoryPath);

        if (!$status->exists) {
            return $status; // nothing to validate yet — fine, see class docblock
        }

        if (!$status->isClean && $nonInteractive) {
            $files = implode("\n  - ", $status->changedFiles);
            throw new DeploymentBootstrapException(
                "Working Tree در {$config->repositoryPath} تغییرات ذخیره‌نشده دارد و در حالت non-interactive نمی‌توان ادامه داد (اجرای بعدی Pipeline با git reset --hard این تغییرات را برای همیشه پاک می‌کند):\n  - {$files}\nابتدا این تغییرات را commit/stash کنید یا دور بریزید، سپس دوباره اجرا کنید."
            );
        }

        $expectedRemotes = array_filter([$config->repositorySshUrl, $config->repositoryHttpsUrl]);
        if ($status->remoteUrl !== null && $expectedRemotes !== [] && !in_array($status->remoteUrl, $expectedRemotes, true) && $nonInteractive) {
            throw new DeploymentBootstrapException(
                "Remote origin در {$config->repositoryPath} برابر «{$status->remoteUrl}» است، نه یکی از مقادیر مورد انتظار در تنظیمات (REPOSITORY_SSH_URL/REPOSITORY_HTTPS_URL)."
            );
        }

        if ($status->currentBranch !== null && $status->currentBranch !== $config->deployBranch && $nonInteractive) {
            throw new DeploymentBootstrapException(
                "Branch فعلی «{$status->currentBranch}» است، نه «{$config->deployBranch}» که در DEPLOY_BRANCH تنظیم شده."
            );
        }

        return $status;
    }

    public function run(DeploymentBootstrapConfig $config, ?string $adminEmail): DeploymentBootstrapReport
    {
        $actorIdentifier = $this->resolveActorIdentifier($adminEmail);

        return DB::transaction(function () use ($config, $actorIdentifier) {
            $notices = [];
            $differences = [];

            [$server, $serverCreated, $plainToken, $plainSecret] = $this->findOrCreateServer($config, $actorIdentifier);
            [$agent, $agentCreated] = $this->findOrCreateAgent($server, $config, $actorIdentifier);
            [$project, $projectCreated, $plainWebhookSecret, $projectDifferences] = $this->findOrCreateProject($config, $server, $actorIdentifier);
            array_push($differences, ...$projectDifferences);

            [$environment, $environmentCreated] = $this->findOrCreateEnvironment($project, $config, $actorIdentifier);
            [$target, $targetCreated, $targetNotices] = $this->findOrCreateDeploymentTarget($environment, $server, $agent, $config, $actorIdentifier);
            array_push($notices, ...$targetNotices);

            $webhookUrl = route('webhooks.github', $project->project_key);
            $webhookSecretForApi = $plainWebhookSecret; // only non-null the run that just created the Project
            $webhookAutoCreated = false;
            $webhookAutoCreateError = null;

            if ($config->githubToken !== null && $config->autoCreateGithubWebhook) {
                // A re-run with a token but no freshly-generated secret can't
                // register a webhook (github_webhook_secret is encrypted at
                // rest — Project's own $hidden — and, like agent_token, is
                // never re-exposed after creation; the operator would need
                // to rotate it via the dashboard first if they truly want
                // auto-creation after the fact).
                if ($webhookSecretForApi === null) {
                    $notices[] = 'GITHUB_TOKEN تنظیم شده، اما چون این پروژه از قبل وجود داشت و Secret آن قابل بازیابی نیست، Webhook خودکار ساخته نشد. برای ساخت خودکار، ابتدا Secret را از پنل Rotate کنید یا Webhook را دستی در گیت‌هاب اضافه کنید.';
                } else {
                    [$webhookAutoCreated, $webhookAutoCreateError] = $this->maybeCreateGithubWebhook($config, $webhookUrl, $webhookSecretForApi);
                }
            }

            return new DeploymentBootstrapReport(
                server: $server,
                serverWasCreated: $serverCreated,
                plainAgentToken: $plainToken,
                plainSigningSecret: $plainSecret,
                agent: $agent,
                agentWasCreated: $agentCreated,
                project: $project,
                projectWasCreated: $projectCreated,
                plainWebhookSecret: $plainWebhookSecret,
                webhookUrl: $webhookUrl,
                environment: $environment,
                environmentWasCreated: $environmentCreated,
                target: $target,
                targetWasCreated: $targetCreated,
                webhookAutoCreated: $webhookAutoCreated,
                webhookAutoCreateError: $webhookAutoCreateError,
                notices: $notices,
                configuredDifferences: $differences,
            );
        });
    }

    /**
     * Console context has no logged-in user — --admin-email is an
     * optional pointer to an EXISTING admin, purely for a meaningful
     * AuditLogEntry.actor_identifier, validated so a typo'd email can
     * never silently misattribute the audit trail to someone who wasn't
     * actually involved.
     */
    private function resolveActorIdentifier(?string $adminEmail): string
    {
        if ($adminEmail === null) {
            return 'deployment:bootstrap';
        }

        $user = User::where('email', $adminEmail)->first();
        if ($user === null) {
            throw new DeploymentBootstrapException("کاربری با ایمیل «{$adminEmail}» پیدا نشد.");
        }

        if (!$user->isAdmin()) {
            throw new DeploymentBootstrapException("کاربر «{$adminEmail}» نقش Admin ندارد — Bootstrap فقط با ایمیل یک مدیر مجاز است.");
        }

        return $user->email;
    }

    /**
     * @return array{0: Server, 1: bool, 2: ?string, 3: ?string}
     */
    private function findOrCreateServer(DeploymentBootstrapConfig $config, string $actorIdentifier): array
    {
        $server = Server::where('name', $config->serverName)->first();
        if ($server !== null) {
            return [$server, false, null, null];
        }

        $plainToken = $this->credentials->generateToken();
        $plainSecret = $this->credentials->generateSigningSecret();

        $description = $config->serverIp !== null ? "IP: {$config->serverIp}" : null;

        $server = Server::create([
            'name' => $config->serverName,
            'description' => $description,
            'type' => Server::TYPE_VPS,
            'agent_token_hash' => $this->credentials->hashToken($plainToken),
            'signing_secret' => $plainSecret,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: $actorIdentifier,
            action: 'server.registered',
            subjectType: Server::class,
            subjectId: $server->id,
            metadata: ['source' => 'deployment:bootstrap'],
        );

        return [$server, true, $plainToken, $plainSecret];
    }

    /**
     * @return array{0: Agent, 1: bool}
     */
    private function findOrCreateAgent(Server $server, DeploymentBootstrapConfig $config, string $actorIdentifier): array
    {
        $agent = Agent::where('server_id', $server->id)
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 WHEN status = 'pending_approval' THEN 1 ELSE 2 END")
            ->first();

        if ($agent !== null) {
            if ($agent->status === Agent::STATUS_APPROVED) {
                return [$agent, false];
            }

            // A pending/disabled agent found on a re-run is approved here
            // too — same one-time-install intent as a brand-new Agent,
            // just resuming instead of starting over. Credentials are
            // (re)issued exactly like AgentController::approve() does for
            // a pending agent; a disabled one keeps its existing
            // credentials (re-enabling, not re-approving from scratch).
            if ($agent->status === Agent::STATUS_PENDING_APPROVAL) {
                return $this->approveAgent($agent, $actorIdentifier, reissueCredentials: true);
            }

            return $this->approveAgent($agent, $actorIdentifier, reissueCredentials: false);
        }

        $agent = Agent::create([
            'server_id' => $server->id,
            'status' => Agent::STATUS_PENDING_APPROVAL,
            'name' => $config->agentName,
            'os' => 'linux',
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: $actorIdentifier,
            action: 'agent.registration_token_issued',
            subjectType: Agent::class,
            subjectId: $agent->id,
            metadata: ['server_id' => $server->id, 'source' => 'deployment:bootstrap'],
        );

        return $this->approveAgent($agent, $actorIdentifier, reissueCredentials: true);
    }

    /**
     * @return array{0: Agent, 1: bool}
     */
    private function approveAgent(Agent $agent, string $actorIdentifier, bool $reissueCredentials): array
    {
        $wasPendingOrNew = $agent->status !== Agent::STATUS_APPROVED;

        $attributes = ['status' => Agent::STATUS_APPROVED, 'approved_by' => $actorIdentifier, 'approved_at' => now()];

        if ($reissueCredentials) {
            $attributes['agent_token_hash'] = $this->credentials->hashToken($this->credentials->generateToken());
            $attributes['signing_secret'] = $this->credentials->generateSigningSecret();
        }

        $agent->update($attributes);

        if ($wasPendingOrNew) {
            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_SYSTEM,
                actorIdentifier: $actorIdentifier,
                action: 'agent.approved',
                subjectType: Agent::class,
                subjectId: $agent->id,
                metadata: ['source' => 'deployment:bootstrap', 'auto_approved_for_initial_install' => true],
            );
        }

        return [$agent->refresh(), $wasPendingOrNew];
    }

    /**
     * @return array{0: Project, 1: bool, 2: ?string, 3: array<int, string>}
     */
    private function findOrCreateProject(DeploymentBootstrapConfig $config, Server $server, string $actorIdentifier): array
    {
        $project = Project::where('project_key', $config->projectKey)->first();

        if ($project !== null) {
            $differences = [];
            $updates = [];

            foreach ([
                'name' => $config->projectName,
                'repository' => $config->repository(),
                'branch' => $config->deployBranch,
            ] as $field => $desiredValue) {
                if ($project->{$field} !== $desiredValue) {
                    $differences[] = "پروژه: {$field} از «{$project->{$field}}» به «{$desiredValue}» به‌روزرسانی شد.";
                    $updates[$field] = $desiredValue;
                }
            }

            if ($updates !== []) {
                $project->update($updates);
            }

            return [$project, false, null, $differences];
        }

        $plainWebhookSecret = Str::random(40);

        $project = Project::create([
            'project_key' => $config->projectKey,
            'name' => $config->projectName,
            'repository' => $config->repository(),
            'branch' => $config->deployBranch,
            'environment' => strtolower($config->environmentKey),
            'server_id' => $server->id,
            'pipeline' => $this->buildPipeline($config),
            'auto_deploy' => false,
            'github_webhook_secret' => $plainWebhookSecret,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: $actorIdentifier,
            action: 'project.created',
            subjectType: Project::class,
            subjectId: $project->id,
            metadata: ['source' => 'deployment:bootstrap'],
        );

        return [$project, true, $plainWebhookSecret, []];
    }

    /**
     * Task 10's exact pipeline, expressed with the same {name, command}
     * shape and {branch}/{commit}/{php_binary} placeholders
     * ProjectController::PIPELINE_PRESET_LARAVEL already uses — the only
     * difference is this project's repository root and its Laravel
     * subdirectory being two different paths, so git steps run at
     * REPOSITORY_PATH and every artisan/composer/permission step
     * explicitly `cd`s into LARAVEL_PATH first (Executor::run() gives
     * every step the SAME single working_directory, so which one that is
     * has to be chosen per command rather than assumed).
     *
     * `php artisan down`/`up` are deliberately wrapped with `|| true` on
     * the down side only — a failure entering maintenance mode should
     * not abort the deploy, but a failure leaving it should be visible
     * (silently swallowing that would leave the site down with no error).
     *
     * When the Hub attaches a matching Local-Agent-built artifact, the
     * maintenance entry is marked `before_activation` so the agent runs it
     * against the CURRENT release before the atomic swap, and `git pull`
     * is skipped entirely (the artifact already contains the exact
     * source/vendor/build for {commit} — resetting the repo checkout after
     * activation would only fight the freshly-activated release).
     *
     * @return array<int, array{name: string, command: string, before_activation?: bool, skip_when_artifact?: bool}>
     */
    public function buildPipeline(DeploymentBootstrapConfig $config): array
    {
        $steps = [
            ['name' => 'maintenance mode', 'command' => "cd {$config->laravelPath} && {$config->phpBinary} artisan down || true", 'before_activation' => true],
            ['name' => 'git pull', 'command' => "cd {$config->repositoryPath} && git fetch origin {branch} && git reset --hard {commit}", 'skip_when_artifact' => true],
        ];

        if ($config->runComposerInstall) {
            $steps[] = [
                'name' => 'composer install',
                'command' => "cd {$config->laravelPath} && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader",
            ];
        }

        $steps[] = ['name' => 'migrate', 'command' => "cd {$config->laravelPath} && {$config->phpBinary} artisan migrate --force"];
        $steps[] = [
            'name' => 'cache',
            'command' => "cd {$config->laravelPath} && {$config->phpBinary} artisan optimize:clear && {$config->phpBinary} artisan config:cache && {$config->phpBinary} artisan route:cache && {$config->phpBinary} artisan view:cache",
        ];
        $steps[] = [
            'name' => 'permissions',
            'command' => "chown -R www-data:www-data {$config->laravelPath}/storage {$config->laravelPath}/bootstrap/cache && chmod -R ug+rwX {$config->laravelPath}/storage {$config->laravelPath}/bootstrap/cache",
        ];
        // sudo here is intentional and narrowly scoped — see
        // deployment-bootstrap/install.sh's sudoers.d entry, which grants
        // the unprivileged agent user NOPASSWD only for these two exact
        // `systemctl reload` invocations, nothing broader.
        $steps[] = [
            'name' => 'restart services',
            'command' => "sudo systemctl reload {$config->phpFpmService} && sudo systemctl reload {$config->webServerService}",
        ];
        $steps[] = ['name' => 'exit maintenance mode', 'command' => "cd {$config->laravelPath} && {$config->phpBinary} artisan up"];
        $steps[] = [
            'name' => 'health check',
            'command' => "curl --fail --silent --show-error {$config->panelUrl}/login",
        ];

        return $steps;
    }

    /**
     * The exact, complete content of the Agent's local config.php,
     * matching agent/config.example.php's shape byte for byte (same
     * keys, same order) — a pure function of already-known values, kept
     * separate from any file-writing so DeploymentBootstrapServiceTest
     * can assert on its content directly instead of needing a real
     * filesystem/systemd install to verify "would the Agent have been
     * configured correctly" (test #18's testable-abstraction ask).
     * install.sh is the only thing that ever writes this to disk (with
     * 600 permissions) — this class never touches the filesystem.
     *
     * Returns null when there is no fresh plaintext token/secret to
     * write (a re-run against an already-installed Agent) — install.sh
     * must leave the existing config.php alone in that case, not
     * overwrite it with placeholders it has no way to fill in.
     */
    public function buildAgentConfigPayload(
        DeploymentBootstrapConfig $config,
        Server $server,
        ?string $plainToken,
        ?string $plainSecret,
    ): ?array {
        if ($plainToken === null || $plainSecret === null) {
            return null;
        }

        return [
            'service_base_url' => $config->panelUrl,
            'server_id' => $server->public_id,
            'agent_token' => $plainToken,
            'signing_secret' => $plainSecret,
            'poll_interval_seconds' => 15,
            'request_timeout_seconds' => 10,
            'default_step_timeout_seconds' => 900,
            'projects' => [
                $config->projectKey => [
                    'working_directory' => $config->targetLaravelPath,
                    'php_binary' => $config->phpBinary,
                ],
            ],
            'log_dir' => '__DIR__ . \'/storage/logs\'',
        ];
    }

    /**
     * @return array{0: Environment, 1: bool}
     */
    private function findOrCreateEnvironment(Project $project, DeploymentBootstrapConfig $config, string $actorIdentifier): array
    {
        $environment = Environment::where('project_id', $project->id)->where('key', $config->environmentKey)->first();
        if ($environment !== null) {
            return [$environment, false];
        }

        $environment = $project->environments()->create([
            'name' => $config->environmentName,
            'key' => $config->environmentKey,
            'status' => Environment::STATUS_ACTIVE,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: $actorIdentifier,
            action: 'environment.created',
            subjectType: Environment::class,
            subjectId: $environment->id,
            metadata: ['project_id' => $project->id, 'source' => 'deployment:bootstrap'],
        );

        return [$environment, true];
    }

    /**
     * @return array{0: DeploymentTarget, 1: bool, 2: array<int, string>}
     */
    private function findOrCreateDeploymentTarget(Environment $environment, Server $server, Agent $agent, DeploymentBootstrapConfig $config, string $actorIdentifier): array
    {
        $notices = [];
        $target = DeploymentTarget::where('environment_id', $environment->id)->where('name', $config->targetName)->first();

        if ($target !== null) {
            $updates = [];
            if ((int) $target->server_id !== $server->id) {
                $updates['server_id'] = $server->id;
                $notices[] = 'Deployment Target: Server متصل اصلاح شد تا با تنظیمات فعلی مطابقت داشته باشد.';
            }
            if ($target->agent_id !== $agent->id) {
                $updates['agent_id'] = $agent->id;
                $notices[] = 'Deployment Target: Agent متصل اصلاح شد تا با Agent تاییدشده فعلی مطابقت داشته باشد.';
            }

            if ($updates !== []) {
                $target->update($updates);
            }

            return [$target->refresh(), false, $notices];
        }

        $target = $environment->deploymentTargets()->create([
            'server_id' => $server->id,
            'agent_id' => $agent->id,
            'name' => $config->targetName,
            'is_active' => true,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: $actorIdentifier,
            action: 'deployment_target.created',
            subjectType: DeploymentTarget::class,
            subjectId: $target->id,
            metadata: ['environment_id' => $environment->id, 'server_id' => $server->id, 'agent_id' => $agent->id, 'source' => 'deployment:bootstrap'],
        );

        return [$target, true, $notices];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function maybeCreateGithubWebhook(DeploymentBootstrapConfig $config, string $webhookUrl, string $webhookSecret): array
    {
        try {
            $existing = $this->github->listWebhooks((string) $config->githubToken, $config->githubOwner, $config->githubRepository);
            foreach ($existing as $hook) {
                if ($hook['url'] === $webhookUrl) {
                    return [false, null]; // already there — not a failure, just nothing to do
                }
            }

            $this->github->createWebhook(
                (string) $config->githubToken,
                $config->githubOwner,
                $config->githubRepository,
                $webhookUrl,
                $webhookSecret,
            );

            return [true, null];
        } catch (GitHubApiException $e) {
            report($e);

            return [false, $e->getMessage()];
        }
    }
}
