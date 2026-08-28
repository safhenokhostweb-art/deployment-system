<?php

namespace Tests\Unit\Services\Deployment;

use App\Models\Agent;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Server;
use App\Services\Deployment\AgentJobService;
use App\Support\Deployment\FailureCodeGuidance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase: Laravel Release Completeness — the Hub must never dispatch an
 * artifact-based deployment to a Server Agent too old to enforce the
 * fail-closed release gate (agent/lib/ReleaseValidator.php), and the job
 * payload must carry the release metadata (commit_hash, required_paths)
 * the gate needs. An incompatible agent fails the deployment at claim
 * time, before the artifact is ever handed over.
 */
class AgentJobServiceReleaseGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    private function serverWithAgent(?string $agentVersion): Server
    {
        $server = Server::factory()->create();
        Agent::factory()->approved()->create([
            'server_id' => $server->id,
            'agent_version' => $agentVersion,
        ]);

        return $server;
    }

    private function artifactFor(Server $server, array $overrides = []): Artifact
    {
        $project = Project::factory()->create(['server_id' => $server->id]);

        return Artifact::factory()->physicallyAvailable()->create(array_merge([
            'project_id' => $project->id,
            'commit_hash' => str_repeat('a', 40),
            'required_paths' => ['composer.json', 'vendor/autoload.php'],
        ], $overrides));
    }

    private function queuedArtifactDeployment(Server $server, Artifact $artifact): Deployment
    {
        return Deployment::create([
            'project_id' => $artifact->project_id,
            'server_id' => $server->id,
            'artifact_id' => $artifact->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);
    }

    public function test_artifact_deployment_to_an_outdated_agent_is_rejected_before_claim(): void
    {
        $server = $this->serverWithAgent('1.1.0');
        $deployment = $this->queuedArtifactDeployment($server, $this->artifactFor($server));

        $claimed = app(AgentJobService::class)->claimNextJob($server);

        $this->assertNull($claimed);
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE, $deployment->fresh()->failure_code);
    }

    public function test_artifact_deployment_to_an_agent_with_unknown_version_is_rejected(): void
    {
        $server = $this->serverWithAgent(null);
        $deployment = $this->queuedArtifactDeployment($server, $this->artifactFor($server));

        $this->assertNull(app(AgentJobService::class)->claimNextJob($server));
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->fresh()->status);
    }

    public function test_artifact_deployment_to_a_current_agent_carries_release_metadata(): void
    {
        $server = $this->serverWithAgent('1.2.0');
        $artifact = $this->artifactFor($server, ['required_paths' => ['composer.json', 'vendor/autoload.php', 'public/build/manifest.json']]);
        $deployment = $this->queuedArtifactDeployment($server, $artifact);

        $claimed = app(AgentJobService::class)->claimNextJob($server);

        $this->assertNotNull($claimed);
        $this->assertSame($artifact->public_id, $claimed['job']['artifact']['public_id']);
        $this->assertSame(str_repeat('a', 40), $claimed['job']['artifact']['commit_hash']);
        $this->assertSame(['composer.json', 'vendor/autoload.php', 'public/build/manifest.json'], $claimed['job']['artifact']['required_paths']);
        $this->assertSame(Deployment::STATUS_RUNNING, $deployment->fresh()->status);
    }

    public function test_v2_artifact_is_blocked_after_policy_rollback_on_server_1_3_4_p1(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);
        $server = $this->serverWithAgent('1.3.4-p1');
        $artifact = $this->artifactFor($server, [
            'required_paths' => ['composer.json', 'vendor/autoload.php', '.deployment/dependency-lock-v2'],
        ]);
        $artifact->project->update(['project_key' => 'customer-site']);
        $deployment = $this->queuedArtifactDeployment($server, $artifact);

        $this->assertNull(app(AgentJobService::class)->claimNextJob($server));
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE, $deployment->fresh()->failure_code);
    }

    public function test_v2_artifact_dispatches_to_server_1_4_even_when_policy_is_off(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);
        $server = $this->serverWithAgent('1.4.0');
        $artifact = $this->artifactFor($server, [
            'required_paths' => ['composer.json', 'vendor/autoload.php', '.deployment/dependency-lock-v2'],
        ]);
        $artifact->project->update(['project_key' => 'customer-site']);
        $deployment = $this->queuedArtifactDeployment($server, $artifact);

        $claimed = app(AgentJobService::class)->claimNextJob($server);
        $this->assertNotNull($claimed);
        $this->assertTrue($claimed['job']['artifact']['dependency_lock_required']);
        $this->assertSame(Deployment::STATUS_RUNNING, $deployment->fresh()->status);
    }

    public function test_current_agent_receives_signed_persistent_runtime_contract(): void
    {
        $server = $this->serverWithAgent('1.3.4');
        $artifact = $this->artifactFor($server);
        $artifact->project->update([
            'artifact_preserve_paths' => ['.env'],
            'artifact_persistent_paths' => ['public/uploads' => 'uploads'],
            'artifact_persistent_root' => '/var/www/customer-site-shared',
        ]);
        $deployment = $this->queuedArtifactDeployment($server, $artifact);

        $claimed = app(AgentJobService::class)->claimNextJob($server);

        $this->assertSame([
            'paths' => ['public/uploads' => 'uploads'],
            'root' => '/var/www/customer-site-shared',
            'preserve_paths' => ['.env'],
        ], $claimed['job']['artifact_persistence']);
        $this->assertSame(Deployment::STATUS_RUNNING, $deployment->fresh()->status);
    }

    public function test_persistent_runtime_contract_rejects_agent_without_activation_safety_fix(): void
    {
        $server = $this->serverWithAgent('1.3.3');
        $artifact = $this->artifactFor($server);
        $artifact->project->update([
            'artifact_persistent_paths' => ['public/uploads' => 'uploads'],
            'artifact_persistent_root' => '/var/www/customer-site-shared',
        ]);
        $deployment = $this->queuedArtifactDeployment($server, $artifact);

        $this->assertNull(app(AgentJobService::class)->claimNextJob($server));
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_AGENT_PROTOCOL_INCOMPATIBLE, $deployment->fresh()->failure_code);
    }

    public function test_saas_persistence_requires_the_project_specific_agent_release(): void
    {
        $server = $this->serverWithAgent('1.3.4');
        $artifact = $this->artifactFor($server);
        $artifact->project->update([
            'project_key' => 'saas-platform',
            'artifact_preserve_paths' => ['.env'],
            'artifact_persistent_paths' => [
                'storage/app' => 'storage/app',
                'storage/logs' => 'storage/logs',
                'public/storage' => 'public/storage',
                'public/uploads' => 'public/uploads',
            ],
            'artifact_persistent_root' => '/var/www/saas-platform-shared',
        ]);
        $deployment = $this->queuedArtifactDeployment($server, $artifact);

        $this->assertNull(app(AgentJobService::class)->claimNextJob($server));
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->fresh()->status);
        $this->assertStringContainsString('1.3.5', (string) $deployment->fresh()->failure_message);
    }

    public function test_saas_persistence_dispatches_to_server_agent_1_3_5_with_signed_contract(): void
    {
        $server = $this->serverWithAgent('1.3.5');
        $artifact = $this->artifactFor($server);
        $paths = [
            'storage/app' => 'storage/app',
            'storage/logs' => 'storage/logs',
            'public/storage' => 'public/storage',
            'public/uploads' => 'public/uploads',
        ];
        $artifact->project->update([
            'project_key' => 'saas-platform',
            'artifact_preserve_paths' => ['.env'],
            'artifact_persistent_paths' => $paths,
            'artifact_persistent_root' => '/var/www/saas-platform-shared',
        ]);
        $deployment = $this->queuedArtifactDeployment($server, $artifact);

        $claimed = app(AgentJobService::class)->claimNextJob($server);

        $this->assertSame([
            'paths' => $paths,
            'root' => '/var/www/saas-platform-shared',
            'preserve_paths' => ['.env'],
        ], $claimed['job']['artifact_persistence']);
        $this->assertSame(Deployment::STATUS_RUNNING, $deployment->fresh()->status);
    }

    public function test_plain_git_pipeline_deployment_is_unaffected_by_the_gate(): void
    {
        $server = $this->serverWithAgent('1.0.0');
        $project = Project::factory()->create(['server_id' => $server->id]);
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]);

        $claimed = app(AgentJobService::class)->claimNextJob($server);

        $this->assertNotNull($claimed);
        $this->assertNull($claimed['job']['artifact']);
        $this->assertSame(Deployment::STATUS_RUNNING, $deployment->fresh()->status);
    }

    public function test_requires_complete_artifact_with_no_artifact_fails_closed_legacy(): void
    {
        $server = $this->serverWithAgent('1.2.0');
        $project = Project::factory()->create(['server_id' => $server->id, 'requires_complete_artifact' => true]);
        // Deliberately manufacture a legacy invalid row to keep the
        // claim-time defense covered; normal creation now rejects it.
        $deployment = Deployment::withoutEvents(fn () => Deployment::create([
            'public_id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]));

        $claimed = app(AgentJobService::class)->claimNextJob($server);

        $this->assertNull($claimed);
        $this->assertSame(Deployment::STATUS_FAILED, $deployment->fresh()->status);
        $this->assertSame(Deployment::FAILURE_CODE_ARTIFACT_REQUIRED_MISSING, $deployment->fresh()->failure_code);
        $this->assertStringContainsString('Artifact کامل اجباری است', FailureCodeGuidance::forCode($deployment->fresh()->failure_code));
        $this->assertStringContainsString('live-directory git pipeline اجرا نشده است', FailureCodeGuidance::forCode($deployment->fresh()->failure_code));
    }

    public function test_late_exact_commit_artifact_reattaches_at_claim_legacy(): void
    {
        $server = $this->serverWithAgent('1.2.0');
        $project = Project::factory()->create(['server_id' => $server->id, 'requires_complete_artifact' => true]);
        // Deliberately manufacture a legacy invalid row to keep the
        // late-attachment compatibility path covered.
        $deployment = Deployment::withoutEvents(fn () => Deployment::create([
            'public_id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'server_id' => $server->id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => str_repeat('a', 40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'queued_at' => now(),
        ]));
        // Simulate the timing race: artifact upload completed AFTER the
        // webhook created the deployment (so artifact_id is still null).
        $artifact = Artifact::factory()->physicallyAvailable()->create([
            'project_id' => $project->id,
            'commit_hash' => $deployment->commit_hash,
            'required_paths' => ['composer.json', 'vendor/autoload.php'],
        ]);

        $claimed = app(AgentJobService::class)->claimNextJob($server);

        $this->assertNotNull($claimed);
        $this->assertSame($artifact->public_id, $claimed['job']['artifact']['public_id']);
        $this->assertSame($deployment->commit_hash, $claimed['job']['artifact']['commit_hash']);
        $this->assertSame($artifact->id, $deployment->fresh()->artifact_id);
    }
}
