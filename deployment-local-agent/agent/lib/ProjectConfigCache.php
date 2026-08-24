<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Persists the Hub's project-config sync response
 * (project_key => {source_workspace_path, repository_url, remote_name,
 * branch}) to a local JSON file
 * so the agent can keep publishing while the Hub is temporarily
 * unreachable. Deliberately holds only project metadata — never
 * agent_token/signing_secret/registration_token, which live solely in
 * config.php and are never passed through this class.
 *
 * Writes are atomic: the new content is written to a sibling temp file
 * first, then renamed into place, so a crash or power loss mid-write can
 * never leave a half-written/corrupt cache behind — the previous good
 * cache (or no file at all) is what a reader would see instead.
 */
final class ProjectConfigCache
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Builds the project map from the Hub API's response shape (a JSON
     * list of {project_key, source_workspace_path, repository_url,
     * remote_name, branch} objects) and
     * validates it before returning. Throws on the first structurally
     * invalid entry — a partially-trusted config is worse than none.
     *
     * @param array<int, mixed> $rawProjects
     * @return array<string, array{local_path: string, remote_name: string, branch: string}>
     */
    public static function fromApiResponse(array $rawProjects): array
    {
        $projects = [];

        foreach ($rawProjects as $entry) {
            if (!is_array($entry) || !isset($entry['project_key'])) {
                throw new \InvalidArgumentException('a project entry is missing project_key');
            }

            $key = $entry['project_key'];
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('a project entry has an empty or non-string project_key');
            }
            if (!is_string($entry['repository_url'] ?? null) || trim($entry['repository_url']) === '') {
                throw new \InvalidArgumentException("project '{$key}' is missing repository_url required for source workspace bootstrap");
            }

            $projects[$key] = [
                'source_workspace_path' => $entry['source_workspace_path'] ?? $entry['local_path'] ?? null,
                'remote_name' => $entry['remote_name'] ?? 'origin',
                'branch' => $entry['branch'] ?? null,
                'application_root' => $entry['application_root'] ?? '.',
                'repository_url' => $entry['repository_url'] ?? null,
            ];
        }

        self::assertValid($projects);

        return $projects;
    }

    /**
     * Structural validation only (non-empty strings) — this class never
     * checks whether local_path exists on disk, since that's a
     * per-machine fact the Hub cannot know and the agent must re-check at
     * use time regardless of where the config came from (see agent.php's
     * own is_dir() guard before running git commands).
     *
     * @param array<string, mixed> $projects
     */
    public static function assertValid(array $projects): void
    {
        foreach ($projects as $key => $entry) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('empty project_key in project configuration');
            }

            if (
                !is_array($entry)
                || !is_string($entry['source_workspace_path'] ?? $entry['local_path'] ?? null) || ($entry['source_workspace_path'] ?? $entry['local_path'] ?? '') === ''
                || !is_string($entry['remote_name'] ?? null) || $entry['remote_name'] === ''
                || !is_string($entry['branch'] ?? null) || $entry['branch'] === ''
                || !is_string($entry['application_root'] ?? '.')
                || (isset($entry['repository_url']) && (!is_string($entry['repository_url']) || $entry['repository_url'] === ''))
            ) {
                throw new \InvalidArgumentException("invalid configuration for project '{$key}' (source_workspace_path/remote_name/branch must be non-empty strings)");
            }
        }
    }

    /**
     * @param array<string, array{local_path: string, remote_name: string, branch: string}> $projects
     */
    public function save(array $projects): void
    {
        self::assertValid($projects);

        $payload = [
            'version' => 2,
            'synced_at' => gmdate('c'),
            'projects' => $projects,
        ];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create cache directory {$dir}");
        }

        // Write-to-temp-then-rename: rename() is atomic on both POSIX and
        // NTFS (same volume, which a sibling file always is), so any
        // reader only ever sees the fully-old or fully-new file, never a
        // half-written one.
        $tmpPath = $this->path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write temporary cache file {$tmpPath}");
        }
        @chmod($tmpPath, 0600);

        if (!rename($tmpPath, $this->path)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Could not atomically replace cache file at {$this->path}");
        }
    }

    /**
     * Returns null only when no cache file exists yet (a normal first-run
     * state, not an error). Throws InvalidProjectCacheException when a
     * cache file exists but is unreadable/corrupt/invalid — callers must
     * treat that as a distinct, loggable condition rather than silently
     * treating it the same as "no cache yet".
     *
     * @return array<string, array{local_path: string, remote_name: string, branch: string}>|null
     */
    public function load(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }

        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') {
            throw new InvalidProjectCacheException("cache file at {$this->path} is empty or unreadable");
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidProjectCacheException("cache file at {$this->path} is not valid JSON: {$e->getMessage()}");
        }

        if (!is_array($decoded) || !is_array($decoded['projects'] ?? null)) {
            throw new InvalidProjectCacheException("cache file at {$this->path} is missing a valid 'projects' object");
        }

        try {
            self::assertValid($decoded['projects']);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidProjectCacheException("cache file at {$this->path} failed validation: {$e->getMessage()}");
        }

        return $decoded['projects'];
    }
}
