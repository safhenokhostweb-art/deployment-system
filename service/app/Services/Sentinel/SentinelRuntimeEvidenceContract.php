<?php

namespace App\Services\Sentinel;

use InvalidArgumentException;

/**
 * sentinel.runtime.v1 — the Agent-signed Runtime Evidence payload.
 *
 * This contract defines the schema for the runtime snapshot that the
 * Server Agent (agent/) and Local Agent (local-agent/) include in their
 * signed heartbeat payloads. The Hub validates this payload through
 * AgentRuntimeEvidenceReader before it enters the Sentinel evidence chain.
 *
 * Rules enforced here (fail-closed at construction time):
 *
 *  - Expected and observed values are separate fields.
 *  - Only whitelisted fields exist. Any unknown field name is rejected.
 *  - Every string value is scanned for secret-shaped content; the
 *    document refuses to build if one is found.
 *  - Path values are validated for containment within the release root.
 *  - Nothing is ever mutated after construction.
 *  - No secret, token, cookie, credential, or .env content is ever stored.
 */
final class SentinelRuntimeEvidenceContract
{
    public const VERSION = 'sentinel.runtime.v1';

    /**
     * Strict whitelist of top-level fields. Anything not listed here is
     * rejected — this is the structural guarantee that no secret can be
     * smuggled into stored evidence under an exotic key.
     */
    public const ALLOWED_FIELDS = [
        'evidence_version',
        'agent_id',
        'agent_version',
        'project_key',
        'environment',
        'release_id',
        'deployment_id',
        'current_release_path',
        'runtime_marker',
        'release_manifest_commit_sha',
        'artifact_checksum_sha256',
        'timestamp',
        'sequence',
        'nonce',
        'snapshot_status',
    ];

    /**
     * Fields that must be present and non-empty before the snapshot is
     * considered complete. Missing required fields yields snapshot_status =
     * incomplete (never VERIFIED) — see AgentRuntimeEvidenceReader.
     */
    public const REQUIRED_FIELDS = [
        'evidence_version',
        'agent_id',
        'agent_version',
        'project_key',
        'environment',
        'release_id',
        'deployment_id',
        'current_release_path',
        'runtime_marker',
        'timestamp',
        'sequence',
        'nonce',
    ];

    public const SNAPSHOT_STATUS_COMPLETE = 'complete';
    public const SNAPSHOT_STATUS_INCOMPLETE = 'incomplete';

    public const SNAPSHOT_STATUS_VALUES = [
        self::SNAPSHOT_STATUS_COMPLETE,
        self::SNAPSHOT_STATUS_INCOMPLETE,
    ];

    /**
     * Key names that must never appear in evidence — defensive second
     * layer on top of the whitelist.
     */
    private const FORBIDDEN_KEY_PATTERN = '/(token|secret|password|passwd|cookie|credential|authorization|api[_-]?key|private[_-]?key)/i';

    /**
     * Secret-shaped value patterns. A value matching any of these makes
     * the document refuse to build.
     */
    private const SECRET_VALUE_PATTERNS = [
        '/Bearer\s+[A-Za-z0-9._~+\/=-]{8,}/i',
        '/Basic\s+[A-Za-z0-9+\/=]{8,}/i',
        '/gh[pousr]_[A-Za-z0-9]{10,}/',
        '/AKIA[0-9A-Z]{16}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/',
        '/\b(?:password|passwd|secret|token|api[_-]?key|credential)\s*[=:]\s*[^\s&;,"\'()]+/i',
    ];

    /** Path traversal patterns that indicate an attempt to escape the release root. */
    private const PATH_TRAVERSAL_PATTERNS = [
        '/^[A-Za-z]:/',
        '~^[\\\\/]~',
        '/\.\.\//',
        '/\.\.\\\\/',
        '/^\\//',
        '/^[a-zA-Z]:\\\\/',
    ];

    /** @var array<string, mixed> canonicalized evidence, never mutated */
    private array $evidence;

    /**
     * @param array<string, mixed> $evidence already canonicalized
     */
    private function __construct(array $evidence)
    {
        $this->evidence = $evidence;
    }

