<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Phase 4-H: scans the immediate subdirectories of a configured
 * workspace_root and reports structured metadata only (name/path/type/
 * framework_version/git info) — never a file's contents beyond the
 * specific, allow-listed config files below, and never anything on the
 * deny-list regardless of what the allow-list would otherwise permit
 * (docs/PHASE_LOCAL_DISCOVERY_ZERO_CONFIG_AUDIT.md §5, Task 9 of the
 * implementation brief). Never recurses into vendor/, node_modules/, or
 * .git/ as project candidates — those are noise, not projects.
 */
final class WorkspaceScanner
{
    private const SKIP_DIRECTORY_NAMES = ['vendor', 'node_modules', '.git', '.idea', '.vscode'];

    /**
     * Filenames this scanner will ever open the contents of. Deliberately
     * a short, explicit list — composer.json/package.json/git metadata/
     * WordPress's own version constant. Nothing resembling an env file,
     * key, or credential store is ever in this list.
     */
    private const ALLOWED_READ_FILENAMES = ['composer.json', 'package.json', 'config', 'HEAD', 'packed-refs', 'version.php'];

    /**
     * Active exclusion, independent of the allow-list above — a filename
     * matching any of these is refused even if some future change to the
     * allow-list would otherwise have let it through.
     */
    private const FORBIDDEN_FILENAMES = ['.env', '.env.local', 'id_rsa', 'id_ed25519', 'id_ecdsa', 'id_dsa'];
    private const FORBIDDEN_EXTENSIONS = ['pem', 'key', 'ppk', 'p12', 'pfx'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scan(string $workspaceRoot): array
    {
        $projects = [];

        if (!is_dir($workspaceRoot)) {
            return $projects;
        }

        $entries = scandir($workspaceRoot);
        if ($entries === false) {
            return $projects;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (in_array($entry, self::SKIP_DIRECTORY_NAMES, true)) {
                continue;
            }

            $path = rtrim($workspaceRoot, '/\\') . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $type = $this->detectType($path);
            $projects[] = [
                'name' => $entry,
                'path' => $path,
                'type' => $type,
                'framework_version' => $this->detectFrameworkVersion($path, $type),
                'git' => $this->scanGit($path),
            ];
        }

        return $projects;
    }

    private function detectType(string $path): string
    {
        if (
            $this->isFile($path, 'artisan')
            && $this->isFile($path, 'composer.json')
            && isset($this->readAllowedJson($path, 'composer.json')['require']['laravel/framework'])
            && is_dir($path . '/app')
            && is_dir($path . '/bootstrap')
            && is_dir($path . '/config')
        ) {
            return 'laravel';
        }

        if (
            (is_file($path . '/wp-config.php') || is_file($path . '/wp-load.php'))
            && is_dir($path . '/wp-content')
        ) {
            return 'wordpress';
        }

        if ($this->isFile($path, 'package.json')) {
            return 'node';
        }

        return 'unknown';
    }

    private function detectFrameworkVersion(string $path, string $type): ?string
    {
        if ($type === 'laravel') {
            $composer = $this->readAllowedJson($path, 'composer.json');

            return $composer['require']['laravel/framework'] ?? null;
        }

        if ($type === 'node') {
            $package = $this->readAllowedJson($path, 'package.json');

            return $package['version'] ?? null;
        }

        if ($type === 'wordpress') {
            // wp-includes/version.php only ever declares plain version
            // constants ($wp_version, $wp_db_version, ...) — no
            // credential of any kind lives in this file.
            $versionFile = $path . '/wp-includes/version.php';
            if (is_file($versionFile) && $this->assertReadable('version.php')) {
                $contents = file_get_contents($versionFile);
                if ($contents !== false && preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $contents, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /**
     * @return array{remote_url: ?string, provider: ?string, repository: ?string, branch: ?string, commit_hash: ?string}|null
     */
    private function scanGit(string $path): ?array
    {
        $gitDir = $path . '/.git';
        if (!is_dir($gitDir)) {
            return null;
        }

        $remoteUrl = $this->readOriginUrl($gitDir . '/config');
        $sanitizedUrl = $remoteUrl !== null ? RemoteUrlSanitizer::sanitize($remoteUrl) : null;

        $branch = $this->readCurrentBranch($gitDir);
        $commitHash = $this->readCommitHash($gitDir, $branch);

        return [
            'remote_url' => $sanitizedUrl,
            'provider' => $sanitizedUrl !== null ? RemoteUrlSanitizer::provider($sanitizedUrl) : null,
            'repository' => $remoteUrl !== null ? RemoteUrlSanitizer::repositoryName($remoteUrl) : null,
            'branch' => $branch,
            'commit_hash' => $commitHash,
        ];
    }

    private function readOriginUrl(string $gitConfigPath): ?string
    {
        if (!is_file($gitConfigPath) || !$this->assertReadable('config')) {
            return null;
        }

        $lines = file($gitConfigPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $inOriginSection = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\[remote\s+"([^"]+)"\]$/', $trimmed, $m)) {
                $inOriginSection = ($m[1] === 'origin');
                continue;
            }
            if (str_starts_with($trimmed, '[')) {
                $inOriginSection = false;
                continue;
            }
            if ($inOriginSection && preg_match('/^url\s*=\s*(.+)$/', $trimmed, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    private function readCurrentBranch(string $gitDir): ?string
    {
        $headFile = $gitDir . '/HEAD';
        if (!is_file($headFile) || !$this->assertReadable('HEAD')) {
            return null;
        }

        $contents = trim((string) file_get_contents($headFile));
        if (preg_match('#^ref:\s*refs/heads/(.+)$#', $contents, $m)) {
            return $m[1];
        }

        return null; // detached HEAD — no branch name to report
    }

    private function readCommitHash(string $gitDir, ?string $branch): ?string
    {
        if ($branch === null) {
            // Detached HEAD: the commit hash is HEAD's own content.
            $headFile = $gitDir . '/HEAD';
            if (is_file($headFile) && $this->assertReadable('HEAD')) {
                $contents = trim((string) file_get_contents($headFile));

                return preg_match('/^[0-9a-f]{40}$/', $contents) ? $contents : null;
            }

            return null;
        }

        $refFile = $gitDir . '/refs/heads/' . $branch;
        if (is_file($refFile)) {
            return trim((string) file_get_contents($refFile));
        }

        $packedRefs = $gitDir . '/packed-refs';
        if (is_file($packedRefs) && $this->assertReadable('packed-refs')) {
            $lines = file($packedRefs, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $line) {
                if (preg_match('/^([0-9a-f]{40}) refs\/heads\/' . preg_quote($branch, '/') . '$/', trim($line), $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    private function isFile(string $path, string $filename): bool
    {
        return is_file($path . '/' . $filename);
    }

    private function readAllowed(string $path, string $filename): ?string
    {
        if (!$this->assertReadable($filename)) {
            return null;
        }

        $contents = file_get_contents($path . '/' . $filename);

        return $contents === false ? null : $contents;
    }

    /**
     * @return array<string, mixed>
     */
    private function readAllowedJson(string $path, string $filename): array
    {
        $contents = $this->readAllowed($path, $filename);
        if ($contents === null) {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Active deny-list check, independent of the allow-list above being
     * correct: a file is only ever opened if it both appears in
     * ALLOWED_READ_FILENAMES and fails every FORBIDDEN_* check here.
     */
    private function assertReadable(string $filename): bool
    {
        if (!in_array($filename, self::ALLOWED_READ_FILENAMES, true)) {
            return false;
        }

        if (in_array($filename, self::FORBIDDEN_FILENAMES, true)) {
            return false;
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            return false;
        }

        return true;
    }
}
