<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class SentinelProofRunConfig
{
    private function __construct(
        public readonly string $runId,
        public readonly string $root,
        public readonly string $sourceRepository,
        public readonly string $commitSha,
        public readonly string $branch,
        public readonly string $projectKey,
        public readonly int $hubPort,
        public readonly int $agentPort,
    ) {}

    public static function create(string $sourceRepository, string $commitSha, string $branch, int $hubPort, int $agentPort, string $projectKey = 'saas-platform'): self
    {
        $source = realpath($sourceRepository);
        $temp = realpath(sys_get_temp_dir());
        if ($source === false || $temp === false || !is_dir($source . '/.git')
            || !preg_match('/^[0-9a-f]{40}$/D', $commitSha)
            || !preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $branch)
            || str_contains($branch, '..')
            || !preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $projectKey)
            || $hubPort < 1024 || $hubPort > 65535 || $agentPort < 1024 || $agentPort > 65535
            || $hubPort === $agentPort || in_array($hubPort, [8000, 8080], true)
            || in_array($agentPort, [8000, 8080], true)) {
            throw new InvalidArgumentException('proof_configuration_rejected');
        }
        $runId = (string) Str::uuid();
        $root = str_replace('\\', '/', $temp) . '/SentinelProof/' . $runId;
        foreach ([$source, dirname(__DIR__, 5)] as $repository) {
            $repository = rtrim(str_replace('\\', '/', $repository), '/');
            if (strncasecmp($root, $repository . '/', strlen($repository) + 1) === 0) {
                throw new InvalidArgumentException('proof_root_inside_repository');
            }
        }
        return new self($runId, $root,
            $source, $commitSha, $branch, $projectKey, $hubPort, $agentPort);
    }

    public function path(string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, ':')
            || preg_match('~(^[\\\\/]|^[A-Za-z]:|(^|[\\\\/])\.\.?([\\\\/]|$))~', $relative)) {
            throw new InvalidArgumentException('proof_path_rejected');
        }
        return $this->root . '/' . str_replace('\\', '/', $relative);
    }

    public function databasePath(): string { return $this->path('hub/database.sqlite'); }
    public function metadata(): array
    {
        return ['run_id' => $this->runId, 'root' => $this->root,
            'source_repository' => $this->sourceRepository, 'commit_sha' => $this->commitSha,
            'branch' => $this->branch, 'project_key' => $this->projectKey,
            'hub_port' => $this->hubPort, 'agent_port' => $this->agentPort];
    }

    public static function restore(array $data): self
    {
        $temp = str_replace('\\', '/', (string) realpath(sys_get_temp_dir()));
        $id = $data['run_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[0-9a-f-]{36}$/D', $id)
            || ($data['root'] ?? '') !== $temp . '/SentinelProof/' . $id) {
            throw new InvalidArgumentException('proof_run_metadata_rejected');
        }
        // Reuse all source/port/schema validations; the new generated ID is never persisted.
        $validated = self::create($data['source_repository'] ?? '', $data['commit_sha'] ?? '',
            $data['branch'] ?? '', $data['hub_port'] ?? 0, $data['agent_port'] ?? 0, $data['project_key'] ?? '');
        return new self($id, $data['root'], $validated->sourceRepository, $validated->commitSha,
            $validated->branch, $validated->projectKey, $validated->hubPort, $validated->agentPort);
    }
    public function runtimeDatabasePath(): string { return $this->path('runtime/database.sqlite'); }
    public function storagePath(): string { return $this->path('hub/storage'); }
    public function cachePath(bool $hub = true): string { return $this->path(($hub ? 'hub' : 'runtime') . '/cache'); }
    public function sessionPath(bool $hub = true): string { return $this->path(($hub ? 'hub' : 'runtime') . '/session'); }
    public function logPath(bool $hub = true): string { return $this->path(($hub ? 'hub' : 'runtime') . '/logs'); }
    public function releaseRoot(): string { return $this->path('runtime/application'); }
    public function hubUrl(): string { return 'http://127.0.0.1:' . $this->hubPort; }
    public function runtimeUrl(): string { return 'http://127.0.0.1:' . $this->agentPort; }

    public function assertUrl(string $url, bool $runtime = true, string $path = '/health/release'): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'http'
            || ($parts['host'] ?? null) !== '127.0.0.1'
            || ($parts['port'] ?? null) !== ($runtime ? $this->agentPort : $this->hubPort)
            || ($parts['path'] ?? '') !== $path
            || isset($parts['user']) || isset($parts['pass'])
            || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException('proof_url_rejected');
        }
    }

    /** All writable state belongs to this run; existing environment is never inherited. */
    public function environment(bool $hub): array
    {
        $base = $hub ? 'hub' : 'runtime';
        return [
            'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $hub ? $this->databasePath() : $this->runtimeDatabasePath(),
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'null',
            'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'warning',
            'APP_CONFIG_CACHE' => $this->path($base . '/cache/config.php'),
            'APP_ROUTES_CACHE' => $this->path($base . '/cache/routes.php'),
            'APP_EVENTS_CACHE' => $this->path($base . '/cache/events.php'),
            'APP_SERVICES_CACHE' => $this->path($base . '/cache/services.php'),
            'APP_PACKAGES_CACHE' => $this->path($base . '/cache/packages.php'),
            'VIEW_COMPILED_PATH' => $this->path($base . '/cache/views'),
            'LARAVEL_STORAGE_PATH' => $this->path($base . '/storage'),
            'SENTINEL_PROOF_RUN_ID' => $this->runId,
        ];
    }
}
