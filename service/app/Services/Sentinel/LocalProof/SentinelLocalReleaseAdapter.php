<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use RuntimeException;
use ZipArchive;

final class SentinelLocalReleaseAdapter
{
    public function __construct(
        private readonly SentinelLocalProofFilesystem $filesystem,
        private readonly SentinelLocalArtifactAdapter $artifacts,
        private readonly SentinelLocalHubAdapter $hub,
    ) {}

    public function assertRecord(array $record, array $artifact): void
    {
        $config = $this->filesystem->config;
        if (!preg_match('/^[a-z0-9-]+$/D', $record['public_id'] ?? '')
            || ($record['project_key'] ?? null) !== $config->projectKey
            || ($record['environment'] ?? null) !== 'local'
            || ($record['commit_hash'] ?? null) !== $config->commitSha
            || ($artifact['manifest']['commit_sha'] ?? null) !== $config->commitSha
            || !hash_equals((string) ($record['checksum_sha256'] ?? ''), (string) ($artifact['checksum'] ?? ''))) {
            throw new RuntimeException('proof_release_record_mismatch');
        }
    }

    public function activate(string $deploymentId, string $expectedChecksum): string
    {
        $record = $this->hub->deployment($deploymentId);
        if ($record === null) { throw new RuntimeException('proof_deployment_missing'); }
        $artifact = $this->artifacts->receive($record['disk_path'], $expectedChecksum);
        $this->assertRecord($record, $artifact);
        $relative = 'runtime/application/releases/' . $deploymentId;
        $directory = $this->filesystem->directory($relative);
        if (count(scandir($directory)) !== 2) { throw new RuntimeException('proof_release_already_populated'); }
        $zip = new ZipArchive();
        if ($zip->open($artifact['path'], ZipArchive::RDONLY) !== true) { throw new RuntimeException('proof_zip_invalid'); }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) { continue; }
                // receive() validates every entry, including symlinks and source fidelity.
                $this->filesystem->write($relative . '/' . $name, $zip->getFromIndex($i));
            }
        } finally { $zip->close(); }
        $manifest = json_decode((string) file_get_contents($directory . '/.deployment/release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->artifacts->validateManifest($manifest);
        if (!hash_equals($expectedChecksum, hash_file('sha256', $artifact['path']))) { throw new RuntimeException('proof_artifact_changed'); }
        $this->filesystem->write($relative . '/.deployment/runtime-marker',
            'release:' . $deploymentId . '|commit:' . $manifest['commit_sha']);
        $current = $this->filesystem->assertContained($this->filesystem->config->path('runtime/application/current'));
        if (file_exists($current) || is_link($current)) { throw new RuntimeException('proof_current_already_exists'); }
        $temporary = $current . '.pending-' . bin2hex(random_bytes(8));
        $this->filesystem->assertContained($temporary);
        (new WindowsNativeSymlinkCreator())->create($temporary, $directory, $this->filesystem->config->root);
        if (!rename($temporary, $current)) {
            throw new RuntimeException('proof_symlink_activation_failed');
        }
        $this->assertCurrent($deploymentId);
        return $directory;
    }

    public function assertCurrent(string $deploymentId): string
    {
        $current = $this->filesystem->config->path('runtime/application/current');
        $this->filesystem->assertContained($current, true);
        $expected = $this->filesystem->config->path('runtime/application/releases/' . $deploymentId);
        $actual = realpath($current);
        if ($actual === false) {
            throw new RuntimeException('proof_current_mismatch');
        }
        $this->filesystem->assertContained($actual . '/.deployment/runtime-marker');
        $marker = file_get_contents($actual . '/.deployment/runtime-marker');
        $this->validateCurrentObservation($actual, (string) realpath($expected), is_link($current), (string) $marker, $deploymentId);
        return $actual;
    }

    public function validateCurrentObservation(string $actual, string $expected, bool $isLink, string $marker, string $deploymentId): void
    {
        $this->filesystem->assertResolved($actual);
        $required = str_replace('\\', '/', $this->filesystem->config->path('runtime/application/releases/' . $deploymentId));
        if (!$isLink || str_replace('\\', '/', $actual) !== str_replace('\\', '/', $expected)
            || strcasecmp(str_replace('\\', '/', $expected), $required) !== 0) {
            throw new RuntimeException('proof_current_mismatch');
        }
        if ($marker !== 'release:' . $deploymentId . '|commit:' . $this->filesystem->config->commitSha) {
            throw new RuntimeException('proof_runtime_marker_mismatch');
        }
    }
}
