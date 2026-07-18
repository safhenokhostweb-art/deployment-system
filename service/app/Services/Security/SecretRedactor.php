<?php

namespace App\Services\Security;

/**
 * Best-effort redaction applied to pipeline command/output text before it is
 * persisted. Pipeline steps only ever reference {branch}/{commit}/
 * {working_directory}/{php_binary} placeholders — no vault credential is
 * ever substituted into a command — but a project author can still paste a
 * literal token into a step by mistake, or a build tool can echo one to
 * stdout. This is defense in depth, applied at write time (not just
 * display time) so a leaked secret never reaches the database or backups
 * in the first place.
 */
class SecretRedactor
{
    private const MASK = '[REDACTED]';

    /**
     * Each pattern is applied independently with its own replacement, so a
     * pattern that captures a "key=" prefix can keep it and mask only the
     * value. Patterns are self-contained (own inline flags, own groups) to
     * avoid cross-pattern scoping bugs from combining them into one regex.
     *
     * @var array<int, array{pattern: string, replacement: string}>
     */
    private const RULES = [
        // key=value / key: value assignments naming an obvious secret — keep the key, mask the value.
        ['pattern' => '/(?i)\b((?:api[_-]?key|secret|token|password|passwd|access[_-]?key|private[_-]?key)\b\s*[:=]\s*)([^\s\'"]+)/', 'replacement' => '$1' . self::MASK],
        // GitHub tokens (ghp_, gho_, ghu_, ghs_, ghr_ prefixes).
        ['pattern' => '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/', 'replacement' => self::MASK],
        // AWS access key IDs.
        ['pattern' => '/\bAKIA[0-9A-Z]{16}\b/', 'replacement' => self::MASK],
        // Bearer tokens in headers/output — keep "Bearer ", mask the token.
        ['pattern' => '/(?i)(bearer\s+)([A-Za-z0-9._-]{16,})/', 'replacement' => '$1' . self::MASK],
    ];

    public function redact(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        foreach (self::RULES as $rule) {
            $text = preg_replace($rule['pattern'], $rule['replacement'], $text) ?? $text;
        }

        return $text;
    }
}
