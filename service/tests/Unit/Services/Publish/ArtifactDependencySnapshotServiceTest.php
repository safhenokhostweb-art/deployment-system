<?php

namespace Tests\Unit\Services\Publish;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Services\Publish\ArtifactDependencySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256): proves the
 * central new invariant this whole change exists for — a customer-site
 * Release's pinned SaaS commit is resolved exactly ONCE and frozen,
 * regardless of how many times it is polled/retried and regardless of
 * whether the upstream saas-platform project later ships a newer ready
 * Release. This is what makes rollback and exact-commit evidence
 * reproducible: the pin is a recorded fact about the Release, never a
 * live query re-evaluated on demand.
 */
class ArtifactDependencySnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('deployment.artifact_dependency_policies.customer-site.required', true);
    }

    private function makeReadySaasRelease(string $commit): Project
    {
        $saas = Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        $release = Release::factory()->ready()->create(['project_id' => $saas->id, 'commit_hash' => $commit, 'branch' => 'main']);
        Deployment::factory()->create([
            'project_id' => $saas->id,
            'server_id' => $saas->server_id,
            'release_id' => $release->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $commit,
            'branch' => 'main',
            'finished_at' => now(),
        ]);

        return $saas;
    }

    public function test_a_new_customer_site_release_resolves_and_freezes_one_saas_commit(): void
    {
        $firstCommit = str_repeat('1', 40);
        $this->makeReadySaasRelease($firstCommit);
        $customer = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);
        $release = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('c', 40), 'branch' => 'main']);
        $this->assertNull($release->dependency_snapshot);

        $contract = app(ArtifactDependencySnapshotService::class)->resolveForRelease($release);

        $this->assertSame(2, $contract['version']);
        $this->assertCount(7, $contract['dependencies']);
        $this->assertSame($firstCommit, $contract['dependencies'][0]['commit_sha']);

        $frozen = $release->fresh()->dependency_snapshot;
        $this->assertIsArray($frozen);
        $this->assertSame($contract, $frozen);
    }

    public function test_after_saas_advances_the_existing_release_still_emits_the_original_frozen_sha(): void
    {
        $firstCommit = str_repeat('1', 40);
        $saas = $this->makeReadySaasRelease($firstCommit);
        $customer = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);
        $release = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('c', 40), 'branch' => 'main']);
        $service = app(ArtifactDependencySnapshotService::class);
        $firstContract = $service->resolveForRelease($release);
        $this->assertSame($firstCommit, $firstContract['dependencies'][0]['commit_sha']);

        // saas-platform ships a NEWER ready Release/successful Deployment.
        $secondCommit = str_repeat('2', 40);
        $newerRelease = Release::factory()->ready()->create(['project_id' => $saas->id, 'commit_hash' => $secondCommit, 'branch' => 'main']);
        Deployment::factory()->create([
            'project_id' => $saas->id,
            'server_id' => $saas->server_id,
            'release_id' => $newerRelease->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $secondCommit,
            'branch' => 'main',
            'finished_at' => now()->addMinute(),
        ]);

        // Re-resolving (a retry) for the SAME Release must NOT advance.
        $retryContract = $service->resolveForRelease($release->fresh());
        $this->assertSame($firstCommit, $retryContract['dependencies'][0]['commit_sha']);
        $this->assertSame($firstContract, $retryContract);
    }

    public function test_a_new_customer_site_release_resolves_the_newer_authoritative_saas_sha(): void
    {
        $firstCommit = str_repeat('1', 40);
        $saas = $this->makeReadySaasRelease($firstCommit);
        $customer = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);
        $olderRelease = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('c', 40), 'branch' => 'main']);
        app(ArtifactDependencySnapshotService::class)->resolveForRelease($olderRelease);

        $secondCommit = str_repeat('2', 40);
        $newerSaasRelease = Release::factory()->ready()->create(['project_id' => $saas->id, 'commit_hash' => $secondCommit, 'branch' => 'main']);
        Deployment::factory()->create([
            'project_id' => $saas->id,
            'server_id' => $saas->server_id,
            'release_id' => $newerSaasRelease->id,
            'status' => Deployment::STATUS_SUCCEEDED,
            'commit_hash' => $secondCommit,
            'branch' => 'main',
            'finished_at' => now()->addMinute(),
        ]);

        $newerCustomerRelease = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('d', 40), 'branch' => 'main']);
        $contract = app(ArtifactDependencySnapshotService::class)->resolveForRelease($newerCustomerRelease);

        $this->assertSame($secondCommit, $contract['dependencies'][0]['commit_sha']);
        // The OLDER Release's own frozen snapshot is provably untouched.
        $this->assertSame($firstCommit, $olderRelease->fresh()->dependency_snapshot['dependencies'][0]['commit_sha']);
    }

    public function test_retrying_artifact_build_for_the_same_release_emits_the_exact_same_dependency_contract(): void
    {
        $this->makeReadySaasRelease(str_repeat('1', 40));
        $customer = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);
        $release = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('c', 40), 'branch' => 'main']);
        $service = app(ArtifactDependencySnapshotService::class);

        $first = $service->resolveForRelease($release);
        $second = $service->resolveForRelease($release->fresh());
        $third = $service->resolveForRelease($release->fresh());

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
    }

    public function test_legacy_release_without_a_snapshot_resolves_lazily_without_rewriting_other_rows(): void
    {
        $this->makeReadySaasRelease(str_repeat('1', 40));
        $customer = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);
        // Simulates a Release row that existed before this column did —
        // dependency_snapshot is null, not an empty array, and nothing
        // else about the row is touched by resolution.
        $legacyRelease = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('e', 40), 'branch' => 'main']);
        $untouchedSibling = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('f', 40), 'branch' => 'main']);
        $this->assertNull($legacyRelease->dependency_snapshot);

        $contract = app(ArtifactDependencySnapshotService::class)->resolveForRelease($legacyRelease);

        $this->assertNotEmpty($contract);
        $this->assertIsArray($legacyRelease->fresh()->dependency_snapshot);
        $this->assertNull($untouchedSibling->fresh()->dependency_snapshot, 'Resolving one Release must never touch an unrelated sibling Release.');
    }

    public function test_returns_empty_and_never_resolves_when_pinning_is_not_required(): void
    {
        config()->set('deployment.artifact_dependency_policies.customer-site.required', false);
        $customer = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);
        $release = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('c', 40), 'branch' => 'main']);

        // No saas-platform Project/Release/Deployment exists at all — if
        // resolution were attempted, this would throw. It must not.
        $contract = app(ArtifactDependencySnapshotService::class)->resolveForRelease($release);

        $this->assertSame([], $contract);
        $this->assertNull($release->fresh()->dependency_snapshot);
    }

    public function test_fails_closed_when_no_authoritative_saas_target_exists_and_freezes_nothing(): void
    {
        // customer-site requires pinning but saas-platform has no ready
        // Release/successful Deployment at all.
        Project::factory()->create(['project_key' => 'saas-platform', 'branch' => 'main']);
        $customer = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);
        $release = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('c', 40), 'branch' => 'main']);

        try {
            app(ArtifactDependencySnapshotService::class)->resolveForRelease($release);
            $this->fail('Expected resolution to fail closed with no authoritative saas-platform target.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        $this->assertNull($release->fresh()->dependency_snapshot, 'A failed resolution must never freeze a partial/invalid snapshot.');
    }

    /**
     * NO CONFIRM -> NO DEPLOYMENT: dependency snapshot resolution is an
     * Artifact-build-time concern only. It must never create, approve, or
     * otherwise mutate any Deployment row — it only ever READS the
     * upstream project's latestSuccessfulDeployment() to choose a pin.
     */
    public function test_resolving_a_snapshot_never_creates_or_mutates_any_deployment(): void
    {
        $this->makeReadySaasRelease(str_repeat('1', 40));
        $customer = Project::factory()->create(['project_key' => 'customer-site', 'branch' => 'main']);
        $release = Release::factory()->create(['project_id' => $customer->id, 'commit_hash' => str_repeat('c', 40), 'branch' => 'main']);
        $deploymentCountBefore = Deployment::query()->count();

        app(ArtifactDependencySnapshotService::class)->resolveForRelease($release);

        $this->assertSame($deploymentCountBefore, Deployment::query()->count());
        $this->assertDatabaseMissing('deployments', ['project_id' => $customer->id]);
    }
}
