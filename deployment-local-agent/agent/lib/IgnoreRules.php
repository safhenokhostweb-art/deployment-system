<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Phase 5.2: `.local-agent-ignore` pattern matching. Deliberately
 * enforced here, in PHP, rather than by writing into git's own ignore
 * mechanism (.gitignore/.git/info/exclude) — a git-ignore-based approach
 * only ever affects UNTRACKED files, so a path that was already
 * committed once by mistake would keep being reported/staged forever.
 * Filtering the file list ourselves, before it is ever reported to the
 * Hub or staged for commit, is a hard guarantee regardless of git
 * history, and is a pure function anyone can unit-test.
 *
 * Supported pattern forms (matches the spec's own example exactly):
 *   node_modules/     -> directory prefix (anything under it, any depth)
 *   *.log              -> suffix glob
 *   .env.*             -> prefix glob
 *   .env                -> exact relative path
 *   storage/logs/       -> directory prefix
 *
 * See MANDATORY_PATTERNS below for the hardcoded sensitive-file
 * denylist that applies unconditionally, on top of whatever the
 * developer configures.
 */
final class IgnoreRules
{
    /**
     * @return array<int, string>
     */
    public static function loadPatterns(string $repositoryPath): array
    {
        $path = rtrim($repositoryPath, '/\\') . DIRECTORY_SEPARATOR . '.local-agent-ignore';

        if (!is_file($path)) {
            return [];
        }

        $lines = preg_split('/\r?\n/', (string) file_get_contents($path));
        $patterns = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $patterns[] = $line;
        }

        return $patterns;
    }

    /**
     * Phase 5.4 (spec section 5): sensitive files must NEVER be
     * committed — not "unless the developer remembers to list them,"
     * always. Applied unconditionally in filter(), same as the
     * ignore-rules file itself already was, so a `.env` or a private key
     * can never appear in a "changed files" review list nor get staged,
     * regardless of what (or whether) `.local-agent-ignore` says.
     */
    private const MANDATORY_PATTERNS = [
        '.local-agent-ignore',
        '.env',
        '.env.*',
        '*.pem',
        '*.key',
        '*.pfx',
        '*.p12',
        'credentials.json',
        '*.token',
    ];

    /**
     * @param array<int, string> $files relative paths, as reported by
     *     GitManager::changedFiles() (may include a rename's
     *     "old -> new" form, matched on the "new" side).
     * @param array<int, string> $patterns
     * @return array<int, string>
     */
    public static function filter(array $files, array $patterns): array
    {
        $patterns = array_merge($patterns, self::MANDATORY_PATTERNS);

        return array_values(array_filter($files, static fn (string $file) => !self::isIgnored($file, $patterns)));
    }

    /**
     * @param array<int, string> $patterns
     */
    public static function isIgnored(string $file, array $patterns): bool
    {
        // A rename is reported as "old/path.php -> new/path.php" — the
        // file that will actually exist (and get staged) is the new side.
        $normalized = str_contains($file, ' -> ') ? trim(substr($file, strpos($file, ' -> ') + 4)) : $file;
        $normalized = str_replace('\\', '/', $normalized);

        foreach ($patterns as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);

            if (str_ends_with($pattern, '/')) {
                if (str_starts_with($normalized, $pattern) || str_starts_with($normalized, rtrim($pattern, '/') . '/')) {
                    return true;
                }

                continue;
            }

            if (str_starts_with($pattern, '*')) {
                if (str_ends_with($normalized, substr($pattern, 1))) {
                    return true;
                }

                continue;
            }

            // Phase 5.4: a trailing "*" (e.g. ".env.*") is a prefix
            // glob — needed for .env.local/.env.production/etc, which a
            // leading-"*" suffix glob can't express.
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($normalized, substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }

            if ($normalized === $pattern) {
                return true;
            }
        }

        return false;
    }
}
