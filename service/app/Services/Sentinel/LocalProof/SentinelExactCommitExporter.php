<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use ZipArchive;

/** Source-only export. No checkout, attributes conversion, build or link materialization. */
final class SentinelExactCommitExporter
{
    private const BLOB_BATCH_SIZE = 32;

    private readonly ?\Closure $processFactory;

    public function __construct(
        private readonly SentinelLocalProofFilesystem $filesystem,
        ?callable $processFactory = null,
    ) {
        $this->processFactory = $processFactory === null ? null : \Closure::fromCallable($processFactory);
    }

    private function git(
        array $arguments,
        ?string $blobOid = null,
        ?string $relativePath = null,
        ?string $input = null,
    ): string
    {
        $command = array_merge(['git', '-C', $this->filesystem->config->sourceRepository], $arguments);
        $process = $this->processFactory === null
            ? new Process($command)
            : ($this->processFactory)($command, $arguments);
        if (!$process instanceof Process) { throw new \LogicException('Invalid Git process factory result.'); }
        $process->setTimeout(30);
        if ($input !== null) { $process->setInput($input); }
        $operation = $this->gitOperation($arguments);
        try {
            $process->run();
        } catch (ProcessTimedOutException $error) {
            $timedOutProcess = $error->getProcess();
            throw new SentinelGitFailure('proof_source_git_failed', $this->gitContext(
                $operation, $blobOid, $relativePath, $timedOutProcess->getExitCode(), true,
                $timedOutProcess->getErrorOutput(), 'GIT_TIMEOUT'), $error);
        } catch (ProcessStartFailedException $error) {
            $failedProcess = $error->getProcess();
            throw new SentinelGitFailure('proof_source_git_failed', $this->gitContext(
                $operation, $blobOid, $relativePath, $failedProcess->getExitCode(), false,
                $failedProcess->getErrorOutput(), 'UNKNOWN_GIT_FAILURE'), $error);
        }
        if (!$process->isSuccessful()) {
            $stderr = $process->getErrorOutput();
            throw new SentinelGitFailure('proof_source_git_failed', $this->gitContext(
                $operation, $blobOid, $relativePath, $process->getExitCode(), false, $stderr,
                $this->classifyGitFailure($stderr, $process->getExitCode())));
        }
        return $process->getOutput();
    }

    private function gitOperation(array $arguments): string
    {
        return match ($arguments[0] ?? '') {
            'cat-file' => 'cat_file_blob',
            'ls-tree' => 'ls_tree',
            'rev-parse' => ($arguments[1] ?? '') === '--verify' ? 'verify_commit' : 'read_head',
            'branch' => 'read_branch',
            'show' => 'read_commit_time',
            default => 'git_other',
        };
    }

    private function gitContext(
        string $operation,
        ?string $blobOid,
        ?string $relativePath,
        ?int $exitCode,
        bool $timedOut,
        string $stderr,
        string $classification,
    ): array {
        return [
            'git_operation' => $operation,
            'blob_oid' => $this->safeOid($blobOid),
            'repository_relative_path' => $this->safeRepositoryPath($relativePath),
            'git_exit_code' => $exitCode,
            'git_timeout' => $timedOut,
            'stderr_classification' => $classification,
            'stderr_present' => $stderr !== '',
        ];
    }

    private function safeOid(?string $oid): ?string
    {
        return $oid !== null && preg_match('/^[0-9a-f]{40}$/D', $oid) ? $oid : null;
    }

    private function safeRepositoryPath(?string $path): ?string
    {
        if ($path === null) { return null; }
        if (strlen($path) > 512 || !preg_match('~^[A-Za-z0-9._/-]+$~D', $path)
            || str_contains($path, '//') || str_ends_with($path, '/')
            || preg_match('~(^|/)\.\.?(/|$)~', $path)
            || preg_match('~(^|/)(credentials[^/]*|secrets?[^/]*|auth\.json|\.env[^/]*|private\.(key|pem|p12|pfx)|id_rsa|id_ed25519)(/|$)~i', $path)) {
            return '[REDACTED]';
        }
        return $path;
    }

    private function classifyGitFailure(string $stderr, ?int $exitCode): string
    {
        if (preg_match('/(?:path .+ does not exist|object .+ (?:is missing|not found)|could not get object info|not a valid object name)/i', $stderr)) {
            return 'GIT_OBJECT_MISSING';
        }
        if (preg_match('/(?:unable to read|failed to read|corrupt|object file .+ is empty)/i', $stderr)) {
            return 'GIT_OBJECT_UNREADABLE';
        }
        return $exitCode === null ? 'UNKNOWN_GIT_FAILURE' : 'GIT_PROCESS_FAILED';
    }

