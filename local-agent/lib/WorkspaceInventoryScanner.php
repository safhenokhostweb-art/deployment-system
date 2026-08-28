<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Phase 1 of the Workspace Inventory feature: a strictly read-only Git
 * facts scanner for one already-synchronized/approved Local Project
 * Mapping. Reports what Git actually sees — never who or what produced
 * it. `ownership` on every returned file is hardcoded to "unknown"; nothing
 * in this class infers AI vs. human vs. IDE/script authorship, and no
 * caller should treat it as ready for that until a later phase adds real
 * task-ownership metadata.
 *
 * Only ever runs `git status` and `git rev-parse` (both read-only
 * inspection commands, the same family GitManager's own docblock commits
 * to never mutating the working tree) — never `git add`/`commit`/
 * `push`/`fetch`/`checkout`/`stash`/`reset`/`merge`/`rebase`/`clean`. It
 * never fetches, so ahead/behind is only ever computed against whatever
 * remote-tracking ref this machine already has locally — see
 * GitStatusPorcelainV2Parser and scan()'s own comments.
 *
 * Phase 2 integration note: this class currently runs its own `git
 * status` invocation independently of LocalProjectStateReporter's
 * heartbeat summary and agent.php's change-detection `changedFiles()`
 * calls — three separate scans of the same working tree per poll cycle
 * once all three are wired together. A later phase should have the
 * poll loop run ONE `git status --porcelain=v2 -z --branch --ahead-behind
 * --untracked-files=all` per project per cycle, feed its raw output
 * through GitStatusPorcelainV2Parser once, and derive the heartbeat
 * summary, the change-detection file list, and this inventory from that
 * single parsed result — rather than refactoring that sharing in
 * automatically here, which is out of this phase's scope.
 */
final class WorkspaceInventoryScanner
{
    private const SCHEMA_VERSION = 1;
    private const DEFAULT_MAX_RETURNED_FILES = 500;
    private const HARD_MAX_RETURNED_FILES = 2000;
    private const MAX_PATH_LENGTH = 1000;

    private readonly int $maxReturnedFiles;

    public function __construct(
        private readonly GitManager $git,
        int $maxReturnedFiles = self::DEFAULT_MAX_RETURNED_FILES,
    ) {
        $this->maxReturnedFiles = max(1, min(self::HARD_MAX_RETURNED_FILES, $maxReturnedFiles));
    }

    /**
     * @param string $mappedRoot the absolute Local Project Mapping path —
     *     must already exist and already BE the Git repository's own
     *     top-level directory; this method never searches for a
     *     repository above or below it.
     * @return array<string, mixed> the schema described in this class's
     *     docblock and the Phase 1 spec — see buildInventory()/
     *     degradedInventory() for its exact shape.
     * @throws \InvalidArgumentException|\RuntimeException on a mapping/
     *     policy violation (not a valid absolute path, filesystem root,
     *     production-like path, nonexistent, a symlink/Junction root, or
     *     not the Git top-level) — these represent a bad mapping, not a
     *     transient scan failure, so they are never softened into a
     *     scan_error-carrying result.
     */
    public function scan(string $mappedRoot): array
    {
        $canonicalRoot = $this->assertSafeRoot($mappedRoot);

        $timeoutSeconds = $this->git->timeoutFor('status');
        $result = $this->git->run(
            ['git', 'status', '--porcelain=v2', '-z', '--branch', '--ahead-behind', '--untracked-files=all'],
            $canonicalRoot,
            $timeoutSeconds,
        );

        if ($result['timedOut']) {
            return $this->degradedInventory("git status timed out after {$timeoutSeconds}s.");
        }
        if ($result['exitCode'] !== 0) {
            return $this->degradedInventory('git status failed: ' . trim($result['output']));
        }

        $output = $result['output'];
        $byteCapped = $this->stripOutputCapMarker($output);

        $parsed = GitStatusPorcelainV2Parser::parse($output);
        $inventory = $this->buildInventory($parsed, $canonicalRoot);

        if ($byteCapped) {
            // GitManager's own output cap (MAX_OUTPUT_BYTES) cut the raw
            // `git status` output short — the last record may be
            // incomplete and any entries beyond the cut point are simply
            // missing, so the counts built from it can no longer be
            // guaranteed exact. Never let this be mistaken for the
            // ordinary "more files than the return limit" truncation,
            // which still has exact counts (see buildInventory()).
            $inventory['is_truncated'] = true;
            $inventory['is_clean'] = false;
            $inventory['scan_error'] = 'git status output exceeded the command output cap; scan is incomplete and must not be treated as authoritative.';
        }

        return $inventory;
    }

