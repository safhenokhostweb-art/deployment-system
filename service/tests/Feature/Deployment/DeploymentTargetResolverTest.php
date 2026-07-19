<?php

namespace Tests\Feature\Deployment;

use App\Models\Environment;
use App\Models\Project;
use App\Services\Deployment\DeploymentTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md §4 — the adapter that
 * makes "no data migration" and "current Deployment must work unchanged"
 * simultaneously true.
 */
class DeploymentTargetResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): DeploymentTargetResolver
    {
        return app(DeploymentTargetResolver::class);
    }

    public function test_legacy_project_with_no_environment_resolves_to_one_synthesized_target(): void
    {
        $project = Project::factory()->create();

        $targets = $this->resolver()->resolveTargetsFor($project);

        $this->assertCount(1, $targets);
        $target = $targets[0];
        $this->assertTrue($target->isSynthesized);
        $this->assertNull($target->deploymentTargetId);
        $this->assertNull($target->agentId);
        $this->assertSame($project->server_id, $target->serverId);
        // The exact same string an already-installed agent's config.php
        // already maps to a working directory — nothing on that side
        // needs to change.
        $this->assertSame($project->project_key, $target->targetKey);
    }

    public function test_project_with_real_environment_resolves_to_its_active_targets_only(): void
    {
        $project = Project::factory()->create();
        $environment = Environment::factory()->create(['project_id' => $project->id]);

        $active1 = \App\Models\DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'name' => 'web-01',
            'is_active' => true,
        ]);
        $active2 = \App\Models\DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'name' => 'web-02',
            'is_active' => true,
        ]);
        \App\Models\DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'name' => 'web-03-excluded',
            'is_active' => false,
        ]);

        $targets = $this->resolver()->resolveTargetsFor($project);

        $this->assertCount(2, $targets);
        $keys = array_map(fn ($t) => $t->targetKey, $targets);
        $this->assertContains('web-01', $keys);
        $this->assertContains('web-02', $keys);
        $this->assertNotContains('web-03-excluded', $keys);

        foreach ($targets as $target) {
            $this->assertFalse($target->isSynthesized);
            $this->assertNotNull($target->deploymentTargetId);
        }
    }

    public function test_project_with_environment_but_zero_active_targets_resolves_to_empty_not_a_fallback(): void
    {
        // Presence of a real Environment row is what decides the branch —
        // not target count — so a half-configured new project doesn't
        // silently fall back to the legacy single-target behavior, which
        // would hide the misconfiguration.
        $project = Project::factory()->create();
        Environment::factory()->create(['project_id' => $project->id]);

        $targets = $this->resolver()->resolveTargetsFor($project);

        $this->assertSame([], $targets);
    }

    public function test_target_key_continuity_if_a_project_is_later_promoted(): void
    {
        $project = Project::factory()->create(['project_key' => 'acme-app-42']);

        $legacyTargets = $this->resolver()->resolveTargetsFor($project);
        $this->assertSame('acme-app-42', $legacyTargets[0]->targetKey);

        // Simulates promotion: a real target using the same key value.
        $environment = Environment::factory()->create(['project_id' => $project->id]);
        \App\Models\DeploymentTarget::factory()->create([
            'environment_id' => $environment->id,
            'name' => 'acme-app-42',
        ]);

        $promotedTargets = $this->resolver()->resolveTargetsFor($project);
        $this->assertSame('acme-app-42', $promotedTargets[0]->targetKey);
    }
}
