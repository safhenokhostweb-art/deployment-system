<?php

namespace Tests\Feature\Console;

use App\Models\Artifact;
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
