<?php

namespace App\Support\Deployment;

final class SourceWorkspacePath
{
    public static function isAbsoluteForOperatingSystem(string $path, ?string $operatingSystem): bool
    {
        $path = trim($path);
        $isWindowsPath = preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
        $isPosixPath = str_starts_with($path, '/');
        $normalizedOs = strtolower(trim((string) $operatingSystem));

        if (str_contains($normalizedOs, 'windows')) {
            return $isWindowsPath;
        }

        if ($normalizedOs !== '') {
            return $isPosixPath;
        }

        return $isWindowsPath || $isPosixPath;
    }

    public static function isFilesystemRoot(string $path): bool
    {
        $normalized = str_replace('\\', '/', trim($path));

        return $normalized === '/' || preg_match('#^[A-Za-z]:/$#', $normalized) === 1;
    }

    public static function exampleForOperatingSystem(?string $operatingSystem, string $projectKey = 'project'): string
    {
        $safeKey = preg_replace('/[^a-z0-9._-]+/i', '-', $projectKey) ?: 'project';

        return str_contains(strtolower(trim((string) $operatingSystem)), 'windows')
            ? 'C:\\deployment-workspaces\\' . trim($safeKey, '-')
            : '/opt/deployment-agent/workspaces/' . trim($safeKey, '-');
    }

    public static function isProductionLike(string $path): bool
    {
        $normalized = strtolower(rtrim(str_replace('\\', '/', trim($path)), '/'));

        return in_array($normalized, ['/var/www', '/srv/www'], true)
            || str_starts_with($normalized, '/var/www/')
            || str_starts_with($normalized, '/srv/www/')
            || preg_match('#^/home/[^/]+/(?:public|public_html)(?:/|$)#', $normalized) === 1
            || preg_match('#(?:^|/)htdocs(?:/|$)#', $normalized) === 1
            || preg_match('#(?:^|/)(?:releases?|current)(?:/|$)#', $normalized) === 1
            || preg_match('#^[a-z]:/inetpub/wwwroot(?:/|$)#', $normalized) === 1;
    }

    public static function defaultFor(string $projectKey): string
    {
        $safeKey = preg_replace('/[^a-z0-9._-]+/i', '-', $projectKey) ?: 'project';

        return '/opt/deployment-agent/workspaces/' . trim($safeKey, '-');
    }
}