    /**
     * @param array{branch: array{oid: ?string, head: ?string, detached: bool, upstream: ?string, ahead: ?int, behind: ?int}, entries: array<int, array<string, mixed>>} $parsed
     */
    private function buildInventory(array $parsed, string $canonicalRoot): array
    {
        $branchInfo = $parsed['branch'];
        $entries = $parsed['entries'];

        // Deterministic order so which entries land in files[] (once the
        // return limit is applied) and the snapshot_hash input are both
        // stable for identical underlying state, regardless of the order
        // `git status` itself happened to emit them in.
        usort($entries, static function (array $a, array $b): int {
            return $a['path'] <=> $b['path'] ?: ($a['old_path'] ?? '') <=> ($b['old_path'] ?? '');
        });

        $upstreamRef = $branchInfo['upstream'];
        $upstreamSha = $upstreamRef !== null ? $this->resolveUpstreamSha($upstreamRef, $canonicalRoot) : null;

        $comparisonBasis = 'unavailable';
        $aheadCount = null;
        $behindCount = null;
        if ($upstreamRef !== null && $branchInfo['ahead'] !== null && $branchInfo['behind'] !== null) {
            $comparisonBasis = 'upstream_tracking';
            $aheadCount = $branchInfo['ahead'];
            $behindCount = $branchInfo['behind'];
        }

        $totalChangedCount = count($entries);
        $trackedModifiedCount = 0;
        $untrackedCount = 0;
        $stagedCount = 0;
        $deletedCount = 0;
        $renamedCount = 0;
        $conflictedCount = 0;
        foreach ($entries as $entry) {
            if ($entry['tracked']) {
                $trackedModifiedCount++;
            } else {
                $untrackedCount++;
            }
            if ($entry['staged']) {
                $stagedCount++;
            }
            if ($entry['status'] === 'deleted') {
                $deletedCount++;
            }
            if ($entry['status'] === 'renamed') {
                $renamedCount++;
            }
            if ($entry['status'] === 'conflicted') {
                $conflictedCount++;
            }
        }

        $omittedByPathLength = 0;
        $eligibleForReturn = [];
        foreach ($entries as $entry) {
            if (strlen($entry['path']) > self::MAX_PATH_LENGTH || ($entry['old_path'] !== null && strlen($entry['old_path']) > self::MAX_PATH_LENGTH)) {
                $omittedByPathLength++;
                continue;
            }
            $eligibleForReturn[] = $entry;
        }

        $omittedByLimit = max(0, count($eligibleForReturn) - $this->maxReturnedFiles);
        $returnedEntries = array_slice($eligibleForReturn, 0, $this->maxReturnedFiles);

        $files = array_map(static function (array $entry): array {
            $entry['ownership'] = 'unknown';

            return $entry;
        }, $returnedEntries);

        $omittedFileCount = $omittedByPathLength + $omittedByLimit;
        $isTruncated = $omittedFileCount > 0;

        $branchName = $branchInfo['detached'] ? null : $branchInfo['head'];
        $headSha = $branchInfo['oid'];

        $isClean = $totalChangedCount === 0 && $conflictedCount === 0;

        $inventory = [
            'schema_version' => self::SCHEMA_VERSION,
            'branch' => $branchName,
            'head_sha' => $headSha,
            'upstream_ref' => $upstreamRef,
            'upstream_sha' => $upstreamSha,
            'comparison_basis' => $comparisonBasis,
            'ahead_count' => $aheadCount,
            'behind_count' => $behindCount,
            'tracked_modified_count' => $trackedModifiedCount,
            'untracked_count' => $untrackedCount,
            'staged_count' => $stagedCount,
            'deleted_count' => $deletedCount,
            'renamed_count' => $renamedCount,
            'conflicted_count' => $conflictedCount,
            'total_changed_count' => $totalChangedCount,
            'is_clean' => $isClean,
            'is_truncated' => $isTruncated,
            'omitted_file_count' => $omittedFileCount,
            'scan_error' => null,
            'files' => $files,
        ];

        $inventory['snapshot_hash'] = $this->computeSnapshotHash($inventory, $entries);

        return $inventory;
    }

    /**
     * A scan that could not be trusted at all (git status itself failed
     * or timed out) — every fact is unknown, never guessed at or defaulted
     * to a "clean"/zero state that could be mistaken for a real one.
     */
    private function degradedInventory(string $scanError): array
    {
        $inventory = [
            'schema_version' => self::SCHEMA_VERSION,
            'branch' => null,
            'head_sha' => null,
            'upstream_ref' => null,
            'upstream_sha' => null,
            'comparison_basis' => 'unavailable',
            'ahead_count' => null,
            'behind_count' => null,
            'tracked_modified_count' => 0,
            'untracked_count' => 0,
            'staged_count' => 0,
            'deleted_count' => 0,
            'renamed_count' => 0,
            'conflicted_count' => 0,
            'total_changed_count' => 0,
            'is_clean' => false,
            'is_truncated' => true,
            'omitted_file_count' => 0,
            'scan_error' => $scanError,
            'files' => [],
        ];

        $inventory['snapshot_hash'] = $this->computeSnapshotHash($inventory, []);

        return $inventory;
    }