    private function commit(): string
    {
        $config = $this->filesystem->config;
        $sha = $config->commitSha;
        if (!preg_match('/^[0-9a-f]{40}$/D', $sha)
            || trim($this->git(['rev-parse', '--verify', $sha . '^{commit}'])) !== $sha
            || trim($this->git(['rev-parse', 'HEAD'])) !== $sha
            || trim($this->git(['branch', '--show-current'])) !== $config->branch) {
            throw new RuntimeException('proof_source_changed');
        }
        return $sha;
    }

    /** @return array<string, array{mode: string, oid: string, bytes: string, size: int, sha256: string}> */
    public function readEntries(?array $paths = null): array
    {
        $commit = $this->commit();
        $policy = new SentinelLocalArtifactAdapter($this->filesystem);
        $tree = [];
        foreach (explode("\0", $this->git(['ls-tree', '-r', '-z', '--full-tree', $commit])) as $record) {
            if ($record === '') { continue; }
            if (!preg_match('/^(100644|100755|120000) blob ([0-9a-f]{40})\t(.+)$/sD', $record, $match)) {
                throw new SentinelGitFailure('proof_source_mode_rejected', [
                    'git_operation' => 'ls_tree',
                    'blob_oid' => null,
                    'repository_relative_path' => null,
                    'git_exit_code' => 0,
                    'git_timeout' => false,
                    'stderr_classification' => 'GIT_OUTPUT_INVALID',
                    'stderr_present' => false,
                ]);
            }
            $name = $match[3];
            // Filter names before reading any environment/credential object, including examples.
            if (SentinelLocalArtifactAdapter::isSensitiveEntry($name)) { continue; }
            if (str_contains($name, '//') || str_ends_with($name, '/')) {
                throw new RuntimeException('proof_artifact_entry_rejected');
            }
            $policy->assertEntry($name, []);
            $tree[$name] = ['mode' => $match[1], 'oid' => $match[2]];
        }
        if ($paths !== null) {
            foreach ($paths as $name) {
                if (!is_string($name)) { throw new RuntimeException('proof_source_path_missing'); }
                $policy->assertEntry($name, []);
                if (!isset($tree[$name])) { throw new RuntimeException('proof_source_path_missing'); }
            }
            $tree = array_intersect_key($tree, array_fill_keys($paths, true));
        }
        if ($tree === []) { throw new RuntimeException('proof_source_empty'); }
        $entries = [];
        foreach (array_chunk($tree, self::BLOB_BATCH_SIZE, true) as $batch) {
            $oids = array_column($batch, 'oid');
            $singleName = count($batch) === 1 ? array_key_first($batch) : null;
            $singleMetadata = $singleName === null ? null : $batch[$singleName];
            try {
                $output = $this->git(
                    ['cat-file', '--batch'],
                    $singleMetadata['oid'] ?? null,
                    $singleName,
                    implode("\n", $oids) . "\n",
                );
            } catch (RuntimeException $error) {
                throw new RuntimeException('proof_source_blob_unavailable', 0, $error);
            }

            $offset = 0;
            foreach ($batch as $name => $metadata) {
                $headerEnd = strpos($output, "\n", $offset);
                if ($headerEnd === false) {
                    throw $this->blobProtocolFailure($metadata['oid'], $name, 'GIT_OUTPUT_INVALID');
                }
                $header = substr($output, $offset, $headerEnd - $offset);
                $offset = $headerEnd + 1;
                if ($header === $metadata['oid'] . ' missing') {
                    throw $this->blobProtocolFailure($metadata['oid'], $name, 'GIT_OBJECT_MISSING');
                }
                if (!preg_match('/^([0-9a-f]{40}) blob ([0-9]+)$/D', $header, $match)
                    || !hash_equals($metadata['oid'], $match[1])) {
                    throw $this->blobProtocolFailure($metadata['oid'], $name, 'GIT_OUTPUT_INVALID');
                }
                $size = filter_var($match[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($size === false || $size > strlen($output) - $offset - 1) {
                    throw $this->blobProtocolFailure($metadata['oid'], $name, 'GIT_OUTPUT_INVALID');
                }
                $bytes = substr($output, $offset, $size);
                $offset += $size;
                if (($output[$offset] ?? null) !== "\n") {
                    throw $this->blobProtocolFailure($metadata['oid'], $name, 'GIT_OUTPUT_INVALID');
                }
                $offset++;

                // Verify both object identity and content; no working-tree file is read.
                if (!hash_equals($metadata['oid'], hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes))) {
                    throw new SentinelGitFailure('proof_source_blob_mismatch', [
                        'git_operation' => 'cat_file_blob',
                        'blob_oid' => $metadata['oid'],
                        'repository_relative_path' => $this->safeRepositoryPath($name),
                        'git_exit_code' => 0,
                        'git_timeout' => false,
                        'stderr_classification' => 'GIT_BLOB_HASH_MISMATCH',
                        'stderr_present' => false,
                    ]);
                }
                if ($metadata['mode'] === '120000') { $this->assertLinkTarget($name, $bytes); }
                $entries[$name] = $metadata + ['bytes' => $bytes, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            }
            if ($offset !== strlen($output)) {
                throw $this->blobProtocolFailure('', '', 'GIT_OUTPUT_INVALID');
            }
        }
        return $entries;
    }

    private function blobProtocolFailure(string $blobOid, string $relativePath, string $classification): SentinelGitFailure
    {
        return new SentinelGitFailure('proof_source_blob_unavailable', [
            'git_operation' => 'cat_file_blob',
            'blob_oid' => $this->safeOid($blobOid),
            'repository_relative_path' => $this->safeRepositoryPath($relativePath),
            'git_exit_code' => 0,
            'git_timeout' => false,
            'stderr_classification' => $classification,
            'stderr_present' => false,
        ]);
    }

    /** Validate lexically; never follow a source or exported symlink on the host. */
    private function assertLinkTarget(string $name, string $target): void
    {
        if ($target === '' || str_contains($target, "\0") || str_contains($target, ':')
            || str_contains($target, '\\') || str_starts_with($target, '/')) {
            throw new RuntimeException('proof_source_symlink_escape');
        }
        $parts = explode('/', $name);
        array_pop($parts);
        foreach (explode('/', $target) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') {
                if ($parts === []) { throw new RuntimeException('proof_source_symlink_escape'); }
                array_pop($parts);
            } else { $parts[] = $part; }
        }
    }

    /**
     * Intermediate source ZIP only; does not register or build a release artifact.
     * Optional paths are an exact tracked subset for component fixtures.
     */
    public function export(string $relativeZip, bool $authorized = false, ?array $paths = null): array
    {
        if (!$authorized) { throw new RuntimeException('proof_execution_not_authorized'); }
        $entries = $this->readEntries($paths);
        $path = $this->filesystem->assertContained($this->filesystem->config->path($relativeZip));
        if (file_exists($path) || is_link($path)) { throw new RuntimeException('proof_export_already_exists'); }
        $this->filesystem->directory(dirname($relativeZip));
        $mtime = (int) trim($this->git(['show', '-s', '--format=%ct', $this->filesystem->config->commitSha]));
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('proof_zip_invalid'); }
        try {
            foreach ($entries as $name => $entry) {
                if (!$zip->addFromString($name, $entry['bytes']) || !$zip->setMtimeName($name, $mtime)
                    || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, octdec($entry['mode']) << 16)) {
                    throw new RuntimeException('proof_zip_write_failed');
                }
            }
        } catch (\Throwable $error) {
            $zip->close(); // Preserve partial evidence; never clean up a failed export.
            throw $error;
        }
        if (!$zip->close()) { throw new RuntimeException('proof_zip_finalize_failed'); }
        $this->verifyZip($path, $entries);
        // Archive digest is computed ONLY after finalization and entry verification.
        $checksum = hash_file('sha256', $path);
        if ($checksum === false || !hash_equals($checksum, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('proof_checksum_mismatch');
        }
        $inventory = [];
        foreach ($entries as $name => $entry) { $inventory[$name] = array_diff_key($entry, ['bytes' => true]); }
        return ['path' => $path, 'commit_sha' => $this->filesystem->config->commitSha,
            'archive_sha256' => $checksum, 'archive_size' => filesize($path), 'blob_inventory' => $inventory];
    }

    private function verifyZip(string $path, array $entries): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) { throw new RuntimeException('proof_zip_invalid'); }
        try {
            if ($zip->numFiles !== count($entries)) { throw new RuntimeException('proof_source_inventory_mismatch'); }
            foreach ($entries as $name => $entry) {
                $stat = $zip->statName($name);
                $bytes = $zip->getFromName($name);
                $opsys = $attributes = 0;
                if ($stat === false || $bytes === false || $stat['size'] !== $entry['size']
                    || !hash_equals($entry['sha256'], hash('sha256', $bytes))
                    || !$zip->getExternalAttributesName($name, $opsys, $attributes)
                    || $opsys !== ZipArchive::OPSYS_UNIX || ($attributes >> 16 & 0177777) !== octdec($entry['mode'])) {
                    throw new RuntimeException('proof_source_zip_mismatch');
                }
            }
        } finally { $zip->close(); }
    }
}
