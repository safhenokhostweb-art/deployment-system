<?php

declare(strict_types=1);

namespace LocalAgent;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * ArtifactFileManifest — a deterministic, canonicalized manifest of every
 * file packaged inside an artifact ZIP.
 *
 * Invariants:
 *  - Excludes the manifest file itself from its own inventory (no
 *    self-reference). This rule is explicit and non-negotiable.
 *  - Normalized relative paths: forward slashes, no leading/trailing
 *    slash, no dot segments, no traversal.
 *  - Deterministic ordering: paths sorted lexicographically (ASCII,
 *    case-sensitive).
 *  - Canonical JSON encoding: JSON_UNESCAPED_SLASHES |
 *    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, with recursive
 *    key sorting.
 *  - Stable numeric/string representations: sizes as integers, hashes
 *    as lowercase hex.
 *  - The manifest hash (SHA-256 of canonical JSON) binds the exact
 *    inventory to the artifact bytes.
 *
 * Verification-time use:
 *  - Derive the expected manifest from the validated artifact bytes
 *    (re-compute from the unpacked tree), OR prove any stored manifest
 *    is exactly bound to those bytes and the expected source commit.
 *  - Reject malformed archives, duplicate normalized paths, traversal
 *    paths, unsupported entry types, and manifest inconsistencies.
 *
 * The manifest hash is NOT a substitute for the artifact checksum.
 */
final class ArtifactFileManifest
{
    public const MANIFEST_FILENAME = '.deployment/file-manifest.json';
    public const FORMAT_VERSION = 1;
    public const FORMAT_IDENTIFIER = 'deployment-system-file-manifest';

    /** @var array<string, array{size: int, sha256: string}> */
    private array $entries;

    /** @var string SHA-256 of canonical JSON (without the 'manifest_hash' field) */
    private string $manifestHash;

    /**
     * @param array<string, array{size: int, sha256: string}> $entries
     */
    private function __construct(array $entries, string $manifestHash)
    {
        $this->entries = $entries;
        $this->manifestHash = $manifestHash;
    }

    /**
     * Build a manifest from a collected file map (relative path => absolute path).
     *
     * @param array<string, string> $files relative path => absolute file path
     * @param string $manifestFilename the manifest's own filename (relative) to exclude
     */
    public static function buildFromFiles(array $files, string $manifestFilename = self::MANIFEST_FILENAME): self
    {
        $normalized = [];
        $seen = [];

        foreach ($files as $relativePath => $absolutePath) {
            $normalizedPath = self::normalizePath($relativePath);
            if ($normalizedPath === null) {
                throw new RuntimeException("Invalid path in artifact: {$relativePath}");
            }

            // Exclude the manifest file itself from its own inventory
            if ($normalizedPath === $manifestFilename) {
                continue;
            }

            // Reject duplicate normalized paths
            if (isset($seen[$normalizedPath])) {
                throw new RuntimeException("Duplicate normalized path in artifact: {$normalizedPath}");
            }
            $seen[$normalizedPath] = true;

            if (!is_file($absolutePath) || !is_readable($absolutePath)) {
                throw new RuntimeException("File not readable or not a regular file: {$absolutePath}");
            }

            $size = @filesize($absolutePath);
            if ($size === false || $size < 0) {
                throw new RuntimeException("Could not determine file size: {$absolutePath}");
            }

            $hash = @hash_file('sha256', $absolutePath);
            if ($hash === false) {
                throw new RuntimeException("Could not compute SHA-256: {$absolutePath}");
            }

            $normalized[$normalizedPath] = ['size' => $size, 'sha256' => strtolower($hash)];
        }

        // Deterministic ordering: sort by path (ASCII, case-sensitive)
        ksort($normalized, SORT_STRING);

        // Compute manifest hash from canonical JSON (without manifest_hash field)
        $canonical = self::canonicalizeManifest($normalized);
        $manifestHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return new self($normalized, $manifestHash);
    }