    /**
     * Deterministic SHA-256 over normalized, repo-intrinsic facts only —
     * deliberately excludes is_truncated/omitted_file_count/scan_error,
     * which reflect this scan's own configuration (max returned files) or
     * a transient failure rather than actual workspace state, and would
     * otherwise make the hash flap independently of anything changing in
     * the repository. A caller doing change detection is expected to
     * check scan_error before trusting snapshot_hash, exactly as it must
     * for every other field here.
     *
     * @param array<int, array<string, mixed>> $sortedEntries the full
     *     (pre-truncation) entry list, already sorted by buildInventory() —
     *     hashing the full set (not the possibly-truncated files[]) means
     *     the hash still changes correctly even when the visible file
     *     list is capped.
     */
    private function computeSnapshotHash(array $inventory, array $sortedEntries): string
    {
        $entryTuples = array_map(static function (array $entry): array {
            return [
                $entry['path'],
                $entry['old_path'],
                $entry['status'],
                $entry['index_status'],
                $entry['worktree_status'],
                $entry['staged'],
                $entry['tracked'],
            ];
        }, $sortedEntries);

        $hashInput = [
            'schema_version' => $inventory['schema_version'],
            'branch' => $inventory['branch'],
            'head_sha' => $inventory['head_sha'],
            'upstream_ref' => $inventory['upstream_ref'],
            'upstream_sha' => $inventory['upstream_sha'],
            'comparison_basis' => $inventory['comparison_basis'],
            'ahead_count' => $inventory['ahead_count'],
            'behind_count' => $inventory['behind_count'],
            'entries' => $entryTuples,
        ];

        return hash('sha256', (string) json_encode($hashInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * `git rev-parse --verify --quiet <upstream>` — read-only, resolves
     * only whatever this machine already has locally; never fetches. A
     * configured-but-unresolvable upstream (e.g. its remote-tracking ref
     * was deleted locally) yields null, not an error — ahead/behind
     * already independently reflects "unavailable" via comparisonBasis in
     * that case.
     */
    private function resolveUpstreamSha(string $upstreamRef, string $canonicalRoot): ?string
    {
        $result = $this->git->run(
            ['git', 'rev-parse', '--verify', '--quiet', $upstreamRef],
            $canonicalRoot,
            $this->git->timeoutFor('status'),
        );

        if ($result['exitCode'] !== 0) {
            return null;
        }

        $sha = trim($result['output']);

        return preg_match('/^[0-9a-f]{40}$/', $sha) === 1 ? $sha : null;
    }

    /**
     * GitManager::run() appends a plain-text marker (not NUL-delimited)
     * once accumulated output exceeds its own MAX_OUTPUT_BYTES cap — see
     * its docblock. Strips it out in place (by reference) so the parser
     * never sees it as part of the last record, and reports whether it
     * was present.
     */
    private function stripOutputCapMarker(string &$output): bool
    {
        $marker = "\n[local-agent] output truncated at ";
        $pos = strpos($output, $marker);
        if ($pos === false) {
            return false;
        }

        $output = substr($output, 0, $pos);

        return true;
    }

    /**
     * Validates $mappedRoot against every Phase 1 safety requirement and
     * returns its canonical realpath for the actual `git` invocations to
     * run against. Never searches for a repository — the mapped root must
     * already BE the Git top-level, checked directly against
     * `git rev-parse --show-toplevel`, or this throws.
     */
    private function assertSafeRoot(string $mappedRoot): string
    {
        $trimmed = trim($mappedRoot);
        if ($trimmed === '' || !$this->isAbsolutePath($trimmed)) {
            throw new \InvalidArgumentException('Workspace inventory root must be a non-empty absolute path.');
        }

        if ($this->isFilesystemRoot($trimmed)) {
            throw new \RuntimeException("Refusing to scan filesystem root '{$mappedRoot}'.");
        }

        if (SafetyChecker::productionSourcePathForbidden($trimmed)) {
            throw new \RuntimeException("Refusing to scan production-like path '{$mappedRoot}'.");
        }

        $real = realpath($trimmed);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("Workspace inventory root '{$mappedRoot}' does not exist or is not accessible.");
        }

        if (SafeFilesystem::isLinkLikeEntry($trimmed)) {
            throw new \RuntimeException("Refusing to scan workspace root '{$mappedRoot}': it is a symlink/junction, not a real directory.");
        }

        $toplevel = $this->git->run(['git', 'rev-parse', '--show-toplevel'], $real, $this->git->timeoutFor('status'));
        if ($toplevel['exitCode'] !== 0) {
            throw new \RuntimeException("'{$mappedRoot}' is not inside a Git working tree: " . trim($toplevel['output']));
        }

        $gitTop = realpath(trim($toplevel['output']));
        if ($gitTop === false || $this->comparablePath($gitTop) !== $this->comparablePath($real)) {
            throw new \RuntimeException("Refusing to scan '{$mappedRoot}': its Git top-level does not match the mapped workspace root (mapping points into a repository subdirectory, or the wrong repository).");
        }

        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function isFilesystemRoot(string $path): bool
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return $normalized === '' || preg_match('/^[A-Za-z]:$/', $normalized) === 1;
    }

    private function comparablePath(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
    }
}
