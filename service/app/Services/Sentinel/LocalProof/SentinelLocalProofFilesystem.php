<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use RuntimeException;

final class SentinelLocalProofFilesystem
{
    public function __construct(public readonly SentinelProofRunConfig $config) {}

    public function assertContained(string $path, bool $allowLink = false): string
    {
        $root = str_replace('\\', '/', $this->config->root);
        $candidate = str_replace('\\', '/', $path);
        if (str_contains($candidate, "\0") || preg_match('~(^|/)\.\.?(/|$)~', $candidate)
            || str_contains(substr($candidate, strlen($root) + 1), ':')
            || strncasecmp($candidate, $root . '/', strlen($root) + 1) !== 0) {
            throw new RuntimeException('proof_path_escape');
        }
        // Check every existing ancestor: this also catches Windows junction escapes.
        $cursor = $candidate;
        while (!file_exists($cursor) && !is_link($cursor)) {
            $parent = dirname($cursor);
            if ($parent === $cursor) { throw new RuntimeException('proof_path_unresolved'); }
            $cursor = $parent;
        }
        $resolved = realpath($cursor);
        if ($resolved === false) { throw new RuntimeException('proof_path_unresolved'); }
        $resolved = str_replace('\\', '/', $resolved);
        if (strlen($cursor) >= strlen($root)) { $this->assertResolved($resolved); }
        for ($probe = $candidate; strlen($probe) >= strlen($root); $probe = dirname($probe)) {
            if (is_link($probe) && (!$allowLink || $probe !== $candidate)) {
                throw new RuntimeException('proof_symlink_rejected');
            }
        }
        // A pre-existing SentinelProof parent may not redirect the run outside TEMP.
        $parent = dirname($root);
        if (file_exists($parent) && strcasecmp(str_replace('\\', '/', (string) realpath($parent)), $parent) !== 0) {
            throw new RuntimeException('proof_parent_redirected');
        }
        return $candidate;
    }

    /** Shared canonical-target check, also independently testable without OS symlink privileges. */
    public function assertResolved(string $resolved): void
    {
        $root = str_replace('\\', '/', $this->config->root);
        $resolved = str_replace('\\', '/', $resolved);
        if (strcasecmp($resolved, $root) !== 0
            && strncasecmp($resolved, $root . '/', strlen($root) + 1) !== 0) {
            throw new RuntimeException('proof_symlink_escape');
        }
    }

    public function initialize(bool $authorized = false): void
    {
        if (!$authorized) { throw new RuntimeException('proof_execution_not_authorized'); }
        if (file_exists($this->config->root) || is_link($this->config->root)) {
            throw new RuntimeException('proof_run_already_exists');
        }
        $this->assertContained($this->config->path('run.json'));
        if (!mkdir($this->config->root, 0700, true)) { throw new RuntimeException('proof_directory_failed'); }
        $this->write('run.json', json_encode($this->config->metadata(), JSON_THROW_ON_ERROR));
        foreach (['hub/storage', 'hub/cache/views', 'hub/session', 'hub/logs',
            'runtime/storage', 'runtime/cache/views', 'runtime/session', 'runtime/logs',
            'artifacts', 'runtime/application/releases'] as $relative) {
            $this->directory($relative);
        }
    }

    public function directory(string $relative): string
    {
        $path = $this->assertContained($this->config->path($relative));
        if (!is_dir($path) && !mkdir($path, 0700, true)) { throw new RuntimeException('proof_directory_failed'); }
        return $path;
    }

    public function write(string $relative, string $bytes): string
    {
        $path = $this->assertContained($this->config->path($relative));
        if (dirname($relative) !== '.') { $this->directory(dirname($relative)); }
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('proof_write_failed');
        }
        return $path;
    }

    public function cleanup(bool $authorized = false): void
    {
        if (!$authorized) { throw new RuntimeException('proof_cleanup_not_authorized'); }
        $marker = $this->assertContained($this->config->path('run.json'));
        $data = json_decode((string) file_get_contents($marker), true);
        if (($data['run_id'] ?? null) !== $this->config->runId) { throw new RuntimeException('proof_cleanup_owner_mismatch'); }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->root,
            \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        // Preflight everything before deleting anything.
        $entries = [];
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $this->assertContained($path, true);
            $entries[] = [$path, $item->isDir() && !$item->isLink()];
        }
        foreach ($entries as [$path, $directory]) {
            if (!($directory ? rmdir($path) : unlink($path))) { throw new RuntimeException('proof_cleanup_failed'); }
        }
        if (!rmdir($this->config->root)) { throw new RuntimeException('proof_cleanup_failed'); }
    }
}
