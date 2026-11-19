<?php

namespace Tests\Feature\Console;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupArtifactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_deletes_an_expired_available_artifact_and_its_file(): void
    {
        $project = Project::factory()->create();
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
            'expires_at' => now()->subDay(),
            'disk_path' => "{$project->project_key}/expired.zip",
        ]);
        Storage::disk('artifacts')->put($artifact->disk_path, 'content');

        $this->artisan('artifacts:cleanup')->assertSuccessful();

        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
        Storage::disk('artifacts')->assertMissing($artifact->disk_path);
    }

    public function test_keeps_an_available_artifact_that_has_not_expired_yet(): void
    {
        $project = Project::factory()->create();
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('artifacts:cleanup')->assertSuccessful();

        $this->assertDatabaseHas('artifacts', ['id' => $artifact->id]);
    }

    public function test_preserves_an_expired_artifact_attached_to_deployment_history(): void
    {
        $project = Project::factory()->create();
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_AVAILABLE,
            'expires_at' => now()->subDay(),
            'disk_path' => "{$project->project_key}/retained.zip",
        ]);
        Storage::disk('artifacts')->put($artifact->disk_path, 'historical-content');
        Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'artifact_id' => $artifact->id,
            'status' => Deployment::STATUS_SUCCEEDED,
        ]);

        $this->artisan('artifacts:cleanup')->assertSuccessful();

        $this->assertDatabaseHas('artifacts', ['id' => $artifact->id]);
        Storage::disk('artifacts')->assertExists($artifact->disk_path);
    }

    public function test_deletes_an_upload_abandoned_past_the_timeout(): void
    {
        $project = Project::factory()->create();
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_UPLOADING,
            'created_at' => now()->subHours(3),
        ]);

        $this->artisan('artifacts:cleanup')->assertSuccessful();

        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
    }

    public function test_deleting_an_abandoned_upload_also_deletes_its_partial_file(): void
    {
        $project = Project::factory()->create();
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_UPLOADING,
            'created_at' => now()->subHours(3),
            'disk_path' => "{$project->project_key}/partial.zip",
        ]);
        Storage::disk('artifacts')->put($artifact->disk_path, 'partial-bytes');

        $this->artisan('artifacts:cleanup')->assertSuccessful();

        $this->assertDatabaseMissing('artifacts', ['id' => $artifact->id]);
        Storage::disk('artifacts')->assertMissing($artifact->disk_path);
    }

    public function test_an_abandoned_rebuild_of_a_deployed_artifact_keeps_its_row_as_deleted(): void
    {
        $project = Project::factory()->create();
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_UPLOADING,
            'created_at' => now()->subHours(3),
            'disk_path' => "{$project->project_key}/rebuild.zip",
        ]);
        Storage::disk('artifacts')->put($artifact->disk_path, 'partial-bytes');
        $deployment = Deployment::factory()->create([
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'artifact_id' => $artifact->id,
            'status' => Deployment::STATUS_SUCCEEDED,
        ]);

        $this->artisan('artifacts:cleanup')->assertSuccessful();

        $this->assertSame(Artifact::STATUS_DELETED, $artifact->fresh()->status);
        $this->assertSame($artifact->id, $deployment->fresh()->artifact_id);
        Storage::disk('artifacts')->assertMissing($artifact->disk_path);
    }

    public function test_keeps_an_upload_still_within_the_timeout_window(): void
    {
        $project = Project::factory()->create();
        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'status' => Artifact::STATUS_UPLOADING,
            'created_at' => now()->subMinutes(5),
        ]);

        $this->artisan('artifacts:cleanup')->assertSuccessful();

        $this->assertDatabaseHas('artifacts', ['id' => $artifact->id]);
    }
}
