<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use App\Services\Sentinel\ArtifactChecksumReader;
use App\Services\Sentinel\SentinelArtifactObservation;
use App\Services\Sentinel\SentinelCollectionTarget;
use App\Services\Sentinel\SentinelReleaseExpectation;
use RuntimeException;
use ZipArchive;

final class SentinelLocalArtifactAdapter implements ArtifactChecksumReader
{
    public const STORAGE_SOURCE_FIXTURES = [
        'storage/json-import-samples/bricks-sample.json',
        'storage/json-import-samples/custom-sample.json',
        'storage/json-import-samples/elementor-sample.json',
        'storage/json-import-samples/shopify-sample.json',
        'storage/json-import-samples/webflow-sample.json',
    ];

    private ?array $observed = null;
    public function __construct(private readonly SentinelLocalProofFilesystem $filesystem) {}

    /** Read committed blobs only. No fetch, push, build or working-tree copy. */
    public function sourceInventory(): array
    {
        $inventory = [];
        foreach ((new SentinelExactCommitExporter($this->filesystem))->readEntries() as $name => $entry) {
            // Export can preserve safe link metadata; executable release validation remains fail closed.
            if ($entry['mode'] === '120000') {
                throw new RuntimeException('proof_source_symlink_or_submodule');
            }
            if (in_array($name, self::STORAGE_SOURCE_FIXTURES, true)) {
                $this->assertFixtureSourceContained($name);
            }
            $inventory[$name] = $entry['sha256'];
        }
        if ($inventory === []) { throw new RuntimeException('proof_source_empty'); }
        return $inventory;
    }

    /** Shared source/ZIP policy: credential data matches file basenames, never directories. */
    public static function isSensitiveEntry(string $name): bool
    {
        return preg_match('~(^|/)(\.env(?:\.[^/]*)?|\.git|\.aws|\.ssh|\.npmrc|\.netrc|node_modules|auth\.json|id_rsa|id_ed25519|[^/]*\.(?:pem|key|p12|pfx))(/|$)~i', $name) === 1
            || (!str_ends_with($name, '/')
                && preg_match('~^credentials(?:-(?:prod|production|dev|staging))?(?:\.(?:json|yaml|yml|ini|conf|env|local))?$~iD', basename($name)) === 1);
    }

