<?php

namespace Tests\Feature\Dashboard;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\SentinelVerificationRun;
use App\Models\User;
use App\Services\Sentinel\AgentRuntimeReader;
use App\Services\Sentinel\ArtifactChecksumReader;
use App\Services\Sentinel\IndependentHealthProbe;
use App\Services\Sentinel\ReleaseExpectationProvider;
use App\Services\Sentinel\SentinelArtifactObservation;
use App\Services\Sentinel\SentinelCollectionTarget;
use App\Services\Sentinel\SentinelEvidenceCollector;
use App\Services\Sentinel\SentinelHealthObservation;
use App\Services\Sentinel\SentinelReleaseExpectation;
use App\Services\Sentinel\SentinelRuntimeSnapshot;
use App\Services\Sentinel\SentinelVerificationRunStore;
use App\Services\Sentinel\SentinelEvidenceCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SentinelVerificationUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_operator_can_collect_and_review_fail_closed_evidence(): void
    {
        $project = Project::factory()->create(['environment' => 'production']);
        $commit = str_repeat('a', 40);
        $release = Release::query()->create([
            'project_id' => $project->id,
            'commit_hash' => $commit,
            'branch' => 'main',
            'status' => Release::STATUS_READY,
        ]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'commit_hash' => $commit,
            'status' => Deployment::STATUS_SUCCEEDED,
        ]);

        $this->app->instance(SentinelEvidenceCollector::class, $this->collector());
        $operator = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($operator)
            ->post(route('deployments.sentinel.verify', $deployment))
            ->assertRedirect(route('deployments.show', $deployment));

        $run = SentinelVerificationRun::query()->where('deployment_id', $deployment->id)->sole();
        $this->assertSame('UNKNOWN', $run->verdict, 'Missing independent artifact evidence must fail closed.');
        $this->assertNotNull($run->evidence_hash);
        $this->assertSame('unknown', $run->checks['recorded_checks']['status']);

        $this->actingAs($operator)
            ->get(route('deployments.show', $deployment))
            ->assertOk()
            ->assertSee('Sentinel deployment evidence checks')
            ->assertSee('Deployment status:')
            ->assertSee('Evidence collection status:')
            ->assertSee('Observed runtime consistency:')
            ->assertSee('Independent health probe:')
            ->assertSee('Overall verification verdict:')
            ->assertSee('does not prove all application code or behavior is correct')
            ->assertSee('INCONCLUSIVE')
            ->assertSee($run->evidence_hash);

        $deployment->refresh();
        $this->assertSame(Deployment::STATUS_SUCCEEDED, $deployment->status, 'Evidence collection must not alter deployment state.');
    }

    public function test_missing_release_context_returns_a_controlled_message_without_a_run(): void
    {
        $deployment = Deployment::factory()->create(['release_id' => null]);
        $operator = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($operator)
            ->post(route('deployments.sentinel.verify', $deployment))
            ->assertRedirect(route('deployments.show', $deployment))
            ->assertSessionHas('error', 'Sentinel could not resolve a matching deployment and release context. No verification result was recorded.');

        self::assertSame(0, SentinelVerificationRun::query()->where('deployment_id', $deployment->id)->count());
    }

    public function test_collector_exception_is_recorded_only_as_inconclusive(): void
    {
        [$project, $release, $deployment, $operator] = $this->deploymentContext();
        $this->app->instance(SentinelEvidenceCollection::class, new class implements SentinelEvidenceCollection {
            public function collect(SentinelCollectionTarget $target): \App\Services\Sentinel\SentinelCollectionOutcome
            {
                throw new \RuntimeException('sensitive adapter detail that must not leak');
            }
        });

        $this->actingAs($operator)
            ->post(route('deployments.sentinel.verify', $deployment))
            ->assertRedirect(route('deployments.show', $deployment));

        $run = SentinelVerificationRun::query()->where('deployment_id', $deployment->id)->sole();
        self::assertSame('UNKNOWN', $run->verdict);
        self::assertNull($run->evidence);
        self::assertStringNotContainsString('sensitive adapter detail', json_encode($run->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_persistence_failure_is_not_reported_as_a_recorded_result(): void
    {
        [, , $deployment, $operator] = $this->deploymentContext();
        $this->app->instance(SentinelEvidenceCollector::class, $this->collector());
        $this->app->instance(SentinelVerificationRunStore::class, new class implements SentinelVerificationRunStore {
            public function record(array $attributes): SentinelVerificationRun
            {
                throw new \RuntimeException('database adapter failure');
            }
        });

        $this->actingAs($operator)
            ->post(route('deployments.sentinel.verify', $deployment))
            ->assertRedirect(route('deployments.show', $deployment))
            ->assertSessionHas('error', 'Sentinel could not record the verification result. No durable verification record is available.');

        self::assertSame(0, SentinelVerificationRun::query()->where('deployment_id', $deployment->id)->count());
    }

    public function test_completed_run_model_updates_and_deletes_are_blocked(): void
    {
        [$project, $release, $deployment, $operator] = $this->deploymentContext();
        $this->app->instance(SentinelEvidenceCollector::class, $this->collector());
        $this->actingAs($operator)->post(route('deployments.sentinel.verify', $deployment));
        $run = SentinelVerificationRun::query()->where('deployment_id', $deployment->id)->sole();

        $run->verdict = 'VERIFIED';
        try {
            $run->save();
            self::fail('Model update should have been blocked.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('cannot be updated', $exception->getMessage());
        }

        try {
            $run->delete();
            self::fail('Model deletion should have been blocked.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        self::assertDatabaseHas('sentinel_verification_runs', ['id' => $run->id, 'verdict' => 'UNKNOWN']);
    }

    public function test_latest_run_tie_breaks_by_descending_primary_key(): void
    {
        [, , $deployment, $operator] = $this->deploymentContext();
        $collectedAt = now()->startOfSecond();
        $base = [
            'deployment_id' => $deployment->id,
            'user_id' => $operator->id,
            'verdict' => 'FAILED',
            'checks' => [],
            'reasons' => [],
            'collected_at' => $collectedAt,
        ];
        $older = SentinelVerificationRun::query()->forceCreate($base);
        $base['verdict'] = 'UNKNOWN';
        $newer = SentinelVerificationRun::query()->forceCreate($base);

        $this->actingAs($operator)
            ->get(route('deployments.show', $deployment))
            ->assertOk()
            ->assertSee('(UNKNOWN)')
            ->assertDontSee('(FAILED)');
    }

    private function deploymentContext(): array
    {
        $project = Project::factory()->create(['environment' => 'production']);
        $commit = str_repeat('a', 40);
        $release = Release::query()->create([
            'project_id' => $project->id,
            'commit_hash' => $commit,
            'branch' => 'main',
            'status' => Release::STATUS_READY,
        ]);
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'release_id' => $release->id,
            'commit_hash' => $commit,
            'status' => Deployment::STATUS_SUCCEEDED,
        ]);

        return [$project, $release, $deployment, User::factory()->create(['role' => User::ROLE_ADMIN])];
    }

    private function collector(): SentinelEvidenceCollector
    {
        return new SentinelEvidenceCollector(
            new class implements ReleaseExpectationProvider {
                public function expectation(SentinelCollectionTarget $target): ?SentinelReleaseExpectation
                {
                    $commit = str_repeat('a', 40);
                    return new SentinelReleaseExpectation(
                        $target->projectKey,
                        $target->environment,
                        $target->releaseId,
                        $commit,
                        null,
                        null,
                        'release:'.$target->deploymentPublicId.'|commit:'.$commit,
                        $target->deploymentPublicId,
                        'https://health.example.test/status',
                        200,
                        'server-agent-1',
                    );
                }
            },
            new class implements AgentRuntimeReader {
                public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelRuntimeSnapshot
                {
                    return new SentinelRuntimeSnapshot(
                        'server-agent-1', '1.0.0', $target->releaseId, str_repeat('a', 40),
                        'releases/'.$target->deploymentPublicId,
                        'release:'.$target->deploymentPublicId.'|commit:'.str_repeat('a', 40),
                        $target->collectedAt,
                    );
                }
            },
            new class implements ArtifactChecksumReader {
                public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelArtifactObservation
                {
                    return null;
                }
            },
            new class implements IndependentHealthProbe {
                public function probe(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelHealthObservation
                {
                    return new SentinelHealthObservation(200);
                }
            },
        );
    }
}
