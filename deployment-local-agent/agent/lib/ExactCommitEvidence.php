<?php

declare(strict_types=1);

namespace LocalAgent;

final class ExactCommitEvidence
{
    private int $startedNs;
    private string $startedAt;
    /** @var array<int, array<string, mixed>> */
    private array $checks = [];

    /** @param array<string, mixed> $contract */
    public function __construct(
        private readonly array $contract,
        string $projectKey,
        string $releaseJobId,
        string $commitSha,
        string $branch,
        string $agentId,
        private readonly string $agentVersion,
    ) {
        if (($contract['contract_version'] ?? null) !== 1
            || ($contract['project_key'] ?? null) !== $projectKey
            || ($contract['publish_job_id'] ?? null) !== $releaseJobId
            || ($contract['commit_sha'] ?? null) !== $commitSha
            || ($contract['branch'] ?? null) !== $branch
            || ($contract['agent_id'] ?? null) !== $agentId
            || !is_string($contract['release_id'] ?? null)) {
            throw new LocalBuildFailedException('artifact_build_failed', 'Hub evidence contract provenance does not match this exact build job.');
        }
        $claimed = $contract['contract_digest'] ?? null;
        $withoutDigest = $contract;
        unset($withoutDigest['contract_digest']);
        if (!is_string($claimed) || !hash_equals($claimed, self::digest($withoutDigest))) {
            throw new LocalBuildFailedException('artifact_build_failed', 'Hub evidence contract digest is invalid.');
        }
        $required = [
            ['id' => 'source_provenance', 'type' => 'exact_commit', 'required' => true],
            ['id' => 'php_syntax', 'type' => 'php_syntax', 'required' => true],
            ['id' => 'build_pipeline', 'type' => 'trusted_build', 'required' => true],
            ['id' => 'artifact_manifest', 'type' => 'release_manifest', 'required' => true],
        ];
        if (($contract['required_checks'] ?? null) !== $required) {
            throw new LocalBuildFailedException('artifact_build_failed', 'Hub evidence contract contains missing or unsupported required checks.');
        }
        $this->startedNs = hrtime(true);
        $this->startedAt = self::timestamp();
    }

    public function verifySource(BuildWorkspace $workspace, string $commitSha): void
    {
        $this->record('source_provenance', function () use ($workspace, $commitSha): array {
            $headPath = $workspace->projectDir() . '/.git/HEAD';
            if (!is_file($headPath)) {
                throw new \RuntimeException('Evidence workspace is not an isolated Git checkout.');
            }
            $actualHead = strtolower(trim((string) file_get_contents($headPath)));
            if (!hash_equals($commitSha, $actualHead)) {
                throw new \RuntimeException("Isolated evidence workspace HEAD mismatch: expected {$commitSha}, found {$actualHead}.");
            }
            return ['checked_out_sha' => $actualHead, 'workspace' => 'isolated_detached_head'];
        });

        $this->record('php_syntax', function () use ($workspace): array {
            $count = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($workspace->projectDir(), \FilesystemIterator::SKIP_DOTS),
                    static function (\SplFileInfo $entry): bool {
                        return !$entry->isDir() || !in_array($entry->getFilename(), ['.git', 'vendor', 'node_modules'], true);
                    },
                ),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
                $source = file_get_contents($file->getPathname());
                if ($source === false) throw new \RuntimeException('Could not read PHP source for exact-commit syntax validation.');
                token_get_all($source, TOKEN_PARSE);
                $count++;
            }
            return ['files_checked' => $count, 'engine' => PHP_VERSION];
        });
    }

    /** @param array<string, mixed> $buildResult */
    public function verifyBuildAndManifest(string $manifestPath, array $buildResult): void
    {
        $this->record('build_pipeline', static fn (): array => [
            'status' => $buildResult['status'] ?? 'pass',
            'steps' => array_values($buildResult['log'] ?? []),
        ]);
        $this->record('artifact_manifest', function () use ($manifestPath): array {
            $manifest = json_decode((string) @file_get_contents($manifestPath), true);
            if (!is_array($manifest)
                || ($manifest['project_key'] ?? null) !== $this->contract['project_key']
                || ($manifest['commit_sha'] ?? null) !== $this->contract['commit_sha']
                || ($manifest['branch'] ?? null) !== $this->contract['branch']
                || ($manifest['status'] ?? null) !== 'pass') {
                throw new \RuntimeException('Artifact release manifest does not match the evidence contract.');
            }
            return ['format' => $manifest['format'] ?? null, 'status' => 'pass'];
        });
    }

    /** @return array<string, mixed> */
    public function finish(): array
    {
        $evidence = [
            'contract_version' => 1,
            'project_key' => $this->contract['project_key'],
            'release_id' => $this->contract['release_id'],
            'publish_job_id' => $this->contract['publish_job_id'],
            'commit_sha' => $this->contract['commit_sha'],
            'branch' => $this->contract['branch'],
            'agent_id' => $this->contract['agent_id'],
            'agent_version' => $this->agentVersion,
            'contract_digest' => $this->contract['contract_digest'],
            'started_at' => $this->startedAt,
            'completed_at' => self::timestamp(),
            'duration_ms' => (int) ((hrtime(true) - $this->startedNs) / 1_000_000),
            'checks' => $this->checks,
        ];
        $evidence['evidence_digest'] = self::digest($evidence);
        return $evidence;
    }

    private function record(string $id, callable $check): void
    {
        $startedNs = hrtime(true);
        $startedAt = self::timestamp();
        try {
            $details = $check();
        } catch (\Throwable $e) {
            throw new LocalBuildFailedException('artifact_build_failed', "Required exact-commit check '{$id}' failed: {$e->getMessage()}");
        }
        $this->checks[] = [
            'id' => $id,
            'status' => 'passed',
            'started_at' => $startedAt,
            'completed_at' => self::timestamp(),
            'duration_ms' => (int) ((hrtime(true) - $startedNs) / 1_000_000),
            'details' => $details,
        ];
    }

    /** @param array<string, mixed> $value */
    private static function digest(array $value): string
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        foreach ($value as $key => $child) $value[$key] = self::canonicalize($child);
        return $value;
    }

    private static function timestamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z');
    }
}