    public function assertEntry(string $name, array $requiredPaths): void
    {
        $canonical = str_replace('\\', '/', $name);
        // ZIP and Git names must already use canonical forward slashes.
        if ($canonical !== $name) { throw new RuntimeException('proof_artifact_entry_rejected'); }
        $name = $canonical;
        if ($name === '' || str_contains($name, "\0") || str_contains($name, ':') || str_contains($name, '\\')
            || preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.?(/|$))~', $name)
            || self::isSensitiveEntry($name)
            || (str_starts_with($name, 'storage/') && !str_ends_with($name, '/.gitignore')
                && !in_array($name, self::STORAGE_SOURCE_FIXTURES, true))
            || (str_starts_with($name, 'bootstrap/cache/') && !str_ends_with($name, '/.gitignore'))) {
            throw new RuntimeException('proof_artifact_entry_rejected');
        }
        if (str_starts_with($name, 'vendor/') && !in_array('vendor/autoload.php', $requiredPaths, true)) {
            throw new RuntimeException('proof_vendor_not_declared');
        }
    }

    private function assertFixtureSourceContained(string $name): void
    {
        $root = realpath($this->filesystem->config->sourceRepository);
        if ($root === false) { throw new RuntimeException('proof_source_changed'); }
        $path = $root;
        foreach (explode('/', $name) as $part) {
            $path .= '/' . $part;
            if (is_link($path)) { throw new RuntimeException('proof_source_symlink_or_submodule'); }
        }
        $resolved = realpath($path);
        $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if ($resolved === false || !is_file($resolved)
            || !str_starts_with(str_replace('\\', '/', $resolved), $prefix)) {
            throw new RuntimeException('proof_source_fixture_outside_root');
        }
    }

    /** Shared ZIP policy: exact storage names still require committed bytes. */
    public function assertZipEntry(string $name, array $requiredPaths, array $source, string|\Closure $bytes, int $mode): void
    {
        if (($mode & 0170000) === 0120000) { throw new RuntimeException('proof_zip_symlink'); }
        if (str_starts_with($name, 'storage/') && str_contains($name, '//')) {
            throw new RuntimeException('proof_artifact_entry_rejected');
        }
        $directory = str_ends_with($name, '/');
        if ($directory && in_array($name, ['storage/', 'storage/json-import-samples/'], true)) {
            foreach (self::STORAGE_SOURCE_FIXTURES as $fixture) {
                if (isset($source[$fixture])) { return; }
            }
            throw new RuntimeException('proof_untracked_content');
        }
        // Preserve the trailing slash so a directory is not classified as a credential file.
        $this->assertEntry($name, $requiredPaths);
        if (in_array(rtrim($name, '/'), self::STORAGE_SOURCE_FIXTURES, true)) {
            if ($directory || !isset($source[$name])) { throw new RuntimeException('proof_untracked_content'); }
            $fixtureBytes = $bytes instanceof \Closure ? $bytes() : $bytes;
            if (!hash_equals($source[$name], hash('sha256', $fixtureBytes))) {
                throw new RuntimeException('proof_live_worktree_content');
            }
        }
    }

    public function validateManifest(array $manifest): void
    {
        $config = $this->filesystem->config;
        $required = ['format', 'version', 'project_key', 'commit_sha', 'branch',
            'composer_lock_sha256', 'required_paths', 'source_paths', 'build_manifest_present',
            'build_result', 'agent_version', 'generated_at', 'status'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $manifest)) { throw new RuntimeException('proof_manifest_incomplete'); }
        }
        if ($manifest['format'] !== 'deployment-system-release-manifest'
            || !in_array($manifest['version'], [1, 2], true)
            || $manifest['project_key'] !== $config->projectKey
            || $manifest['commit_sha'] !== $config->commitSha || $manifest['branch'] !== $config->branch
            || $manifest['status'] !== 'pass' || $manifest['build_manifest_present'] !== true
            || !is_array($manifest['required_paths']) || !is_array($manifest['source_paths'])
            || !is_array($manifest['build_result'])
            || ($manifest['build_result']['ran_composer'] ?? null) !== true
            || !preg_match('/^[0-9a-f]{64}$/D', (string) $manifest['composer_lock_sha256'])) {
            throw new RuntimeException('proof_manifest_mismatch');
        }

        $ranNpmBuild = $manifest['build_result']['ran_npm_build'] ?? null;
        if ($ranNpmBuild !== true) {
            throw new RuntimeException('proof_manifest_npm_build_required');
        }

        foreach (['vendor/autoload.php', 'public/build/manifest.json', '.deployment/release-manifest.json'] as $path) {
            if (!in_array($path, $manifest['required_paths'], true)) { throw new RuntimeException('proof_required_path_missing'); }
        }
        if (!is_string($manifest['generated_at']) || strtotime($manifest['generated_at']) === false
            || !is_string($manifest['agent_version']) || $manifest['agent_version'] === '') {
            throw new RuntimeException('proof_manifest_incomplete');
        }
    }

    public function verifyChecksum(string $observed, string $expected): void
    {
        if (!preg_match('/^[0-9a-f]{64}$/D', $expected) || !hash_equals($expected, $observed)) {
            throw new RuntimeException('proof_checksum_mismatch');
        }
    }

    public function verifySourceEntries(array $committedHashes, array $archiveHashes): void
    {
        foreach ($committedHashes as $name => $digest) {
            if ($name === '.deployment/release-manifest.json') { continue; }
            if (!isset($archiveHashes[$name]) || !hash_equals($digest, $archiveHashes[$name])) {
                throw new RuntimeException('proof_live_worktree_content');
            }
        }
        foreach ($archiveHashes as $name => $digest) {
            if (!isset($committedHashes[$name]) && !str_starts_with($name, 'vendor/')
                && !str_starts_with($name, 'public/build/') && $name !== '.deployment/release-manifest.json') {
                throw new RuntimeException('proof_untracked_content');
            }
        }
    }

    /** No caller-supplied checksum or source provenance is trusted. */
    public function receive(string $zipPath, string $expectedChecksum): array
    {
        $this->filesystem->assertContained($zipPath);
        if (!is_file($zipPath) || !preg_match('/^[0-9a-f]{64}$/D', $expectedChecksum)) {
            throw new RuntimeException('proof_artifact_unavailable');
        }
        $checksum = hash_file('sha256', $zipPath);
        $this->verifyChecksum((string) $checksum, $expectedChecksum);
        if ($this->observed !== null
            && $this->observed['path'] === $zipPath
            && hash_equals($this->observed['checksum'], (string) $checksum)) {
            return $this->observed;
        }
        $source = $this->sourceInventory();
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) { throw new RuntimeException('proof_zip_invalid'); }
        try {
            $manifestBytes = $zip->getFromName('.deployment/release-manifest.json');
            if ($manifestBytes === false) { throw new RuntimeException('proof_manifest_incomplete'); }
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) { throw new RuntimeException('proof_manifest_incomplete'); }
            $this->validateManifest($manifest);

            $declared = $manifest['source_paths'];
            $actual = array_keys($source);
            sort($declared);
            sort($actual);
            if ($declared !== $actual) { throw new RuntimeException('proof_source_inventory_mismatch'); }
            $seen = [];
            $archiveHashes = [];

            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false || !isset($stat['name'], $stat['size'])) { throw new RuntimeException('proof_zip_invalid'); }
                $name = $stat['name'];
                $total += $stat['size'];
                if ($total > 536870912 || isset($seen[$name])) { throw new RuntimeException('proof_zip_size_or_duplicate'); }
                $seen[$name] = true;
                $opsys = $attributes = 0;
                $zip->getExternalAttributesIndex($index, $opsys, $attributes);
                $this->assertZipEntry($name, $manifest['required_paths'], $source,
                    fn (): string => (string) $zip->getFromIndex($index), $attributes >> 16);
                if (!str_ends_with($name, '/') && isset($source[$name])
                    && $name !== '.deployment/release-manifest.json') {
                    $sourceBytes = $zip->getFromIndex($index);
                    if ($sourceBytes === false || !hash_equals($source[$name], hash('sha256', $sourceBytes))) {
                        throw new RuntimeException('proof_live_worktree_content');
                    }
                }
                if (!str_ends_with($name, '/') && !isset($source[$name])
                    && !str_starts_with($name, 'vendor/') && !str_starts_with($name, 'public/build/')
                    && $name !== '.deployment/release-manifest.json') {
                    throw new RuntimeException('proof_untracked_content');
                }
                if (!str_ends_with($name, '/')) {
                    $entryBytes = $zip->getFromIndex($index);
                    if ($entryBytes === false) { throw new RuntimeException('proof_zip_invalid'); }
                    $archiveHashes[$name] = hash('sha256', $entryBytes);

                }
            }
            foreach ($source as $name => $digest) {
                if (!isset($seen[$name])) { throw new RuntimeException('proof_committed_source_missing'); }
            }
            $this->verifySourceEntries($source, $archiveHashes);

            if (!hash_equals($manifest['composer_lock_sha256'], hash('sha256', (string) $zip->getFromName('composer.lock')))) {
                throw new RuntimeException('proof_lock_mismatch');
            }
            foreach ($manifest['required_paths'] as $name) {
                if (!is_string($name) || $zip->locateName($name) === false) { throw new RuntimeException('proof_required_path_missing'); }
            }
            return $this->observed = ['path' => $zipPath, 'checksum' => $checksum,
                'size' => filesize($zipPath), 'manifest' => $manifest];
        } finally { $zip->close(); }
    }
    public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelArtifactObservation
    {
        if ($this->observed === null || $target->projectKey !== $this->filesystem->config->projectKey
            || $target->environment !== 'local') { return null; }
        $this->filesystem->assertContained($this->observed['path']);
        return new SentinelArtifactObservation(hash_file('sha256', $this->observed['path']), gmdate('c'));
    }
}
