<?php

namespace App\Services\Sentinel;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * sentinel.v1 — the immutable Deployment Proof evidence document.
 *
 * Sentinel's job is to prove one chain with real evidence:
 *
 *   Local Source -> Commit SHA -> Artifact -> Hub Release -> Agent ->
 *   Server Release -> Active Runtime -> Public Health
 *
 * The contract is deliberately UI-independent, framework-free and
 * side-effect free: it never touches the database, the network or any
 * Production flow. It is a value object that can be built, stored,
 * hashed and compared.
 *
 * Rules enforced here (fail-closed at construction time):
 *
 *  - Expected and observed values are separate fields
 *    (expected_commit_sha vs observed_commit_sha,
 *    artifact_checksum_sha256 vs observed_artifact_checksum_sha256,
 *    expected_runtime_marker vs runtime_marker,
 *    expected_health_status vs health_status).
 *  - Only whitelisted fields exist. Any unknown field name is rejected
 *    so a token/secret/cookie/credential key can never be stored.
 *  - Every string value is scanned for secret-shaped content and the
 *    document refuses to build if one is found.
 *  - health_endpoint is sanitized (userinfo, query string and fragment
 *    are stripped) so credentials never enter the evidence.
 *  - The document is canonicalized (recursively key-sorted) so equal
 *    evidence always produces byte-identical JSON and the same SHA-256.
 *  - Nothing is ever mutated after construction; withResult() returns a
 *    new instance.
 *
 * The `result` field is advisory only: SentinelVerifier always
 * recomputes it. Storing `VERIFIED` here does not make anything
 * VERIFIED.
 */
final class SentinelEvidenceContract
{
    public const VERSION = 'sentinel.v1';

    public const RESULT_VERIFIED = 'VERIFIED';
    public const RESULT_FAILED = 'FAILED';
    public const RESULT_UNKNOWN = 'UNKNOWN';

    public const RESULT_VALUES = [
        self::RESULT_VERIFIED,
        self::RESULT_FAILED,
        self::RESULT_UNKNOWN,
    ];

    /**
     * Strict whitelist of top-level fields. Anything not listed here is
     * rejected — this is the structural guarantee that no secret can be
     * smuggled into stored evidence under an exotic key.
     */
    public const ALLOWED_FIELDS = [
        'evidence_version',
        'project_key',
        'environment',
        'expected_commit_sha',
        'observed_commit_sha',
        'artifact_id',
        'artifact_checksum_sha256',
        'observed_artifact_checksum_sha256',
        'release_id',
        'observed_release_id',
        'agent_id',
        'agent_version',
        'runtime_release_path',
        'expected_runtime_marker',
        'runtime_marker',
        'health_endpoint',
        'expected_health_status',
        'health_status',
        'checks',
        'verified_at',
        'result',
        // File-level verification (additive, feature-flagged)
        'expected_file_manifest_hash',
        'observed_file_manifest_hash',
        'expected_file_manifest_entry_count',
        'observed_file_manifest_entry_count',
        'expected_file_manifest_total_size',
        'observed_file_manifest_total_size',
        'file_comparison_scope',
    ];

    /**
     * Fields that must be present and non-empty before a VERIFIED
     * outcome is even possible. Missing required evidence yields
     * UNKNOWN (never VERIFIED) — see SentinelVerifier.
     *
     * File-level fields are NOT in REQUIRED_FIELDS — they are additive
     * and feature-flagged. When the feature is disabled, their absence
     * must not prevent VERIFIED. When enabled, the verifier will
     * explicitly check them via dedicated comparison checks.
     */
    public const REQUIRED_FIELDS = [
        'evidence_version',
        'project_key',
        'environment',
        'expected_commit_sha',
        'observed_commit_sha',
        'artifact_id',
        'artifact_checksum_sha256',
        'observed_artifact_checksum_sha256',
        'release_id',
        'observed_release_id',
        'agent_id',
        'agent_version',
        'runtime_release_path',
        'expected_runtime_marker',
        'runtime_marker',
        'health_endpoint',
        'health_status',
        'verified_at',
        'checks',
    ];

