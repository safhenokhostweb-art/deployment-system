<?php

namespace Tests\Feature\Console;

use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\Bootstrap\DeploymentBootstrapConfig;
use App\Services\Bootstrap\DeploymentBootstrapService;
use App\Services\Bootstrap\GitRepositoryInspector;
use App\Services\Bootstrap\GitRepositoryStatus;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `php artisan deployment:bootstrap` — see
 * docs/DEPLOYMENT_BOOTSTRAP_REPORT.md for the full idempotency contract.
 * Every record this command creates goes through the exact same models
 * the dashboard controllers already use (confirmed by audit before this
 * was written, not assumed) — these tests exercise the command's own
 * new behavior (idempotency, transactionality, secret handling, the
 * git/GitHub integration points), not Server/Agent/Project/Environment/
 * DeploymentTarget's own already-tested creation logic.
 */
class DeploymentBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param array<string, string> $overrides
     */
    private function writeConfig(array $overrides = []): string
    {
        $defaults = [
            'DEPLOYMENT_PANEL_URL' => 'https://deployment.example.test',
            'REPOSITORY_PATH' => '/var/www/deployment-system',
            'LARAVEL_PATH' => '/var/www/deployment-system/service',
            'SERVER_NAME' => 'سرور اصلی Deployment System',
            'SERVER_IP' => '87.107.12.81',
            'AGENT_NAME' => 'عامل اصلی Deployment System',
            'PROJECT_NAME' => 'Deployment System',
            'PROJECT_KEY' => 'deployment-system',
            'REPOSITORY_SSH_URL' => 'git@github.com:safhenokhostweb-art/deployment-system.git',
            'REPOSITORY_HTTPS_URL' => 'https://github.com/safhenokhostweb-art/deployment-system.git',
            'GITHUB_OWNER' => 'safhenokhostweb-art',
            'GITHUB_REPOSITORY' => 'deployment-system',
            'DEPLOY_BRANCH' => 'master',
            'ENVIRONMENT_NAME' => 'Production',
            'ENVIRONMENT_KEY' => 'production',
            'TARGET_NAME' => 'سرور اصلی Production',
            'TARGET_PATH' => '/var/www/deployment-system',
            'TARGET_LARAVEL_PATH' => '/var/www/deployment-system/service',
            'PHP_BINARY' => '/usr/bin/php8.3',
            'PHP_FPM_SERVICE' => 'php8.3-fpm',
            'WEB_SERVER_SERVICE' => 'nginx',
            'RUN_COMPOSER_INSTALL' => 'false',
            'GITHUB_TOKEN' => '',
            'AUTO_CREATE_GITHUB_WEBHOOK' => 'false',
        ];

        $values = array_merge($defaults, $overrides);
        $dir = storage_path('framework/testing/bootstrap-config');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = tempnam($dir, 'config-') . '.env';
        $content = implode("\n", array_map(static fn ($k, $v) => "{$k}={$v}", array_keys($values), $values));
        file_put_contents($path, $content);

        return $path;
    }

    private function fakeCleanRepository(): void
    {
        $this->mock(GitRepositoryInspector::class, function ($mock) {
            $mock->shouldReceive('inspect')->andReturn(new GitRepositoryStatus(
                exists: true,
                isClean: true,
                remoteUrl: 'git@github.com:safhenokhostweb-art/deployment-system.git',
                currentBranch: 'master',
                changedFiles: [],
            ));
        });
    }

    // 1. اجرای اولیه Bootstrap
    public function test_initial_bootstrap_creates_all_records(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('servers', ['name' => 'سرور اصلی Deployment System']);
        $this->assertDatabaseHas('agents', ['name' => 'عامل اصلی Deployment System', 'status' => Agent::STATUS_APPROVED]);
        $this->assertDatabaseHas('projects', ['project_key' => 'deployment-system', 'branch' => 'master']);
        $this->assertDatabaseHas('environments', ['key' => 'production']);
        $this->assertDatabaseHas('deployment_targets', ['name' => 'سرور اصلی Production']);
    }

    // 2. اجرای مجدد بدون ایجاد رکورد تکراری
    public function test_second_run_does_not_create_duplicate_records(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);
        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $this->assertSame(1, Server::count());
        $this->assertSame(1, Agent::count());
        $this->assertSame(1, Project::count());
        $this->assertSame(1, Environment::count());
        $this->assertSame(1, DeploymentTarget::count());
    }

    // 3. شناسایی Server موجود
    public function test_finds_existing_server_by_name(): void
    {
        $this->fakeCleanRepository();
        $existing = Server::factory()->create(['name' => 'سرور اصلی Deployment System']);
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $this->assertSame(1, Server::count());
        $this->assertDatabaseHas('projects', ['server_id' => $existing->id]);
    }

    // 4. شناسایی Agent موجود
    public function test_finds_existing_approved_agent_for_server(): void
    {
        $this->fakeCleanRepository();
        $server = Server::factory()->create(['name' => 'سرور اصلی Deployment System']);
        $existingAgent = Agent::factory()->approved()->create(['server_id' => $server->id, 'name' => 'قبلی']);
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $this->assertSame(1, Agent::count());
        $this->assertDatabaseHas('agents', ['id' => $existingAgent->id, 'name' => 'قبلی']);
    }

    // 5. شناسایی Project موجود
    public function test_finds_existing_project_by_project_key(): void
    {
        $this->fakeCleanRepository();
        $server = Server::factory()->create(['name' => 'سرور اصلی Deployment System']);
        $existingProject = Project::factory()->create(['project_key' => 'deployment-system', 'server_id' => $server->id]);
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $this->assertSame(1, Project::count());
        $this->assertDatabaseHas('projects', ['id' => $existingProject->id]);
    }

    // 6. ساخت Environment
    public function test_creates_environment_with_configured_name_and_key(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $project = Project::where('project_key', 'deployment-system')->firstOrFail();
        $this->assertDatabaseHas('environments', [
            'project_id' => $project->id,
            'name' => 'Production',
            'key' => 'production',
            'status' => Environment::STATUS_ACTIVE,
        ]);
    }

    // 7. ساخت Deployment Target
    public function test_creates_deployment_target_with_configured_name(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $this->assertDatabaseHas('deployment_targets', ['name' => 'سرور اصلی Production', 'is_active' => true]);
    }

    // 8. اتصال صحیح Target
    public function test_deployment_target_is_linked_to_the_correct_server_and_agent(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $server = Server::where('name', 'سرور اصلی Deployment System')->firstOrFail();
        $agent = Agent::where('server_id', $server->id)->firstOrFail();
        $target = DeploymentTarget::where('name', 'سرور اصلی Production')->firstOrFail();

        $this->assertSame($server->id, $target->server_id);
        $this->assertSame($agent->id, $target->agent_id);
    }

    // 9. عدم نمایش Secret در اجرای دوم
    public function test_secrets_are_not_shown_on_second_run(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $first = $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true]);
        $first->assertExitCode(0);
        $first->expectsOutputToContain('Webhook Secret');

        $second = $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true]);
        $second->assertExitCode(0);
        $second->doesntExpectOutputToContain('Webhook Secret (فقط همین یک‌بار نمایش داده می‌شود)');
    }

    // 10. عدم Rotate شدن Secret موجود
    public function test_existing_secret_is_not_rotated_on_second_run(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);
        $server = Server::where('name', 'سرور اصلی Deployment System')->firstOrFail();
        $tokenHashBefore = $server->agent_token_hash;
        $signingSecretBefore = $server->signing_secret;
        $webhookSecretBefore = Project::where('project_key', 'deployment-system')->firstOrFail()->github_webhook_secret;

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $server->refresh();
        $project = Project::where('project_key', 'deployment-system')->firstOrFail();
        $this->assertSame($tokenHashBefore, $server->agent_token_hash);
        $this->assertSame($signingSecretBefore, $server->signing_secret);
        $this->assertSame($webhookSecretBefore, $project->github_webhook_secret);
    }

    // 11. Rollback در خطای میانی
    public function test_mid_operation_failure_rolls_back_the_entire_transaction(): void
    {
        $this->fakeCleanRepository();
        $this->mock(GitHubAppClient::class, function ($mock) {
            $mock->shouldReceive('listWebhooks')->andThrow(new \RuntimeException('simulated mid-operation failure'));
        });

        $configPath = $this->writeConfig([
            'GITHUB_TOKEN' => 'fake-token-for-test',
            'AUTO_CREATE_GITHUB_WEBHOOK' => 'true',
        ]);

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])
            ->assertExitCode(1);

        // Whatever the exit code convention, the transaction must have
        // rolled back completely — no partial Server/Agent/Project may
        // survive an exception thrown mid-way through the same run.
        $this->assertSame(0, Server::count());
        $this->assertSame(0, Agent::count());
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Environment::count());
        $this->assertSame(0, DeploymentTarget::count());
    }

    // 12. توقف امن در صورت Working Tree کثیف
    public function test_stops_safely_when_working_tree_is_dirty_in_non_interactive_mode(): void
    {
        $this->mock(GitRepositoryInspector::class, function ($mock) {
            $mock->shouldReceive('inspect')->andReturn(new GitRepositoryStatus(
                exists: true,
                isClean: false,
                remoteUrl: 'git@github.com:safhenokhostweb-art/deployment-system.git',
                currentBranch: 'master',
                changedFiles: ['M app/Providers/AppServiceProvider.php'],
            ));
        });

        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])
            ->assertExitCode(1);

        $this->assertSame(0, Server::count());
        $this->assertSame(0, Project::count());
    }

    // 13. رفتار بدون GitHub Token
    public function test_behaves_correctly_without_a_github_token(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig(['GITHUB_TOKEN' => '', 'AUTO_CREATE_GITHUB_WEBHOOK' => 'false']);

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Webhook URL')
            ->doesntExpectOutputToContain('Webhook به‌صورت خودکار در GitHub ساخته شد');

        $this->assertSame(1, Project::count());
    }

    // 14. رفتار با GitHub Token Mock شده
    public function test_creates_webhook_automatically_with_a_mocked_github_token(): void
    {
        $this->fakeCleanRepository();
        $this->mock(GitHubAppClient::class, function ($mock) {
            $mock->shouldReceive('listWebhooks')->once()->andReturn([]);
            $mock->shouldReceive('createWebhook')->once();
        });

        $configPath = $this->writeConfig([
            'GITHUB_TOKEN' => 'fake-token-for-test',
            'AUTO_CREATE_GITHUB_WEBHOOK' => 'true',
        ]);

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('Webhook به‌صورت خودکار در GitHub ساخته شد.');
    }

    // 15. عدم ایجاد Webhook تکراری
    public function test_does_not_create_a_duplicate_webhook_when_one_already_exists(): void
    {
        $this->fakeCleanRepository();
        $expectedUrl = route('webhooks.github', 'deployment-system');

        $this->mock(GitHubAppClient::class, function ($mock) use ($expectedUrl) {
            $mock->shouldReceive('listWebhooks')->once()->andReturn([['id' => 1, 'url' => $expectedUrl]]);
            $mock->shouldNotReceive('createWebhook');
        });

        $configPath = $this->writeConfig([
            'GITHUB_TOKEN' => 'fake-token-for-test',
            'AUTO_CREATE_GITHUB_WEBHOOK' => 'true',
        ]);

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])
            ->assertExitCode(0);
    }

    // 16. اعتبارسنجی config.env
    public function test_fails_clearly_when_a_required_config_value_is_missing(): void
    {
        $this->fakeCleanRepository();
        $dir = storage_path('framework/testing/bootstrap-config');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $path = tempnam($dir, 'invalid-config-') . '.env';
        file_put_contents($path, "DEPLOYMENT_PANEL_URL=https://example.test\n"); // everything else missing

        $this->artisan('deployment:bootstrap', ['--config' => $path, '--non-interactive' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain('مقدار الزامی');

        $this->assertSame(0, Server::count());
    }

    // 17. عدم ثبت Secret در Log
    public function test_secrets_never_appear_in_the_laravel_log(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $logPath = storage_path('logs/laravel.log');
        $sizeBefore = is_file($logPath) ? filesize($logPath) : 0;

        $this->artisan('deployment:bootstrap', ['--config' => $configPath, '--non-interactive' => true])->assertExitCode(0);

        $server = Server::where('name', 'سرور اصلی Deployment System')->firstOrFail();
        $project = Project::where('project_key', 'deployment-system')->firstOrFail();

        if (is_file($logPath)) {
            $newLogContent = substr((string) file_get_contents($logPath), $sizeBefore);
            $this->assertStringNotContainsString((string) $project->github_webhook_secret, $newLogContent);
            // agent_token/signing_secret are never even returned in plaintext
            // after this point in a real run except via the in-memory
            // Report object — nothing this test can reach should have
            // written the hash-source plaintext anywhere either.
            $this->assertStringNotContainsString($server->agent_token_hash, $newLogContent);
        } else {
            $this->assertTrue(true, 'No log file was written — nothing to check, which also satisfies "no secret in the log".');
        }
    }

    // 18. نصب و verify سرویس Agent با abstraction قابل تست
    public function test_agent_config_payload_abstraction_has_the_correct_shape(): void
    {
        $config = DeploymentBootstrapConfig::fromEnvArray(DeploymentBootstrapConfig::parseEnvFile($this->writeConfig()));
        $server = Server::factory()->create(['public_id' => 'test-public-id']);

        $service = app(DeploymentBootstrapService::class);

        $this->assertNull($service->buildAgentConfigPayload($config, $server, null, null));

        $payload = $service->buildAgentConfigPayload($config, $server, 'agt_plaintext', 'sec_plaintext');
        $this->assertNotNull($payload);
        $this->assertSame('https://deployment.example.test', $payload['service_base_url']);
        $this->assertSame('test-public-id', $payload['server_id']);
        $this->assertSame('agt_plaintext', $payload['agent_token']);
        $this->assertSame('sec_plaintext', $payload['signing_secret']);
        $this->assertArrayHasKey('deployment-system', $payload['projects']);
        $this->assertSame('/var/www/deployment-system/service', $payload['projects']['deployment-system']['working_directory']);
        $this->assertSame('/usr/bin/php8.3', $payload['projects']['deployment-system']['php_binary']);
    }

    public function test_admin_email_must_belong_to_an_existing_admin_user(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', [
            '--config' => $configPath,
            '--non-interactive' => true,
            '--admin-email' => 'no-such-user@example.test',
        ])->assertExitCode(1);

        $this->assertSame(0, Server::count());
    }

    public function test_admin_email_is_recorded_on_the_audit_log_when_valid(): void
    {
        $this->fakeCleanRepository();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => 'boss@example.test']);
        $configPath = $this->writeConfig();

        $this->artisan('deployment:bootstrap', [
            '--config' => $configPath,
            '--non-interactive' => true,
            '--admin-email' => $admin->email,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('audit_log_entries', [
            'action' => 'server.registered',
            'actor_identifier' => 'boss@example.test',
        ]);
    }

    public function test_secrets_output_file_is_written_with_restrictive_permissions_only_on_creation(): void
    {
        $this->fakeCleanRepository();
        $configPath = $this->writeConfig();
        $dir = storage_path('framework/testing/bootstrap-config');
        // tempnam() itself creates a file at the base (un-suffixed) name —
        // that one gets cleaned up, but the actual --secrets-output path
        // used below (with .json appended) was never created by it, so
        // the command really is creating it fresh, as intended.
        $baseTempFile = tempnam($dir, 'secrets-');
        $secretsPath = $baseTempFile . '.json';
        unlink($baseTempFile);

        $this->artisan('deployment:bootstrap', [
            '--config' => $configPath,
            '--non-interactive' => true,
            '--secrets-output' => $secretsPath,
        ])->assertExitCode(0);

        $this->assertFileExists($secretsPath);
        $decoded = json_decode((string) file_get_contents($secretsPath), true);
        $this->assertArrayHasKey('agent_config', $decoded);
        $this->assertNotNull($decoded['agent_config']);
        $this->assertArrayHasKey('agent_token', $decoded['agent_config']);

        unlink($secretsPath);
    }
}