    /**
     * Build a contract from a raw array. Normalizes empty strings to null,
     * rejects unknown/forbidden fields and secret-shaped values,
     * validates path containment, then canonicalizes the whole document.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException when the input cannot be
     *                                  represented as sentinel.runtime.v1
     *                                  evidence (unknown field, malformed
     *                                  type, secret value, path escape).
     */
    public static function fromArray(array $input): self
    {
        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_FIELDS, true)) {
                throw new InvalidArgumentException(sprintf(
                    "sentinel.runtime.v1 evidence field '%s' is not part of the contract schema.",
                    is_string($key) ? $key : gettype($key),
                ));
            }
            self::assertKeyAllowed((string) $key);
        }

        $evidence = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            $value = $input[$field] ?? null;

            switch ($field) {
                case 'sequence':
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_int($value) || $value < 1) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field 'sequence' must be a positive integer.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'snapshot_status':
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_string($value) || ! in_array($value, self::SNAPSHOT_STATUS_VALUES, true)) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field 'snapshot_status' must be complete or incomplete.");
                    }
                    $evidence[$field] = $value;
                    break;

                default:
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_string($value) && ! is_int($value)) {
                        throw new InvalidArgumentException("sentinel.runtime.v1 evidence field '{$field}' must be a string.");
                    }
                    $value = is_string($value) ? trim($value) : $value;
                    if ($value === '') {
                        $evidence[$field] = null;
                        break;
                    }
                    $value = (string) $value;
                    self::assertNoSecret($value, $field);
                    self::assertPathContainment($value, $field);
                    $evidence[$field] = $value;
                    break;
            }
        }

        // Determine snapshot_status if not explicitly provided
        if ($evidence['snapshot_status'] === null) {
            $missing = [];
            foreach (self::REQUIRED_FIELDS as $field) {
                if ($evidence[$field] === null) {
                    $missing[$field] = 1;
                }
            }
            $evidence['snapshot_status'] = $missing === []
                ? self::SNAPSHOT_STATUS_COMPLETE
                : self::SNAPSHOT_STATUS_INCOMPLETE;
        }

        return new self(self::canonicalize($evidence));
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('sentinel.runtime.v1 evidence is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('sentinel.runtime.v1 evidence must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }

    /**
     * A fresh, explicitly empty evidence shell carrying only the
     * contract version. Useful for producers that fill fields in as
     * they observe them; anything unfilled stays incomplete.
     *
     * @param  array<string, mixed>  $seed
     */
    public static function empty(array $seed = []): self
    {
        return self::fromArray(array_merge(['evidence_version' => self::VERSION], $seed));
    }

    public function version(): ?string
    {
        $version = $this->evidence['evidence_version'];

        return is_string($version) ? $version : null;
    }

    public function isSupportedVersion(): bool
    {
        return $this->version() === self::VERSION;
    }

    public function get(string $field): mixed
    {
        if (! in_array($field, self::ALLOWED_FIELDS, true)) {
            throw new InvalidArgumentException("sentinel.runtime.v1 evidence field '{$field}' is not part of the contract schema.");
        }

        return $this->evidence[$field] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->evidence;
    }

    /**
     * Byte-stable serialization: recursively key-sorted, so equal
     * evidence always serializes identically regardless of input order.
     *
     * @throws \JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * SHA-256 of the canonical JSON form. Two contracts are equal iff
     * their hashes are equal; any single-field change changes the hash.
     *
     * @throws \JsonException
     */
    public function hash(): string
    {
        return hash('sha256', $this->toJson());
    }

    /**
     * True when every required field is present and non-empty.
     *
     * @return array<string, int> map of missing field => 1
     */
    public function missingRequiredFields(): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            $value = $this->evidence[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[$field] = 1;
            }
        }

        return $missing;
    }

    public function isComplete(): bool
    {
        return $this->evidence['snapshot_status'] === self::SNAPSHOT_STATUS_COMPLETE;
    }

    private static function assertKeyAllowed(string $key): void
    {
        if (preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1) {
            throw new InvalidArgumentException("sentinel.runtime.v1 evidence key '{$key}' looks credential-bearing and is forbidden.");
        }
    }

    private static function assertNoSecret(string $value, string $field): void
    {
        foreach (self::SECRET_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new InvalidArgumentException("sentinel.runtime.v1 evidence field '{$field}' contains credential-shaped content; secrets are never stored in evidence.");
            }
        }
    }

    private static function assertPathContainment(string $value, string $field): void
    {
        // Only validate path-like fields
        if (! in_array($field, ['current_release_path'], true)) {
            return;
        }

        foreach (self::PATH_TRAVERSAL_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new InvalidArgumentException("sentinel.runtime.v1 evidence field '{$field}' contains path traversal; paths must be contained within the release root.");
            }
        }
    }

    /**
     * Recursively sort associative keys (list order preserved) so the
     * document has exactly one canonical byte representation.
     *
     * @param  array<string, mixed>  $value
     *
     * @return array<string, mixed>
     */
    private static function canonicalize(array $value): array
    {
        ksort($value, SORT_STRING);

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = array_is_list($child)
                    ? array_map(static fn ($item) => is_array($item) ? self::canonicalize($item) : $item, $child)
                    : self::canonicalize($child);
            }
        }

        return $value;
    }
}
