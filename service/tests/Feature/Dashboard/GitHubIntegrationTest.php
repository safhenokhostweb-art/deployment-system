<?php

namespace Tests\Feature\Dashboard;

use App\Models\GitHubAccount;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4-E: GitHub App integration + Project Creation Wizard. GitHub's
 * API is never called for real here — Http::fake() mocks its documented
 * response shapes (same pattern as Phase 3-D's HealthCheckServiceTest),
 * so these tests verify our code is correct against GitHub's real
 * contract without needing a registered GitHub App or network access.
 */
class GitHubIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test-fixture-only RSA key, generated once via the openssl CLI
     * (`openssl genrsa 2048`) purely to exercise GitHubAppClient's real
     * JWT-signing code path — never used for anything beyond this test
     * file. openssl_pkey_new() (key *generation*) isn't usable in this
     * environment (missing openssl.cnf), but openssl_pkey_get_private()
     * and openssl_sign() — what GitHubAppClient actually calls — work
     * fine against an existing PEM, confirmed separately.
     */
    private const TEST_PRIVATE_KEY_PEM = <<<'PEM'
    -----BEGIN PRIVATE KEY-----
    MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQCULEn0n1AQQBoB
    JKqYHwO4VfluLZO09xfaOL/czN9HdEi/wNRs/JMCB66T38lnMhDcXBhTSqnVKSER
    aRIkfSdcspZeXaeJDJQCFpoE0i1j/qeIldR4q3LwXkKAACotv8Lg92iowT+djcuT
    KH9u+DdKFTgvjYzm5oF4hVcRFX+lvnWYQMdq3pjrkDFFr8yuKBYIy9VCF5dhl04q
    nEE/qyDJcmBeUUkUciMSULnjMuUlmcJW1csSG6a8Yp4dMhjM5O6lhcm8B7q3EpRj
    EIr2V0DfzUi0B9be/Fwl+RM1jr9+CCoThglKVPz/qUSdWW4UMBCtQiX45iE7CF6C
    Xe5aNtMHAgMBAAECggEAJjBgAz9ISFftG9xSZ5CeydTGy8HNbve8Ww8DTCo1Zdov
    odRWK5zRWtWWBiDIbON3ewP1dQoqSXknP7B/742eJoMUxE+JDYaDYyJmIdX1wu7w
    SQCs2Uj+/7qbkxf3xwDEfmzluZDND5NrI4tthLFOtrZPW9o0tZI1TlW3gfyzgIj4
    p3kLXoCK1JF+PqPOK/2atrz/ISnZilpC7j6EzDK6VXBmQvh/QTDrff8HhT3LcnW+
    pcRVozGm+p7X8PN1/1J/z2lXGoL0aSbB2yUzgHgToCUE3D7UlcrC83F/X8H3eRj+
    yWgKuYJMUF2P/MSNMB2talKo20PtDwNelCJoNv0wGQKBgQDGAuvNbBrossJxLxp/
    JT03PtOZ916b+cYD+wySG3Zrb0EfPiJH6KzarTQOB6as66HjWZnEvi00cDubHzlv
    PdO79JB9kueP3SGAPF4gpuGWZErEgVlDyO5yzwwdH8JgFPCYbxBOMlmHWTFnYYxf
    smrfoZIMSq34pr7+F9G8KQqL6wKBgQC/kPD26QRS2/fjCTMgCiQS0HX3wRs+Lgfc
    vJS+4K2N5hI4LZXN5ygikR37aEgVPhZ4KcNYtTahww3MeqN0wjYOLondLO3OIBTF
    XyrVxXxdDA7Ygb2FXEwR9Fv/NIePmwoFbZzoOEtlHsNld0PVnNHx4/5B0JTRQFS/
    87WsOOGaVQKBgGBNl2WnSEcYed0Wbaqc7/LoiX1+g/MUPaw981U0KhoCWAfb7kxO
    65LHXmPccMH1MB0kvNalL9MrKrI9M2/ZABuPzJ5mTlz3zY1SzuzOfTTpvjtoNYP0
    O5R3Aos/oQed544bpjCB6G0dghuno9//JF9zc1arE4W1g3qNFyKOS2TVAoGAXu2Y
    OwKlXdobMKpyZ0IfNiOPEcLjmSB+TOg2XZBA8Lw98xDD11DhFqAK13aJod0ujUVE
    xESoWeiqgdWUAlvrJYPyVWPaQ/ap1FSgiWZfhkxfXvKL0/Y0zADnRcPmGUee46m5
    w2mCEw33jeRX/RbFArUUXdDargbO+H/wWvOk+DECgYAIqhhonUv9TUsl/RqF00dT
    A5FS8nk5dlt2pda3wQF3jNjSJi9NW9+4+kamR7B0zXTgKC5hCAZ3iKhN719+uUXo
    1AU5EdRQMlkSQ2Hmd0guyNSjOr1An/T/4M8yVuNju4pjATQgKhpVKn27laE/D/yV
    navWJdsbBhwtQjRR2hGfbA==
    -----END PRIVATE KEY-----
    PEM;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github_app.id' => '123456',
            'services.github_app.slug' => 'test-deployment-app',
            'services.github_app.private_key' => self::TEST_PRIVATE_KEY_PEM,
        ]);
    }

    public function test_github_app_client_generates_a_correctly_shaped_rs256_jwt(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'ghs_fake_installation_token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
        ]);

        $client = app(GitHubAppClient::class);
        $result = $client->createInstallationToken(999);

        $this->assertSame('ghs_fake_installation_token', $result['token']);

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';
            if (!str_starts_with($auth, 'Bearer ')) {
                return false;
            }
            $jwt = substr($auth, 7);
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) {
                return false;
            }
            $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

            return $header['alg'] === 'RS256' && $payload['iss'] === '123456';
        });
    }

    public function test_connect_redirects_to_github_installation_url_when_configured(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('github.connect'));

        $response->assertRedirect('https://github.com/apps/test-deployment-app/installations/new');
    }

    public function test_connect_shows_a_friendly_error_when_app_is_not_configured(): void
    {
        config(['services.github_app.slug' => null]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('github.connect'));

        $response->assertRedirect(route('github.accounts.index'));
        $follow = $this->actingAs($user)->get(route('github.accounts.index'));
        $follow->assertSee(__('messages.errors.github_app_not_configured'));
    }

    public function test_callback_creates_a_github_account_from_the_installation_id(): void
    {
        Http::fake([
            'api.github.com/app/installations/555' => Http::response([
                'account' => ['login' => 'acme-corp', 'type' => 'Organization'],
            ], 200),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('github.callback', ['installation_id' => 555]));

        $response->assertRedirect(route('github.accounts.index'));
        $this->assertDatabaseHas('github_accounts', [
            'installation_id' => 555,
            'account_login' => 'acme-corp',
            'account_type' => 'Organization',
            'status' => GitHubAccount::STATUS_CONNECTED,
        ]);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'github_account.connected']);
    }

    public function test_callback_handles_a_github_api_failure_without_crashing(): void
    {
        Http::fake([
            'api.github.com/app/installations/*' => Http::response(['message' => 'Not Found'], 404),
        ]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('github.callback', ['installation_id' => 777]));

        $response->assertRedirect(route('github.accounts.index'));
        $this->assertDatabaseMissing('github_accounts', ['installation_id' => 777]);
    }

    public function test_disconnect_marks_the_account_disconnected_without_deleting_it(): void
    {
        $user = User::factory()->create();
        $account = GitHubAccount::factory()->create();

        $this->actingAs($user)->post(route('github.accounts.disconnect', $account))
            ->assertRedirect(route('github.accounts.index'));

        $this->assertDatabaseHas('github_accounts', ['id' => $account->id, 'status' => GitHubAccount::STATUS_DISCONNECTED]);
    }

    public function test_test_connection_updates_last_tested_at_on_success(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
            'api.github.com/installation/repositories*' => Http::response(['repositories' => []]),
        ]);
        $user = User::factory()->create();
        $account = GitHubAccount::factory()->create(['last_tested_at' => null]);

        $this->actingAs($user)->post(route('github.accounts.test-connection', $account));

        $this->assertNotNull($account->fresh()->last_tested_at);
    }

    public function test_repositories_endpoint_returns_the_expected_json_shape(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
            'api.github.com/installation/repositories*' => Http::response([
                'repositories' => [
                    ['name' => 'saas-platform', 'full_name' => 'acme/saas-platform', 'owner' => ['login' => 'acme'], 'private' => true, 'default_branch' => 'main'],
                ],
            ]),
        ]);
        $user = User::factory()->create();
        $account = GitHubAccount::factory()->create();

        $response = $this->actingAs($user)->get(route('github.accounts.repositories', $account));

        $response->assertOk();
        $response->assertJson(['repositories' => [
            ['name' => 'saas-platform', 'full_name' => 'acme/saas-platform', 'owner' => 'acme', 'private' => true, 'default_branch' => 'main'],
        ]]);
    }

    public function test_branches_endpoint_returns_branch_names(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
            'api.github.com/repos/acme/saas-platform/branches*' => Http::response([
                ['name' => 'main'], ['name' => 'staging'],
            ]),
        ]);
        $user = User::factory()->create();
        $account = GitHubAccount::factory()->create();

        $response = $this->actingAs($user)->get(route('github.accounts.branches', $account) . '?owner=acme&repo=saas-platform');

        $response->assertOk();
        $response->assertJson(['branches' => ['main', 'staging']]);
    }

    public function test_github_accounts_page_never_shows_a_token_or_secret(): void
    {
        $user = User::factory()->create();
        GitHubAccount::factory()->create(['account_login' => 'no-secrets-here']);

        $response = $this->actingAs($user)->get(route('github.accounts.index'));

        $response->assertOk();
        $response->assertDontSee('access_token', false);
        $response->assertDontSee('ghs_', false);
        $response->assertDontSee('private_key', false);
    }

    public function test_wizard_creates_a_project_with_an_auto_generated_project_key(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'SaaS Platform',
            'repository' => 'acme/saas-platform',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('projects', ['name' => 'SaaS Platform', 'project_key' => 'saas-platform']);
    }

    public function test_auto_generated_project_key_gets_a_suffix_on_collision(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();
        Project::factory()->create(['project_key' => 'saas-platform']);

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'SaaS Platform',
            'repository' => 'acme/saas-platform-2',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
        ]);

        $this->assertDatabaseHas('projects', ['repository' => 'acme/saas-platform-2', 'project_key' => 'saas-platform-2']);
    }

    public function test_manual_project_key_still_works_exactly_as_before(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Manual Key Project',
            'project_key' => 'my-manual-key',
            'repository' => 'acme/manual',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
        ]);

        $this->assertDatabaseHas('projects', ['project_key' => 'my-manual-key']);
    }

    public function test_wizard_creates_an_environment_and_target_when_environment_name_is_provided(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create(['name' => 'prod-vps']);

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'With Environment',
            'repository' => 'acme/with-env',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
            'environment_name' => 'Production',
        ]);

        $project = Project::where('name', 'With Environment')->firstOrFail();
        $this->assertCount(1, $project->environments);
        $this->assertSame('Production', $project->environments->first()->name);
        $this->assertCount(1, $project->environments->first()->deploymentTargets);
        $this->assertSame('prod-vps', $project->environments->first()->deploymentTargets->first()->name);
    }

    public function test_legacy_project_creation_without_environment_name_creates_no_environment(): void
    {
        $user = User::factory()->create();
        $server = Server::factory()->create();

        $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Legacy Only',
            'repository' => 'acme/legacy-only',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
        ]);

        $project = Project::where('name', 'Legacy Only')->firstOrFail();
        $this->assertCount(0, $project->environments);
    }

    public function test_project_creation_with_github_account_auto_configures_the_webhook(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['token' => 't', 'expires_at' => now()->addHour()->toIso8601String()]),
            'api.github.com/repos/acme/webhook-project/hooks' => Http::response(['id' => 1], 201),
        ]);
        $user = User::factory()->create();
        $server = Server::factory()->create();
        $account = GitHubAccount::factory()->create();

        $store = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Webhook Project',
            'repository' => 'acme/webhook-project',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
            'github_account_id' => $account->id,
        ]);

        $this->assertDatabaseHas('audit_log_entries', ['action' => 'project.github_webhook_auto_configured']);
        $landing = $this->actingAs($user)->get($store->headers->get('Location'));
        $landing->assertSee(__('ui.projects.webhook_auto_configured'));
    }

    public function test_webhook_auto_configuration_failure_does_not_block_project_creation(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response(['message' => 'Bad credentials'], 401),
        ]);
        $user = User::factory()->create();
        $server = Server::factory()->create();
        $account = GitHubAccount::factory()->create();

        $response = $this->actingAs($user)->post(route('projects.store'), [
            'name' => 'Resilient Project',
            'repository' => 'acme/resilient',
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => $server->id,
            'pipeline' => json_encode([['name' => 'noop', 'command' => 'echo hi']]),
            'github_account_id' => $account->id,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('projects', ['name' => 'Resilient Project']);
    }
}
