<?php

namespace Tests\Feature\Console;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneStaleArtifactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_default_is_a_non_mutating_dry_run(): void
    {
        $artifact = $this->staleFailedArtifact();

        $this->artisan('deployment-system:prune-stale')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertDatabaseHas('artifacts', ['id' => $artifact->id]);
        Storage::disk('artifacts')->assertExists($artifact->disk_path);
    }

    public function test_execute_prunes_only_unreferenced_stale_records(): void
    {
        $stale = $this->staleFailedArtifact();
        $retained = $this->staleFailedArtifact();
        Deployment::factory()->create([
            'project_id' => $retained->project_id,
            'server_id' => $retained->project->server_id,
            'artifact_id' => $retained->id,
        ]);

        $this->artisan('deployment-system:prune-stale', ['--execute' => true])->assertSuccessful();

        $this->assertDatabaseMissing('artifacts', ['id' => $stale->id]);
        Storage::disk('artifacts')->assertMissing($stale->disk_path);
        $this->assertDatabaseHas('artifacts', ['id' => $retained->id]);
        Storage::disk('artifacts')->assertExists($retained->disk_path);
    }

    private function staleFailedArtifact(): Artifact
    {
        $project = Project::factory()->create();
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_FAILED,
            'created_at' => now()->subDays((int) config('deployment.artifact_retention_days') + 1),
            'disk_path' => $project->project_key . '/' . uniqid('stale-', true) . '.zip',
        ]);
        Storage::disk('artifacts')->put($artifact->disk_path, 'failed-upload');
        return $artifact;
    }
}
