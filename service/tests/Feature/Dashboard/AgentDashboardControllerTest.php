<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Services\AgentDashboard\AgentProcessLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeAgentProcessLauncher;
use Tests\TestCase;

/**
 * Visual Agent Management Dashboard: every /api/agents route against a
 * disposable copy of the workspace agent runtime layout (agents.json,
 * workspace.json, bin/agent, var/bus/journal.jsonl, a sealed artifact).
 */
class AgentDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT = 'dependency-deployment';

    private string $root;

    private string $artifactPath;

    private string $artifactDigest;

    private FakeAgentProcessLauncher $launcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/agent-dashboard-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->root.'/agents/bin');
        File::ensureDirectoryExists($this->root.'/agents/var/bus');
        file_put_contents($this->root.'/agents/bin/agent', "#!/usr/bin/env php\n<?php\n");
        $this->writeJson($this->root.'/agents/workspace.json', [
            'schema' => 'agents.workspace/v1',
            'root' => '..',
            'deployment_system' => ['path' => 'deployment-system', 'release_prepare' => 'deployment-system/deployment/release-prepare.php'],
            'runtime' => ['artifacts' => 'agents/var/artifacts', 'journal' => 'agents/var/bus/journal.jsonl'],
        ]);
        $this->writeJson($this->registryPath(), [
            'schema' => 'agents.registry/v1',
            'agents' => [[
                'id' => self::AGENT,
                'class' => 'Workspace\\Agents\\Agents\\DependencyDeployment\\DependencyDeploymentAgent',
                'enabled' => true,
                'subscriptions' => [['topic' => 'release.requested', 'task' => 'deployment.readiness']],
            ]],
        ]);
        $this->sealArtifact();
        $this->writeJournal();

        config([
            'agent_dashboard.runtime_path' => $this->root.'/agents',
            'agent_dashboard.state_path' => $this->root.'/state',
            'agent_dashboard.php_binary' => 'php-test',
        ]);
        $this->launcher = new FakeAgentProcessLauncher();
        $this->app->instance(AgentProcessLauncher::class, $this->launcher);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_graph_returns_agent_and_release_preparer_nodes_with_live_status_and_default_edge(): void
    {
        $response = $this->actingAs($this->user())->getJson(route('agent-dashboard.graph'))->assertOk();

        $response->assertJsonPath('schema', 'agent-dashboard.graph/v1')
            ->assertJsonPath('runtime.layout_saved', false)
            ->assertJsonPath('nodes.0.id', self::AGENT)
            ->assertJsonPath('nodes.0.type', 'agent')
            ->assertJsonPath('nodes.0.enabled', true)
            ->assertJsonPath('nodes.0.tasks', ['deployment.readiness'])
            ->assertJsonPath('nodes.0.inputs', ['release.requested'])
            ->assertJsonPath('nodes.0.outputs', ['deployment.readiness.reported'])
            ->assertJsonPath('nodes.0.status', 'ATTENTION')
            ->assertJsonPath('nodes.0.last_outcome.verdict', 'ATTENTION')
            ->assertJsonPath('nodes.0.last_outcome.counts.warning', 2)
            ->assertJsonPath('nodes.1.id', 'release-preparer')
            ->assertJsonPath('nodes.1.type', 'system')
            ->assertJsonPath('nodes.1.description', 'deployment-system/deployment/release-prepare.php')
            ->assertJsonPath('edges.0.source', self::AGENT)
            ->assertJsonPath('edges.0.target', 'release-preparer');
    }

    public function test_graph_marks_a_blocker_outcome_and_a_failed_task_as_blocked(): void
    {
        $this->appendJournal('task.completed', 'orchestrator', ['task_type' => 'deployment.readiness', 'agent' => self::AGENT, 'status' => 'PASS', 'summary' => 'BLOCKED: 1 blocker(s)', 'findings' => ['blocker' => 1]]);
        $this->actingAs($this->user())->getJson(route('agent-dashboard.graph'))->assertJsonPath('nodes.0.status', 'BLOCKED');

        $this->appendJournal('task.completed', 'orchestrator', ['task_type' => 'deployment.readiness', 'agent' => self::AGENT, 'status' => 'FAILED', 'summary' => 'defect', 'findings' => []]);
        $this->actingAs($this->user())->getJson(route('agent-dashboard.graph'))->assertJsonPath('nodes.0.status', 'BLOCKED')
            ->assertJsonPath('nodes.0.last_outcome.status', 'FAILED');
    }

    public function test_guest_is_rejected_from_every_api_route(): void
    {
        $this->getJson(route('agent-dashboard.graph'))->assertUnauthorized();
        $this->postJson(route('agent-dashboard.save'), [])->assertUnauthorized();
        $this->postJson(route('agent-dashboard.toggle', self::AGENT))->assertUnauthorized();
        $this->postJson(route('agent-dashboard.run', self::AGENT))->assertUnauthorized();
        $this->postJson(route('agent-dashboard.stop', self::AGENT))->assertUnauthorized();
        $this->getJson(route('agent-dashboard.logs', self::AGENT))->assertUnauthorized();
        $this->assertSame([], $this->launcher->started);
    }

    public function test_viewer_can_read_but_cannot_change_or_execute_anything(): void
    {
        $viewer = $this->user(User::ROLE_VIEWER);

        $this->actingAs($viewer)->getJson(route('agent-dashboard.graph'))->assertOk();
        $this->actingAs($viewer)->getJson(route('agent-dashboard.logs', self::AGENT))->assertOk();
        $this->actingAs($viewer)->postJson(route('agent-dashboard.save'), ['edges' => []])->assertForbidden();
        $this->actingAs($viewer)->postJson(route('agent-dashboard.toggle', self::AGENT))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('agent-dashboard.run', self::AGENT))->assertForbidden();
        $this->actingAs($viewer)->postJson(route('agent-dashboard.stop', self::AGENT))->assertForbidden();

        $this->assertSame([], $this->launcher->started);
        $this->assertTrue($this->registry()['agents'][0]['enabled']);
    }

    public function test_operator_and_developer_cannot_run_agents(): void
    {
        foreach ([User::ROLE_OPERATOR, User::ROLE_DEVELOPER] as $role) {
            $this->actingAs($this->user($role))->postJson(route('agent-dashboard.run', self::AGENT))->assertForbidden();
        }
        $this->assertSame([], $this->launcher->started);
    }

    public function test_save_persists_edges_positions_and_enabled_state_without_touching_runtime_fields(): void
    {
        $response = $this->actingAs($this->user())->postJson(route('agent-dashboard.save'), [
            'nodes' => [
                ['id' => self::AGENT, 'enabled' => false, 'position' => ['x' => 140.6, 'y' => 90]],
                ['id' => 'release-preparer', 'position' => ['x' => 620, 'y' => 110]],
            ],
            'edges' => [
                ['source' => self::AGENT, 'target' => 'release-preparer'],
                ['source' => self::AGENT, 'target' => 'release-preparer'],
            ],
        ])->assertOk();

        $response->assertJsonPath('runtime.layout_saved', true)
            ->assertJsonPath('nodes.0.enabled', false)
            ->assertJsonPath('nodes.0.status', 'DISABLED')
            ->assertJsonPath('nodes.0.position', ['x' => 141, 'y' => 90])
            ->assertJsonCount(1, 'edges');

        $registry = $this->registry();
        $this->assertSame('agents.registry/v1', $registry['schema']);
        $this->assertFalse($registry['agents'][0]['enabled']);
        $this->assertSame([['topic' => 'release.requested', 'task' => 'deployment.readiness']], $registry['agents'][0]['subscriptions']);
        $this->assertSame('Workspace\\Agents\\Agents\\DependencyDeployment\\DependencyDeploymentAgent', $registry['agents'][0]['class']);
        $this->assertSame('agent-dashboard.layout/v1', $registry['dashboard']['schema']);
        $this->assertSame(['x' => 620, 'y' => 110], $registry['dashboard']['positions']['release-preparer']);
        $this->assertSame([['source' => self::AGENT, 'target' => 'release-preparer', 'label' => null]], $registry['dashboard']['edges']);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'workspace_agent.graph_saved']);
    }

    public function test_saving_an_empty_edge_list_removes_the_default_wire(): void
    {
        $this->actingAs($this->user())->postJson(route('agent-dashboard.save'), ['edges' => []])
            ->assertOk()
            ->assertJsonCount(0, 'edges');

        $this->assertSame([], $this->registry()['dashboard']['edges']);
    }

    public function test_save_rejects_unknown_nodes_self_wires_and_wires_out_of_release_preparer(): void
    {
        $admin = $this->user();
        $before = file_get_contents($this->registryPath());

        $this->actingAs($admin)->postJson(route('agent-dashboard.save'), ['nodes' => [['id' => 'ghost']]])
            ->assertUnprocessable()->assertJsonValidationErrors('nodes.0.id');
        $this->actingAs($admin)->postJson(route('agent-dashboard.save'), ['edges' => [['source' => self::AGENT, 'target' => 'ghost']]])
            ->assertUnprocessable()->assertJsonValidationErrors('edges.0');
        $this->actingAs($admin)->postJson(route('agent-dashboard.save'), ['edges' => [['source' => self::AGENT, 'target' => self::AGENT]]])
            ->assertUnprocessable()->assertJsonValidationErrors('edges.0');
        $this->actingAs($admin)->postJson(route('agent-dashboard.save'), ['edges' => [['source' => 'release-preparer', 'target' => self::AGENT]]])
            ->assertUnprocessable()->assertJsonValidationErrors('edges.0');
        $this->actingAs($admin)->postJson(route('agent-dashboard.save'), ['edges' => [['source' => self::AGENT]]])
            ->assertUnprocessable()->assertJsonValidationErrors('edges.0.target');

        $this->assertSame($before, file_get_contents($this->registryPath()));
    }

    public function test_toggle_flips_or_sets_the_enabled_flag(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->postJson(route('agent-dashboard.toggle', self::AGENT))
            ->assertOk()->assertJson(['id' => self::AGENT, 'enabled' => false]);
        $this->assertFalse($this->registry()['agents'][0]['enabled']);

        $this->actingAs($admin)->postJson(route('agent-dashboard.toggle', self::AGENT), ['enabled' => true])
            ->assertOk()->assertJson(['enabled' => true]);
        $this->assertTrue($this->registry()['agents'][0]['enabled']);

        $this->assertDatabaseHas('audit_log_entries', ['action' => 'workspace_agent.disabled']);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'workspace_agent.enabled']);
    }

    public function test_toggle_and_run_return_404_for_unknown_or_system_nodes(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->postJson(route('agent-dashboard.toggle', 'ghost'))->assertNotFound();
        $this->actingAs($admin)->postJson(route('agent-dashboard.toggle', 'release-preparer'))->assertNotFound();
        $this->actingAs($admin)->postJson(route('agent-dashboard.run', 'ghost'))->assertNotFound();
        $this->actingAs($admin)->getJson(route('agent-dashboard.logs', 'ghost'))->assertNotFound();
        $this->actingAs($admin)->postJson('/api/agents/Bad_Id/run')->assertNotFound();
    }

    public function test_run_starts_the_agent_cli_in_the_background_and_stores_its_pid(): void
    {
        $response = $this->actingAs($this->user())->postJson(route('agent-dashboard.run', self::AGENT))->assertStatus(202);

        $response->assertJsonPath('run.pid', 4200)
            ->assertJsonPath('run.task', 'deployment.readiness')
            ->assertJsonPath('run.running', true);

        $this->assertCount(1, $this->launcher->started);
        $started = $this->launcher->started[0];
        $this->assertSame(
            ['php-test', $this->root.'/agents/bin/agent', 'run', 'deployment.readiness', '--target='.self::AGENT, '--verbose'],
            $started['command'],
        );
        $this->assertSame(str_replace('\\', '/', $this->root), $started['cwd']);
        $this->assertFileExists($started['log']);

        $state = json_decode(file_get_contents($this->root.'/state/runs/'.self::AGENT.'.json'), true);
        $this->assertSame(4200, $state['pid']);
        $this->assertNull($state['stopped_at']);

        $this->actingAs($this->user())->getJson(route('agent-dashboard.graph'))
            ->assertJsonPath('nodes.0.status', 'RUNNING')
            ->assertJsonPath('nodes.0.run.pid', 4200);
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'workspace_agent.run_started']);
    }

    public function test_run_refuses_a_second_concurrent_run_a_disabled_agent_and_an_unhandled_task(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->postJson(route('agent-dashboard.run', self::AGENT))->assertStatus(202);
        $this->actingAs($admin)->postJson(route('agent-dashboard.run', self::AGENT))->assertStatus(409);
        $this->assertCount(1, $this->launcher->started);

        $this->launcher->finish(4200);
        $this->actingAs($admin)->postJson(route('agent-dashboard.run', self::AGENT), ['task' => 'dependency.mirror-sync'])->assertStatus(422);
        $this->actingAs($admin)->postJson(route('agent-dashboard.run', self::AGENT), ['task' => 'rm -rf /'])->assertStatus(422);

        $this->actingAs($admin)->postJson(route('agent-dashboard.toggle', self::AGENT), ['enabled' => false]);
        $this->actingAs($admin)->postJson(route('agent-dashboard.run', self::AGENT))->assertStatus(409);
        $this->assertCount(1, $this->launcher->started);
    }

    public function test_finished_run_falls_back_to_the_journal_outcome(): void
    {
        $admin = $this->user();
        $this->actingAs($admin)->postJson(route('agent-dashboard.run', self::AGENT))->assertStatus(202);
        $this->launcher->finish(4200);

        $this->actingAs($admin)->getJson(route('agent-dashboard.graph'))
            ->assertJsonPath('nodes.0.status', 'ATTENTION')
            ->assertJsonPath('nodes.0.run.running', false);
    }

    public function test_stop_terminates_the_running_process(): void
    {
        $admin = $this->user();
        $this->actingAs($admin)->postJson(route('agent-dashboard.run', self::AGENT))->assertStatus(202);

        $this->actingAs($admin)->postJson(route('agent-dashboard.stop', self::AGENT))
            ->assertOk()
            ->assertJsonPath('run.running', false)
            ->assertJsonPath('run.pid', 4200);

        $this->assertSame([4200], $this->launcher->stopped);
        $this->assertStringContainsString('stopped by operator', file_get_contents($this->launcher->started[0]['log']));
        $this->assertDatabaseHas('audit_log_entries', ['action' => 'workspace_agent.run_stopped']);

        $this->actingAs($admin)->postJson(route('agent-dashboard.stop', self::AGENT))->assertStatus(409);
        $this->assertSame([4200], $this->launcher->stopped);
    }

    public function test_logs_return_journal_findings_run_log_and_the_verified_sealed_artifact(): void
    {
        $admin = $this->user();
        $this->actingAs($admin)->postJson(route('agent-dashboard.run', self::AGENT))->assertStatus(202);
        file_put_contents($this->launcher->started[0]['log'], "[PASS] dependency-deployment deployment.readiness\n", FILE_APPEND);

        $response = $this->actingAs($admin)->getJson(route('agent-dashboard.logs', self::AGENT))->assertOk();

        $response->assertJsonPath('id', self::AGENT)
            ->assertJsonPath('last_outcome.verdict', 'ATTENTION')
            ->assertJsonPath('findings.0.severity', 'blocker')
            ->assertJsonPath('findings.0.code', 'MIGRATION_REVIEW_REQUIRED')
            ->assertJsonPath('findings.1.severity', 'warning')
            ->assertJsonPath('findings.2.severity', 'info')
            ->assertJsonPath('artifact.artifact_id', 'deployment-readiness-20261027T044619-fac32b9b')
            ->assertJsonPath('artifact.digest', $this->artifactDigest)
            ->assertJsonPath('artifact.computed_digest', $this->artifactDigest)
            ->assertJsonPath('artifact.verified', true)
            ->assertJsonPath('artifact.errors', [])
            ->assertJsonPath('run.running', true);

        $this->assertStringContainsString('[PASS] dependency-deployment', $response->json('run.log_tail'));
        $this->assertStringContainsString('"integrity"', $response->json('artifact.content'));
        $this->assertSame(
            ['task.started', 'artifact.created', 'deployment.readiness.reported', 'task.completed'],
            array_column($response->json('journal'), 'topic'),
        );
    }

    public function test_logs_flag_a_tampered_artifact_and_a_tampered_attachment(): void
    {
        $envelope = json_decode(file_get_contents($this->artifactPath), true);
        $envelope['payload']['verdict'] = 'READY';
        file_put_contents($this->artifactPath, json_encode($envelope));
        file_put_contents(dirname($this->artifactPath).'/release-task.saas-platform.json', '{"tampered":true}');

        $response = $this->actingAs($this->user())->getJson(route('agent-dashboard.logs', self::AGENT))->assertOk();

        $response->assertJsonPath('artifact.verified', false);
        $this->assertNotSame($this->artifactDigest, $response->json('artifact.computed_digest'));
        $errors = implode("\n", $response->json('artifact.errors'));
        $this->assertStringContainsString('Envelope digest mismatch', $errors);
        $this->assertStringContainsString('Attachment digest mismatch: release-task.saas-platform.json', $errors);
    }

    public function test_logs_never_read_an_artifact_path_outside_the_runtime_artifacts_directory(): void
    {
        $outside = $this->root.'/secret.json';
        file_put_contents($outside, '{"secret":"value"}');
        $this->appendJournal('artifact.created', 'orchestrator', ['artifact_id' => 'x', 'kind' => 'deployment-readiness', 'path' => $outside, 'digest' => 'abc', 'agent' => self::AGENT]);

        $response = $this->actingAs($this->user())->getJson(route('agent-dashboard.logs', self::AGENT))->assertOk();

        $response->assertJsonPath('artifact.verified', false)->assertJsonPath('artifact.content', null);
        $this->assertStringContainsString('outside the runtime artifacts directory', $response->json('artifact.errors.0'));
    }

    public function test_corrupt_journal_lines_are_skipped(): void
    {
        file_put_contents($this->root.'/agents/var/bus/journal.jsonl', "{not json\n", FILE_APPEND);

        $this->actingAs($this->user())->getJson(route('agent-dashboard.graph'))
            ->assertOk()->assertJsonPath('nodes.0.status', 'ATTENTION');
    }

    public function test_api_reports_503_when_the_runtime_is_missing(): void
    {
        config(['agent_dashboard.runtime_path' => $this->root.'/missing']);

        $this->actingAs($this->user())->getJson(route('agent-dashboard.graph'))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Workspace agent runtime not found at '.$this->root.'/missing.');
        $this->actingAs($this->user())->postJson(route('agent-dashboard.run', self::AGENT))->assertStatus(503);
    }

    public function test_dashboard_page_renders_the_canvas_for_admin_and_read_only_for_viewer(): void
    {
        $this->actingAs($this->user())->get(route('agent-dashboard.index'))
            ->assertOk()
            ->assertSee('id="ad-canvas"', false)
            ->assertSee('/vendor/drawflow/drawflow.min.js', false)
            ->assertSee('id="ad-save"', false)
            ->assertSee(str_replace('/', '\/', route('agent-dashboard.graph')), false)
            ->assertSee(__('nav.agent_dashboard'));

        $this->actingAs($this->user(User::ROLE_VIEWER))->get(route('agent-dashboard.index'))
            ->assertOk()
            ->assertDontSee('id="ad-save"', false)
            ->assertSee(__('agent_dashboard.read_only'));
    }

    private function user(string $role = User::ROLE_ADMIN): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function registryPath(): string
    {
        return $this->root.'/agents/agents.json';
    }

    private function registry(): array
    {
        return json_decode(file_get_contents($this->registryPath()), true);
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }

    /** Seals an artifact exactly as the runtime's ArtifactStore::persist() does. */
    private function sealArtifact(): void
    {
        $directory = $this->root.'/agents/var/artifacts/deployment-readiness/deployment-readiness-20261027T044619-fac32b9b';
        File::ensureDirectoryExists($directory);
        $attachment = json_encode(['schema' => 'release-task', 'project' => 'saas-platform'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        file_put_contents($directory.'/release-task.saas-platform.json', $attachment);

        $envelope = [
            'schema' => 'agents.artifact/v1',
            'artifact_id' => 'deployment-readiness-20261027T044619-fac32b9b',
            'kind' => 'deployment-readiness',
            'producer' => ['agent' => self::AGENT, 'version' => '1.0.0'],
            'task' => ['id' => 'task-1', 'type' => 'deployment.readiness', 'correlation_id' => 'task-1'],
            'created_at' => '2026-10-27T04:46:19+00:00',
            'provenance' => ['saas-platform' => ['commit' => 'abc123', 'path' => 'C:/laragon/www/saas-platform']],
            'payload' => [
                'verdict' => 'ATTENTION',
                'counts' => ['blocker' => 0, 'warning' => 2, 'info' => 1],
                'findings' => [
                    ['code' => 'RECOMMEND_GIT_DEPENDENCY', 'severity' => 'info', 'subject' => 'customer-site', 'message' => 'advisory'],
                    ['code' => 'CROSS_REPO_COUPLING', 'severity' => 'warning', 'subject' => 'customer-site:saas-platform/modules-theme-runtime', 'message' => 'Release saas-platform first.'],
                    ['code' => 'MIGRATION_REVIEW_REQUIRED', 'severity' => 'blocker', 'subject' => 'saas-platform', 'message' => 'Flagged migration lacks an owner review.', 'remedy' => 'Record a content-bound review.'],
                ],
            ],
            'attachments' => ['release-task.saas-platform.json' => ['sha256' => hash('sha256', $attachment), 'bytes' => strlen($attachment)]],
        ];
        $this->artifactDigest = hash('sha256', json_encode($this->sortKeys($envelope), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $envelope['integrity'] = ['algorithm' => 'sha256', 'scope' => 'canonical-envelope-without-integrity', 'digest' => $this->artifactDigest];
        $this->artifactPath = $directory.'/artifact.json';
        file_put_contents($this->artifactPath, json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
    }

    private function writeJournal(): void
    {
        file_put_contents($this->root.'/agents/var/bus/journal.jsonl', '');
        $this->appendJournal('task.started', 'orchestrator', ['task_type' => 'deployment.readiness', 'agent' => self::AGENT]);
        $this->appendJournal('artifact.created', 'orchestrator', [
            'artifact_id' => 'deployment-readiness-20261027T044619-fac32b9b',
            'kind' => 'deployment-readiness',
            'path' => str_replace('\\', '/', $this->artifactPath),
            'digest' => $this->artifactDigest,
            'agent' => self::AGENT,
        ]);
        $this->appendJournal('deployment.readiness.reported', self::AGENT, ['verdict' => 'ATTENTION', 'counts' => ['blocker' => 0, 'warning' => 2, 'info' => 1]]);
        $this->appendJournal('task.completed', 'orchestrator', [
            'task_type' => 'deployment.readiness',
            'agent' => self::AGENT,
            'status' => 'PASS',
            'summary' => 'ATTENTION: 0 blocker(s), 2 warning(s), 1 info',
            'findings' => ['info' => 1, 'warning' => 2, 'blocker' => 0],
            'duration_ms' => 13789,
            'error' => null,
        ]);
        // Another correlation chain that has nothing to do with the agent.
        file_put_contents($this->root.'/agents/var/bus/journal.jsonl', json_encode([
            'schema' => 'agents.message/v1', 'id' => 'msg-other', 'topic' => 'task.rejected', 'source' => 'orchestrator',
            'payload' => ['task_type' => 'unknown.task'], 'correlation_id' => 'task-other', 'causation_id' => null, 'hop' => 0,
            'occurred_at' => '2026-10-27T05:00:00+00:00',
        ]).PHP_EOL, FILE_APPEND);
    }

    private function appendJournal(string $topic, string $source, array $payload): void
    {
        static $sequence = 0;
        $sequence++;
        file_put_contents($this->root.'/agents/var/bus/journal.jsonl', json_encode([
            'schema' => 'agents.message/v1',
            'id' => 'msg-'.$sequence,
            'topic' => $topic,
            'source' => $source,
            'payload' => $payload,
            'correlation_id' => 'task-1',
            'causation_id' => 'task-1',
            'hop' => 0,
            'occurred_at' => sprintf('2026-10-27T04:46:%02d+00:00', $sequence % 60),
        ], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->sortKeys(...), $value);
    }
}
