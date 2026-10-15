<?php

declare(strict_types=1);

namespace LocalAgent\Tests;

use LocalAgent\ArtifactFileManifest;
use LocalAgent\SafeFilesystem;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ArtifactFileManifest tests — covers deterministic manifest generation,
 * canonical hashing, duplicate/traversal path rejection, and verification.
 */
class ArtifactFileManifestTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/la-manifest-test-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        SafeFilesystem::recursiveDelete($this->tempDir);
    }

    // ------------------------------------------------------------------
    // Deterministic manifest generation
    // ------------------------------------------------------------------

    public function test_build_from_files_is_deterministic(): void
    {
        // Create test files
        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/subdir/file2.txt';
        mkdir(dirname($file2), 0755, true);
        file_put_contents($file1, 'content1');
        file_put_contents($file2, 'content2');

        $files = [
            'subdir/file2.txt' => $file2,
            'file1.txt' => $file1,
        ];

        $manifest1 = ArtifactFileManifest::buildFromFiles($files);
        $manifest2 = ArtifactFileManifest::buildFromFiles($files);

        $this->assertSame($manifest1->toJson(), $manifest2->toJson());
        $this->assertSame($manifest1->manifestHash(), $manifest2->manifestHash());
    }

    public function test_manifest_entries_sorted_by_path(): void
    {
        $file1 = $this->tempDir . '/z.txt';
        $file2 = $this->tempDir . '/a.txt';
        file_put_contents($file1, 'z');
        file_put_contents($file2, 'a');

        $files = ['z.txt' => $file1, 'a.txt' => $file2];
        $manifest = ArtifactFileManifest::buildFromFiles($files);

        $entries = $manifest->entries();
        $keys = array_keys($entries);
        $this->assertSame(['a.txt', 'z.txt'], $keys);
    }

    public function test_manifest_excludes_itself(): void
    {
        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/.deployment/file-manifest.json';
        mkdir(dirname($file2), 0755, true);
        file_put_contents($file1, 'content1');
        file_put_contents($file2, '{"format": "test"}');

        $files = [
            'file1.txt' => $file1,
            '.deployment/file-manifest.json' => $file2,
        ];

        $manifest = ArtifactFileManifest::buildFromFiles($files);

        $entries = $manifest->entries();
        $this->assertArrayHasKey('file1.txt', $entries);
        $this->assertArrayNotHasKey('.deployment/file-manifest.json', $entries);
    }

    public function test_manifest_rejects_duplicate_normalized_paths(): void
    {
        $file1 = $this->tempDir . '/file.txt';
        $file2 = $this->tempDir . './file.txt'; // Same normalized path
        file_put_contents($file1, 'content1');
        file_put_contents($file2, 'content2');

        $files = [
            'file.txt' => $file1,
            './file.txt' => $file2,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate normalized path');
        ArtifactFileManifest::buildFromFiles($files);
    }

    public function test_manifest_rejects_traversal_paths(): void
    {
        $file1 = $this->tempDir . '/file1.txt';
        file_put_contents($file1, 'content1');

        $files = [
            '../escape.txt' => $file1,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid path in artifact');
        ArtifactFileManifest::buildFromFiles($files);
    }

    public function test_manifest_rejects_absolute_paths(): void
    {
        $file1 = $this->tempDir . '/file1.txt';
        file_put_contents($file1, 'content1');

        $files = [
            '/absolute/path.txt' => $file1,
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid path in artifact');
        ArtifactFileManifest::buildFromFiles($files);
    }

    public function test_manifest_normalizes_windows_paths(): void
    {
        $file1 = $this->tempDir . '/subdir/file.txt';
        mkdir(dirname($file1), 0755, true);
        file_put_contents($file1, 'content');

        $files = [
            'subdir\\file.txt' => $file1, // Windows-style path
        ];

        $manifest = ArtifactFileManifest::buildFromFiles($files);
        $entries = $manifest->entries();
        $this->assertArrayHasKey('subdir/file.txt', $entries);
    }

    // ------------------------------------------------------------------
    // Canonical JSON and hash stability
    // ------------------------------------------------------------------

    public function test_canonical_json_is_stable(): void
    {
        $file1 = $this->tempDir . '/a.txt';
        $file2 = $this->tempDir . '/b.txt';
        file_put_contents($file1, 'a');
        file_put_contents($file2, 'b');

        $files = ['b.txt' => $file2, 'a.txt' => $file1];
        $manifest = ArtifactFileManifest::buildFromFiles($files);

        $json1 = $manifest->toJson();
        $json2 = $manifest->toJson();
        $this->assertSame($json1, $json2);

        // Hash should be stable
        $hash1 = $manifest->manifestHash();
        $hash2 = $manifest->manifestHash();
        $this->assertSame($hash1, $hash2);
    }

    public function test_hash_changes_when_content_changes(): void
    {
        $file1 = $this->tempDir . '/file.txt';
        file_put_contents($file1, 'original');

        $files = ['file.txt' => $file1];
        $manifest1 = ArtifactFileManifest::buildFromFiles($files);
        $hash1 = $manifest1->manifestHash();

        file_put_contents($file1, 'modified');
        $manifest2 = ArtifactFileManifest::buildFromFiles($files);
        $hash2 = $manifest2->manifestHash();

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_hash_changes_when_file_added(): void
    {
        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/file2.txt';
        file_put_contents($file1, 'content1');

        $files1 = ['file1.txt' => $file1];
        $manifest1 = ArtifactFileManifest::buildFromFiles($files1);
        $hash1 = $manifest1->manifestHash();

        file_put_contents($file2, 'content2');
        $files2 = ['file1.txt' => $file1, 'file2.txt' => $file2];
        $manifest2 = ArtifactFileManifest::buildFromFiles($files2);
        $hash2 = $manifest2->manifestHash();

        $this->assertNotSame($hash1, $hash2);
    }

    public function test_hash_changes_when_file_removed(): void
    {
        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/file2.txt';
        file_put_contents($file1, 'content1');
        file_put_contents($file2, 'content2');

        $files1 = ['file1.txt' => $file1, 'file2.txt' => $file2];
        $manifest1 = ArtifactFileManifest::buildFromFiles($files1);
        $hash1 = $manifest1->manifestHash();

        $files2 = ['file1.txt' => $file1];
        $manifest2 = ArtifactFileManifest::buildFromFiles($files2);
        $hash2 = $manifest2->manifestHash();

        $this->assertNotSame($hash1, $hash2);
    }

    // ------------------------------------------------------------------
    // Manifest verification (fromCanonical, fromJson)
    // ------------------------------------------------------------------

    public function test_from_canonical_verifies_hash(): void
    {
        $file1 = $this->tempDir . '/file.txt';
        file_put_contents($file1, 'content');

        $files = ['file.txt' => $file1];
        $manifest = ArtifactFileManifest::buildFromFiles($files);

        $entries = $manifest->entries();
        $hash = $manifest->manifestHash();

        // Should succeed with correct hash
        $verified = ArtifactFileManifest::fromCanonical($entries, $hash);
        $this->assertSame($hash, $verified->manifestHash());
    }

    public function test_from_canonical_rejects_wrong_hash(): void
    {
        $file1 = $this->tempDir . '/file.txt';
        file_put_contents($file1, 'content');

        $files = ['file.txt' => $file1];
        $manifest = ArtifactFileManifest::buildFromFiles($files);

        $entries = $manifest->entries();
        $wrongHash = str_repeat('0', 64);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Manifest hash does not match');
        ArtifactFileManifest::fromCanonical($entries, $wrongHash);
    }

    public function test_from_json_parses_correctly(): void
    {
        $file1 = $this->tempDir . '/file.txt';
        file_put_contents($file1, 'content');

        $files = ['file.txt' => $file1];
        $manifest = ArtifactFileManifest::buildFromFiles($files);
        $json = $manifest->toJson();

        $parsed = ArtifactFileManifest::fromJson($json);
        $this->assertSame($manifest->manifestHash(), $parsed->manifestHash());
        $this->assertSame($manifest->entryCount(), $parsed->entryCount());
        $this->assertSame($manifest->totalSize(), $parsed->totalSize());
    }

    public function test_from_json_rejects_wrong_format(): void
    {
        $json = '{"format": "wrong", "version": 1, "entries": {}, "manifest_hash": "'.str_repeat('0', 64).'"}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported manifest format');
        ArtifactFileManifest::fromJson($json);
    }

    public function test_from_json_rejects_wrong_version(): void
    {
        $json = '{"format": "deployment-system-file-manifest", "version": 999, "entries": {}, "manifest_hash": "'.str_repeat('0', 64).'"}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported manifest version');
        ArtifactFileManifest::fromJson($json);
    }

    public function test_from_json_rejects_malformed_hash(): void
    {
        $json = '{"format": "deployment-system-file-manifest", "version": 1, "entries": {}, "manifest_hash": "not-a-hash"}';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Manifest hash is missing or malformed');
        ArtifactFileManifest::fromJson($json);
    }

    public function test_entry_count_and_total_size(): void
    {
        $file1 = $this->tempDir . '/file1.txt';
        $file2 = $this->tempDir . '/file2.txt';
        file_put_contents($file1, 'hello'); // 5 bytes
        file_put_contents($file2, 'world'); // 5 bytes

        $files = ['file1.txt' => $file1, 'file2.txt' => $file2];
        $manifest = ArtifactFileManifest::buildFromFiles($files);

        $this->assertSame(2, $manifest->entryCount());
        $this->assertSame(10, $manifest->totalSize());
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function test_empty_manifest(): void
    {
        $files = [];
        $manifest = ArtifactFileManifest::buildFromFiles($files);

        $this->assertSame(0, $manifest->entryCount());
        $this->assertSame(0, $manifest->totalSize());
        $this->assertSame('[]', json_decode($manifest->toJson(), true)['entries']);
    }

    public function test_unreadable_file_throws(): void
    {
        $file1 = $this->tempDir . '/file.txt';
        file_put_contents($file1, 'content');
        chmod($file1, 0000); // Make unreadable

        $files = ['file.txt' => $file1];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not readable');
        ArtifactFileManifest::buildFromFiles($files);
    }
}