    /**
     * Create a manifest from a canonical manifest array (e.g., read from artifact).
     *
     * @param array<string, array{size: int, sha256: string}> $entries
     * @param string $providedHash the manifest_hash field from the stored manifest
     */
    public static function fromCanonical(array $entries, string $providedHash): self
    {
        // Validate entries
        foreach ($entries as $path => $entry) {
            if (!self::isValidNormalizedPath($path)) {
                throw new InvalidArgumentException("Invalid normalized path in manifest: {$path}");
            }
            if (!is_array($entry) || !isset($entry['size'], $entry['sha256'])) {
                throw new InvalidArgumentException("Invalid entry structure for path: {$path}");
            }
            $size = $entry['size'];
            $sha256 = $entry['sha256'];
            if (!is_int($size) || $size < 0) {
                throw new InvalidArgumentException("Invalid size for path: {$path}");
            }
            if (!is_string($sha256) || !preg_match('/^[0-9a-f]{64}$/D', $sha256)) {
                throw new InvalidArgumentException("Invalid SHA-256 for path: {$path}");
            }
        }

        // Verify the provided hash matches recomputed canonical hash
        $canonical = self::canonicalizeManifest($entries);
        $recomputedHash = hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (!hash_equals($providedHash, $recomputedHash)) {
            throw new InvalidArgumentException('Manifest hash does not match canonical manifest content');
        }

        return new self($entries, $providedHash);
    }

    /**
     * Create a manifest from a JSON string (as stored in the artifact).
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('File manifest is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidArgumentException('File manifest must be a JSON object');
        }

        $format = $decoded['format'] ?? null;
        if ($format !== self::FORMAT_IDENTIFIER) {
            throw new InvalidArgumentException("Unsupported manifest format: {$format}");
        }

        $version = $decoded['version'] ?? null;
        if (!is_int($version) || $version !== self::FORMAT_VERSION) {
            throw new InvalidArgumentException("Unsupported manifest version: {$version}");
        }

        $entries = $decoded['entries'] ?? [];
        if (!is_array($entries)) {
            throw new InvalidArgumentException('Manifest entries must be an object');
        }

        $manifestHash = $decoded['manifest_hash'] ?? null;
        if (!is_string($manifestHash) || !preg_match('/^[0-9a-f]{64}$/D', $manifestHash)) {
            throw new InvalidArgumentException('Manifest hash is missing or malformed');
        }

        return self::fromCanonical($entries, $manifestHash);
    }

    /**
     * Get the manifest entries.
     *
     * @return array<string, array{size: int, sha256: string}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function manifestHash(): string
    {
        return $this->manifestHash;
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }

    public function totalSize(): int
    {
        return array_sum(array_column($this->entries, 'size'));
    }

    /**
     * Serialize to the canonical JSON format for storage in the artifact.
     */
    public function toJson(): string
    {
        $canonical = self::canonicalizeManifest($this->entries);
        $canonical['manifest_hash'] = $this->manifestHash;

        return json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Normalize a relative path for manifest purposes.
     * Returns null if the path is invalid (traversal, absolute, etc.).
     */
    private static function normalizePath(string $path): ?string
    {
        // Reject absolute paths and traversal
        if (str_starts_with($path, '/') || str_contains($path, '..') || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return null;
        }

        // Normalize to forward slashes
        $normalized = str_replace('\\', '/', $path);

        // Remove leading ./ if present
        $normalized = ltrim($normalized, './');

        // Reject empty or root
        if ($normalized === '' || $normalized === '.') {
            return null;
        }

        // Reject if still contains traversal after normalization
        if (str_contains($normalized, '..')) {
            return null;
        }

        return $normalized;
    }

    private static function isValidNormalizedPath(string $path): bool
    {
        return self::normalizePath($path) === $path;
    }

    /**
     * Canonicalize manifest entries for deterministic hashing.
     * Recursively sorts keys so equal manifests always produce byte-identical JSON.
     *
     * @param array<string, array{size: int, sha256: string}> $entries
     * @return array<string, mixed>
     */
    private static function canonicalizeManifest(array $entries): array
    {
        $canonical = [
            'format' => self::FORMAT_IDENTIFIER,
            'version' => self::FORMAT_VERSION,
            'entries' => [],
        ];

        foreach ($entries as $path => $entry) {
            $canonical['entries'][$path] = [
                'size' => $entry['size'],
                'sha256' => $entry['sha256'],
            ];
        }

        // Recursively sort all associative keys
        return self::recursiveKsort($canonical);
    }

    /**
     * Recursively sort associative array keys (preserve list order).
     *
     * @param array<string|int, mixed> $value
     * @return array<string|int, mixed>
     */
    private static function recursiveKsort(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                static fn ($item) => is_array($item) ? self::recursiveKsort($item) : $item,
                $value
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::recursiveKsort($child);
            }
        }

        return $value;
    }
}