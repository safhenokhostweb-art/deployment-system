<?php

namespace App\Support\Deployment;

final class SourceWorkspacePath
{
    public static function isProductionLike(string $path): bool
    {
        $normalized = strtolower(rtrim(str_replace('\\', '/', trim($path)), '/'));

        return in_array($normalized, ['/var/www', '/srv/www'], true)
            || str_starts_with($normalized, '/var/www/')
            || str_starts_with($normalized, '/srv/www/')
            || preg_match('#^/home/[^/]+/(?:public|public_html)(?:/|$)#', $normalized) === 1
            || preg_match('#(?:^|/)(?:releases?|current)(?:/|$)#', $normalized) === 1
            || preg_match('#^[a-z]:/inetpub/wwwroot(?:/|$)#', $normalized) === 1;
    }

    public static function defaultFor(string $projectKey): string
    {
        $safeKey = preg_replace('/[^a-z0-9._-]+/i', '-', $projectKey) ?: 'project';

        return '/opt/deployment-agent/workspaces/' . trim($safeKey, '-');
    }
}
