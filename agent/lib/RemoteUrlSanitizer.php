<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Phase 4-H: a git remote URL can embed credentials
 * (https://user:token@github.com/owner/repo.git) that must never leave
 * this machine. parse_url() gives an exact, unambiguous split of the
 * userinfo component — more precise here than a regex, since a URL has a
 * real grammar for where credentials can appear. This is the Agent-side
 * equivalent of the Hub's SecretRedactor (different runtime, no shared
 * autoload, so it can't literally reuse that class — see
 * docs/PHASE_LOCAL_DISCOVERY_ZERO_CONFIG_AUDIT.md §5).
 */
final class RemoteUrlSanitizer
{
    public static function sanitize(string $remoteUrl): string
    {
        $remoteUrl = trim($remoteUrl);

        // git@github.com:owner/repo.git (SCP-style) carries no userinfo to
        // strip in the URL sense — it's already safe to send as-is.
        if (!str_contains($remoteUrl, '://')) {
            return $remoteUrl;
        }

        $parts = parse_url($remoteUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $remoteUrl;
        }

        $rebuilt = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }
        $rebuilt .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        return $rebuilt;
    }

    /**
     * Best-effort provider name from the sanitized host (github.com ->
     * "github", gitlab.example.com -> "gitlab"). Falls back to the raw
     * host when it doesn't match a known provider — never guesses beyond
     * what's actually in the URL.
     */
    public static function provider(string $sanitizedUrl): ?string
    {
        $host = parse_url($sanitizedUrl, PHP_URL_HOST);
        if ($host === null || $host === false) {
            return null;
        }

        foreach (['github', 'gitlab', 'bitbucket'] as $known) {
            if (str_contains($host, $known)) {
                return $known;
            }
        }

        return $host;
    }

    /**
     * "owner/repo" from either URL style, with a trailing ".git" trimmed.
     */
    public static function repositoryName(string $remoteUrl): ?string
    {
        $sanitized = self::sanitize($remoteUrl);

        if (str_contains($sanitized, '://')) {
            $path = parse_url($sanitized, PHP_URL_PATH);
        } else {
            // SCP-style: git@host:owner/repo.git
            $colonPos = strpos($sanitized, ':');
            $path = $colonPos === false ? null : substr($sanitized, $colonPos + 1);
        }

        if ($path === null || $path === false) {
            return null;
        }

        $path = trim($path, '/');
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return $path === '' ? null : $path;
    }
}
