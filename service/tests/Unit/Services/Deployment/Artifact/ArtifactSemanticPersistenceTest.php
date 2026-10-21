<?php

namespace Tests\Unit\Services\Deployment\Artifact;

use App\Models\Artifact;
use App\Models\Project;
use App\Services\Deployment\Artifact\ArtifactSemanticValidator;
use App\Services\Deployment\Artifact\ArtifactValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtifactSemanticPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    public function test_source_bearing_persistent_parent_is_rejected(): void
    {
        $project = Project::factory()->create([
            'artifact_persistent_paths' => ['database'],
            'artifact_persistent_root' => '/srv/shared',
        ]);
        $artifact = $this->artifact($project, [
            'index.php',
            'database/migrations/2026_08_31_000000_schema.php',
            'database/factories/UserFactory.php',
        ]);

        try {
            app(ArtifactSemanticValidator::class)->validate($artifact);
            $this->fail('Dangerous persistent source overlap was accepted.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('persistent_source_overlap', $e->reason);
        }
    }

    public function test_file_level_sqlite_persistence_keeps_source_siblings_valid(): void
    {
        $project = Project::factory()->create([
            'artifact_persistent_paths' => ['database/database.sqlite'],
            'artifact_persistent_root' => '/srv/shared',
        ]);
        $artifact = $this->artifact($project, [
            'index.php',
            'database/migrations/2026_08_31_000000_schema.php',
            'database/factories/UserFactory.php',
        ]);

        app(ArtifactSemanticValidator::class)->validate($artifact);
        $this->addToAssertionCount(1);
    }

    public function test_exact_tracked_source_file_cannot_be_made_persistent(): void
    {
        $project = Project::factory()->create([
            'artifact_persistent_paths' => ['index.php'],
            'artifact_persistent_root' => '/srv/shared',
        ]);
        $artifact = $this->artifact($project, ['index.php']);

        try {
            app(ArtifactSemanticValidator::class)->validate($artifact);
            $this->fail('Tracked source file was accepted as persistent runtime data.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('persistent_source_overlap', $e->reason);
        }
    }

    public function test_overlapping_persistent_release_paths_are_rejected(): void
    {
        $project = Project::factory()->create([
            'artifact_persistent_paths' => ['database', 'database/database.sqlite'],
            'artifact_persistent_root' => '/srv/shared',
        ]);
        $artifact = $this->artifact($project, ['index.php']);

        try {
            app(ArtifactSemanticValidator::class)->validate($artifact);
            $this->fail('Overlapping persistent release paths were accepted.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('persistent_path_overlap', $e->reason);
        }
    }

    public function test_windows_absolute_source_inventory_path_is_rejected(): void
    {
        $project = Project::factory()->create([
            'artifact_persistent_paths' => ['database/database.sqlite'],
            'artifact_persistent_root' => '/srv/shared',
        ]);
        $artifact = $this->artifact($project, ['C:\\escape.php']);

        try {
            app(ArtifactSemanticValidator::class)->validate($artifact);
            $this->fail('Windows absolute source inventory path was accepted.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('persistent_source_contract_invalid', $e->reason);
        }
    }

    // ------------------------------------------------------------------
    // file-manifest.json validation when artifact declares a file_manifest_hash
    // ------------------------------------------------------------------

    public function test_file_manifest_required_when_hash_declared_but_missing_from_zip(): void
    {
        $project = Project::factory()->create([]);
        $sha = str_repeat('a', 40);
        $diskPath = 'test/'.bin2hex(random_bytes(8)).'.zip';
        $localPath = Storage::disk('artifacts')->path($diskPath);
        if (! is_dir(dirname($localPath))) {
            mkdir(dirname($localPath), 0777, true);
        }
        $zip = new \ZipArchive;
        $zip->open($localPath, \ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php return true;');
        $zip->addFromString('.deployment/release-manifest.json', json_encode([
            'format' => 'deployment-system-release-manifest',
            'project_key' => $project->project_key,
            'commit_sha' => $sha,
            'branch' => 'main',
            'status' => 'pass',
        ], JSON_THROW_ON_ERROR));
        $zip->close();

        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'disk_path' => $diskPath,
            'required_paths' => ['.deployment/release-manifest.json'],
            'checksum_sha256' => hash_file('sha256', $localPath),
            'size_bytes' => filesize($localPath),
            'file_manifest_hash' => hash('sha256', 'some-manifest'),
        ]);

        try {
            app(ArtifactSemanticValidator::class)->validate($artifact);
            $this->fail('Missing .deployment/file-manifest.json was accepted when file_manifest_hash is declared.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('missing_file_manifest', $e->reason);
        }
    }

    public function test_file_manifest_malformed_is_rejected(): void
    {
        $project = Project::factory()->create([]);
        $sha = str_repeat('a', 40);
        $diskPath = 'test/'.bin2hex(random_bytes(8)).'.zip';
        $localPath = Storage::disk('artifacts')->path($diskPath);
        if (! is_dir(dirname($localPath))) {
            mkdir(dirname($localPath), 0777, true);
        }
        $zip = new \ZipArchive;
        $zip->open($localPath, \ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php return true;');
        $zip->addFromString('.deployment/release-manifest.json', json_encode([
            'format' => 'deployment-system-release-manifest',
            'project_key' => $project->project_key,
            'commit_sha' => $sha,
            'branch' => 'main',
            'status' => 'pass',
        ], JSON_THROW_ON_ERROR));
        $zip->addFromString('.deployment/file-manifest.json', 'not-json-at-all');
        $zip->close();

        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'disk_path' => $diskPath,
            'required_paths' => ['.deployment/release-manifest.json'],
            'checksum_sha256' => hash_file('sha256', $localPath),
            'size_bytes' => filesize($localPath),
            'file_manifest_hash' => hash('sha256', 'not-json-at-all'),
        ]);

        try {
            app(ArtifactSemanticValidator::class)->validate($artifact);
            $this->fail('Malformed .deployment/file-manifest.json was accepted.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('file_manifest_invalid', $e->reason);
        }
    }

    public function test_file_manifest_with_valid_structure_is_accepted(): void
    {
        $project = Project::factory()->create([]);
        $sha = str_repeat('a', 40);
        $manifestContent = json_encode([
            'format' => 'deployment-system-file-manifest',
            'version' => 1,
            'entries' => ['index.php' => ['size' => 20, 'sha256' => hash('sha256', '<?php return true;')]],
        ]);
        $manifestHash = hash('sha256', $manifestContent);
        $diskPath = 'test/'.bin2hex(random_bytes(8)).'.zip';
        $localPath = Storage::disk('artifacts')->path($diskPath);
        if (! is_dir(dirname($localPath))) {
            mkdir(dirname($localPath), 0777, true);
        }
        $zip = new \ZipArchive;
        $zip->open($localPath, \ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php return true;');
        $zip->addFromString('.deployment/release-manifest.json', json_encode([
            'format' => 'deployment-system-release-manifest',
            'project_key' => $project->project_key,
            'commit_sha' => $sha,
            'branch' => 'main',
            'status' => 'pass',
        ], JSON_THROW_ON_ERROR));
        $zip->addFromString('.deployment/file-manifest.json', $manifestContent);
        $zip->close();

        $artifact = Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'disk_path' => $diskPath,
            'required_paths' => ['.deployment/release-manifest.json'],
            'checksum_sha256' => hash_file('sha256', $localPath),
            'size_bytes' => filesize($localPath),
            'file_manifest_hash' => $manifestHash,
        ]);

        app(ArtifactSemanticValidator::class)->validate($artifact);
        $this->addToAssertionCount(1);
    }

    private function artifact(Project $project, array $sourcePaths): Artifact
    {
        $sha = str_repeat('a', 40);
        $diskPath = 'test/'.bin2hex(random_bytes(8)).'.zip';
        $localPath = Storage::disk('artifacts')->path($diskPath);
        if (! is_dir(dirname($localPath))) {
            mkdir(dirname($localPath), 0777, true);
        }
        $zip = new \ZipArchive;
        $zip->open($localPath, \ZipArchive::CREATE);
        $zip->addFromString('index.php', '<?php return true;');
        $zip->addFromString('database/migrations/2026_08_31_000000_schema.php', '<?php');
        $zip->addFromString('database/factories/UserFactory.php', '<?php');
        $zip->addFromString('.deployment/release-manifest.json', json_encode([
            'format' => 'deployment-system-release-manifest',
            'project_key' => $project->project_key,
            'commit_sha' => $sha,
            'branch' => 'main',
            'status' => 'pass',
            'source_paths' => $sourcePaths,
        ], JSON_THROW_ON_ERROR));
        $zip->close();

        return Artifact::factory()->create([
            'project_id' => $project->id,
            'commit_hash' => $sha,
            'branch' => 'main',
            'disk_path' => $diskPath,
            'required_paths' => ['.deployment/release-manifest.json'],
            'checksum_sha256' => hash_file('sha256', $localPath),
            'size_bytes' => filesize($localPath),
        ]);
    }
}
