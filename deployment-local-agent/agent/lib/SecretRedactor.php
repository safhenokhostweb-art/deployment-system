<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (materialization diagnostic gap): a single, shared
 * redaction pass any local-agent code path can use before writing
 * otherwise-raw process output (git stderr, an unrecognized Hub response
 * body, ...) to the local Agent log. Extracted from Client's own
 * previously-private redactForLog() — same key=value/Bearer patterns,
 * unchanged — plus a URL-embedded-credential pattern
 * (scheme://user:password@host) neither of this class's callers needed
 * to redact until raw git output (which can legitimately contain a
 * repository remote URL) started being logged too. This agent's own
 * token/signing secret are never interpolated into any command or
 * process output this class is ever asked to redact in the first place —
 * see Client's own docblock.
 */
final class SecretRedactor
{
    public static function redactForLog(string $text): string
    {
        $text = preg_replace('/(api[_-]?key|secret|token|password|passwd|access[_-]?key|private[_-]?key)("?\s*[:=]\s*"?)[^\s"&,}]+/i', '$1$2[REDACTED]', $text) ?? $text;
        $text = preg_replace('/(bearer\s+)[A-Za-z0-9._-]{16,}/i', '$1[REDACTED]', $text) ?? $text;
        // scheme://user:password@host — a credential embedded directly in a
        // URL (e.g. a dependency or remote configured over HTTPS). Requires
        // a literal ':' between the user and the '@' so a normal SSH remote
        // like ssh://git@ssh.github.com is never touched — it carries no
        // password segment at all.
        $text = preg_replace('#([A-Za-z][A-Za-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@#', '$1[REDACTED]@', $text) ?? $text;

        return $text;
    }
}
