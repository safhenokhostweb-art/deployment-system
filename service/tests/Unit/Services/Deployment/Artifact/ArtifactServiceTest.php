<?php

namespace Tests\Unit\Services\Deployment\Artifact;

use App\Models\Artifact;
use App\Models\Project;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\Deployment\Artifact\ArtifactPhysicalIntegrityException;
use App\Services\Deployment\Artifact\ArtifactValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Root-cause fix (production has no access to repo.packagist.org,
 * api.github.com, or the npm registry): ArtifactService::finalizeUpload()'s
 * defense-in-depth content validation — real zip fixtures built with
 * PHP's own ZipArchive, not mocked, proving the exact same "vendor/
 * entries imply vendor/autoload.php must exist" inference the Hub
 * applies independently of whatever the Local Agent's own
 * ArtifactBuilder already checked.
 */
class ArtifactServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
    }

    private function pendingArtifact(string $checksum, int $size = 100): Artifact
    {
        $project = Project::factory()->create();

        return app(ArtifactService::class)->createPending(
            project: $project,
            commitHash: str_repeat('a', 40),
            branch: 'main',
            claimedChecksum: $checksum,
            claimedSizeBytes: $size,
            uploadedBy: 'test-agent',
        );
    }

    private function buildZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'artifact-service-test-') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public function test_finalize_upload_succeeds_for_a_simple_valid_zip(): void
    {
        $zipPath = $this->buildZip(['index.php' => '<?php']);
        $bytes = file_get_contents($zipPath);
        $artifact = $this->pendingArtifact(hash('sha256', $bytes), strlen($bytes));

        $finalized = app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);

        $this->assertSame(Artifact::STATUS_AVAILABLE, $finalized->status);
        Storage::disk('artifacts')->assertExists($artifact->disk_path);
        @unlink($zipPath);
    }

    public function test_finalize_upload_rejects_a_checksum_mismatch(): void
    {
        $zipPath = $this->buildZip(['index.php' => '<?php']);
        $bytes = file_get_contents($zipPath);
        $artifact = $this->pendingArtifact(str_repeat('0', 64), strlen($bytes));

        $this->expectException(ArtifactValidationException::class);

        try {
            app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_finalize_upload_marks_the_artifact_failed_on_checksum_mismatch(): void
    {
        $zipPath = $this->buildZip(['index.php' => '<?php']);
        $bytes = file_get_contents($zipPath);
        $artifact = $this->pendingArtifact(str_repeat('0', 64), strlen($bytes));

        try {
            app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);
        } catch (ArtifactValidationException $e) {
            $this->assertSame('checksum_mismatch', $e->reason);
        } finally {
            @unlink($zipPath);
        }

        $this->assertSame(Artifact::STATUS_FAILED, $artifact->fresh()->status);
    }

    public function test_finalize_upload_rejects_a_corrupt_zip(): void
    {
        $bytes = 'this is not a valid zip file at all';
        $artifact = $this->pendingArtifact(hash('sha256', $bytes), strlen($bytes));

        try {
            app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);
            $this->fail('Expected an ArtifactValidationException.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('corrupt_zip', $e->reason);
        }
    }

    public function test_finalize_upload_rejects_an_empty_zip_archive(): void
    {
        // ZipArchive actively deletes the underlying file from disk when
        // its last entry is removed and closed (confirmed empirically) —
        // it never leaves a genuine zero-entry archive behind that way.
        // A real empty zip is just the bare End-Of-Central-Directory
        // record, so it has to be hand-built.
        $bytes = "PK\x05\x06" . str_repeat("\x00", 18);
        $artifact = $this->pendingArtifact(hash('sha256', $bytes), strlen($bytes));

        try {
            app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);
            $this->fail('Expected an ArtifactValidationException.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('empty_archive', $e->reason);
        }
    }

    public function test_finalize_upload_rejects_a_zip_with_vendor_entries_but_no_autoload(): void
    {
        $zipPath = $this->buildZip(['vendor/composer/installed.json' => '{}']);
        $bytes = file_get_contents($zipPath);
        $artifact = $this->pendingArtifact(hash('sha256', $bytes), strlen($bytes));

        try {
            app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);
            $this->fail('Expected an ArtifactValidationException.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('missing_vendor', $e->reason);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_finalize_upload_accepts_a_zip_with_vendor_autoload_present(): void
    {
        $zipPath = $this->buildZip(['vendor/autoload.php' => '<?php', 'vendor/composer/installed.json' => '{}']);
        $bytes = file_get_contents($zipPath);
        $artifact = $this->pendingArtifact(hash('sha256', $bytes), strlen($bytes));

        $finalized = app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);

        $this->assertSame(Artifact::STATUS_AVAILABLE, $finalized->status);
        @unlink($zipPath);
    }

    public function test_finalize_upload_rejects_a_zip_with_build_entries_but_no_manifest(): void
    {
        $zipPath = $this->buildZip(['public/build/app.abc123.js' => '// asset']);
        $bytes = file_get_contents($zipPath);
        $artifact = $this->pendingArtifact(hash('sha256', $bytes), strlen($bytes));

        try {
            app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);
            $this->fail('Expected an ArtifactValidationException.');
        } catch (ArtifactValidationException $e) {
            $this->assertSame('missing_build', $e->reason);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_finalize_upload_accepts_a_zip_with_no_vendor_or_build_at_all(): void
    {
        // A project using neither composer nor npm — perfectly valid.
        $zipPath = $this->buildZip(['index.html' => '<html></html>']);
        $bytes = file_get_contents($zipPath);
        $artifact = $this->pendingArtifact(hash('sha256', $bytes), strlen($bytes));

        $finalized = app(ArtifactService::class)->finalizeUpload($artifact, $bytes, 3);

        $this->assertSame(Artifact::STATUS_AVAILABLE, $finalized->status);
        @unlink($zipPath);
    }

    public function test_finalize_upload_rejects_an_oversized_body(): void
    {
        config(['deployment.artifact_max_size_mb' => 1]);
        $artifact = $this->pendingArtifact(hash('sha256', 'x'), 1);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ArtifactService::class)->finalizeUpload($artifact, str_repeat('x', 2 * 1024 * 1024), 3);
    }

    public function test_available_artifact_with_matching_physical_file_passes_preflight(): void
    {
        $artifact = Artifact::factory()->physicallyAvailable('valid artifact')->create();

        $validated = app(ArtifactService::class)->assertPhysicalIntegrity($artifact);

        $this->assertSame($artifact->id, $validated->id);
    }

    public function test_missing_physical_file_fails_preflight(): void
    {
        $artifact = Artifact::factory()->create();

        $this->assertIntegrityFailure($artifact, 'artifact_file_missing');
    }

    public function test_missing_database_record_fails_preflight(): void
    {
        $artifact = Artifact::factory()->physicallyAvailable()->create();
        $artifact->delete();

        $this->assertIntegrityFailure($artifact, 'artifact_record_missing');
    }

    public function test_physical_file_size_mismatch_fails_preflight(): void
    {
        $artifact = Artifact::factory()->physicallyAvailable('valid artifact')->create();
        $artifact->update(['size_bytes' => $artifact->size_bytes + 1]);

        $this->assertIntegrityFailure($artifact, 'artifact_size_mismatch');
    }

    public function test_physical_file_checksum_mismatch_fails_preflight(): void
    {
        $artifact = Artifact::factory()->physicallyAvailable('same-size-A')->create();
        Storage::disk('artifacts')->put($artifact->disk_path, 'same-size-B');

        $this->assertIntegrityFailure($artifact, 'artifact_checksum_mismatch');
    }

    public function test_unavailable_artifact_fails_preflight(): void
    {
        $artifact = Artifact::factory()->physicallyAvailable()->create();
        $artifact->update(['status' => Artifact::STATUS_FAILED]);

        $this->assertIntegrityFailure($artifact, 'artifact_unavailable');
    }

    private function assertIntegrityFailure(Artifact $artifact, string $reason): void
    {
        try {
            app(ArtifactService::class)->assertPhysicalIntegrity($artifact);
            $this->fail('Expected an ArtifactPhysicalIntegrityException.');
        } catch (ArtifactPhysicalIntegrityException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame(409, $exception->getStatusCode());
        }
    }
}