    public const CHECK_STATUS_PASS = 'pass';
    public const CHECK_STATUS_FAIL = 'fail';
    public const CHECK_STATUS_UNKNOWN = 'unknown';

    public const DEFAULT_EXPECTED_HEALTH_STATUS = 200;

    /** Allowed keys inside every entry of `checks`. */
    public const ALLOWED_CHECK_KEYS = ['status', 'detail'];

    /**
     * Key names that must never appear in evidence — defensive second
     * layer on top of the whitelist (also applied to check ids).
     */
    private const FORBIDDEN_KEY_PATTERN = '/(token|secret|password|passwd|cookie|credential|authorization|api[_-]?key|private[_-]?key)/i';

    /**
     * Secret-shaped value patterns. A value matching any of these makes
     * the document refuse to build: an evidence document that contains
     * a credential is worse than no evidence at all.
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
     * Build a contract from a raw array. Normalizes empty strings to
     * null, defaults expected_health_status, sanitizes the health
     * endpoint, rejects unknown/forbidden fields and secret-shaped
     * values, then canonicalizes the whole document.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException when the input cannot be
     *                                  represented as sentinel.v1
     *                                  evidence (unknown field,
     *                                  malformed type, secret value).
     */
    public static function fromArray(array $input): self
    {
        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_FIELDS, true)) {
                throw new InvalidArgumentException(sprintf(
                    "sentinel.v1 evidence field '%s' is not part of the contract schema.",
                    is_string($key) ? $key : gettype($key),
                ));
            }
            self::assertKeyAllowed((string) $key);
        }

        $evidence = [];

        foreach (self::ALLOWED_FIELDS as $field) {
            $value = $input[$field] ?? null;

            switch ($field) {
                case 'health_status':
                case 'expected_health_status':
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_int($value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be an integer HTTP status.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'checks':
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_array($value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field 'checks' must be an object map.");
                    }
                    $evidence[$field] = self::normalizeChecks($value);
                    break;

                case 'result':
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_string($value) || ! in_array($value, self::RESULT_VALUES, true)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field 'result' must be VERIFIED, FAILED, UNKNOWN or null.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'expected_file_manifest_hash':
                case 'observed_file_manifest_hash':
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_string($value) || ! preg_match('/^[0-9a-f]{64}$/i', $value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be a 64-character hex SHA-256.");
                    }
                    $evidence[$field] = strtolower((string) $value);
                    break;

                case 'expected_file_manifest_entry_count':
                case 'observed_file_manifest_entry_count':
                case 'expected_file_manifest_total_size':
                case 'observed_file_manifest_total_size':
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_int($value) || $value < 0) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be a non-negative integer.");
                    }
                    $evidence[$field] = $value;
                    break;

                case 'file_comparison_scope':
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_string($value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be a string.");
                    }
                    $value = trim($value);
                    if ($value === '') {
                        $evidence[$field] = null;
                        break;
                    }
                    self::assertNoSecret($value, $field);
                    $evidence[$field] = $value;
                    break;

                default:
                    if ($value === null) {
                        $evidence[$field] = null;
                        break;
                    }
                    if (! is_string($value) && ! is_int($value)) {
                        throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' must be a string.");
                    }
                    $value = is_string($value) ? trim($value) : $value;
                    if ($value === '') {
                        $evidence[$field] = null;
                        break;
                    }
                    $value = (string) $value;
                    if ($field === 'health_endpoint') {
                        // Strip userinfo/query/fragment BEFORE the secret
                        // scan so a credentialed URL degrades to a clean
                        // endpoint instead of poisoning the document.
                        $value = self::sanitizeEndpoint($value);
                    }
                    self::assertNoSecret($value, $field);
                    $evidence[$field] = $value;
                    break;
            }
        }

        // Sentinel never guesses an expected health status: 200 unless
        // the producer explicitly stated otherwise.
        $evidence['expected_health_status'] ??= self::DEFAULT_EXPECTED_HEALTH_STATUS;

        return new self(self::canonicalize($evidence));
    }

    /**
     * @throws InvalidArgumentException|JsonException
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('sentinel.v1 evidence is not valid JSON: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('sentinel.v1 evidence must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return self::fromArray($decoded);
    }

    /**
     * A fresh, explicitly empty evidence shell carrying only the
     * contract version. Useful for producers that fill fields in as
     * they observe them; anything unfilled stays UNKNOWN.
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
            throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' is not part of the contract schema.");
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
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode($this->evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * SHA-256 of the canonical JSON form. Two contracts are equal iff
     * their hashes are equal; any single-field change changes the hash.
     *
     * @throws JsonException
     */
    public function hash(): string
    {
        return hash('sha256', $this->toJson());
    }

    /**
     * Stamp a verification outcome onto a NEW contract instance. The
     * original document is never mutated (immutability guarantee).
     */
    public function withResult(string $result): self
    {
        if (! in_array($result, self::RESULT_VALUES, true)) {
            throw new InvalidArgumentException("sentinel.v1 evidence result must be VERIFIED, FAILED or UNKNOWN, got '{$result}'.");
        }

        $copy = $this->evidence;
        $copy['result'] = $result;

        return new self(self::canonicalize($copy));
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

    /**
     * True when verified_at parses as a timestamp.
     */
    public function hasParseableVerifiedAt(): bool
    {
        $value = $this->evidence['verified_at'] ?? null;
        if (! is_string($value) || $value === '') {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     *
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeChecks(array $checks): array
    {
        $normalized = [];

        foreach ($checks as $id => $check) {
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('sentinel.v1 evidence check ids must be non-empty strings.');
            }
            self::assertKeyAllowed($id);

            if (! is_array($check)) {
                throw new InvalidArgumentException("sentinel.v1 evidence check '{$id}' must be an object.");
            }

            foreach (array_keys($check) as $key) {
                if (! is_string($key) || ! in_array($key, self::ALLOWED_CHECK_KEYS, true)) {
                    throw new InvalidArgumentException(sprintf(
                        "sentinel.v1 evidence check '%s' has an unsupported key '%s'.",
                        $id,
                        is_string($key) ? $key : gettype($key),
                    ));
                }
            }

            $status = $check['status'] ?? null;
            if (! is_string($status) || ! in_array($status, [self::CHECK_STATUS_PASS, self::CHECK_STATUS_FAIL, self::CHECK_STATUS_UNKNOWN], true)) {
                throw new InvalidArgumentException("sentinel.v1 evidence check '{$id}' status must be pass, fail or unknown.");
            }

            $detail = $check['detail'] ?? null;
            if ($detail !== null) {
                if (! is_string($detail)) {
                    throw new InvalidArgumentException("sentinel.v1 evidence check '{$id}' detail must be a string.");
                }
                self::assertNoSecret($detail, "checks.{$id}.detail");
            }

            $normalized[$id] = $detail === null
                ? ['status' => $status]
                : ['status' => $status, 'detail' => $detail];
        }

        return $normalized;
    }

    private static function assertKeyAllowed(string $key): void
    {
        if (preg_match(self::FORBIDDEN_KEY_PATTERN, $key) === 1) {
            // Deliberately does NOT echo the key value back beyond its
            // name — callers must never log raw evidence input.
            throw new InvalidArgumentException("sentinel.v1 evidence key '{$key}' looks credential-bearing and is forbidden.");
        }
    }

    private static function assertNoSecret(string $value, string $field): void
    {
        foreach (self::SECRET_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                throw new InvalidArgumentException("sentinel.v1 evidence field '{$field}' contains credential-shaped content; secrets are never stored in evidence.");
            }
        }
    }

    /**
     * Strip userinfo, query string and fragment from a health endpoint.
     * Only the scheme://host[:port]/path form enters evidence.
     */
    private static function sanitizeEndpoint(string $url): string
    {
        $clean = preg_replace('/#.*$/', '', $url) ?? $url;
        $clean = preg_replace('/\?.*$/', '', $clean) ?? $clean;
        $clean = preg_replace('#(//)[^/@]*@#', '$1', $clean) ?? $clean;

        return $clean;
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
