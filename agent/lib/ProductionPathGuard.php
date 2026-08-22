<?php

declare(strict_types=1);

namespace DeploymentAgent;

final class ProductionPathGuard
{
    public static function isProductionLike(string $path): bool
    {
        $path = strtolower(rtrim(str_replace('\\', '/', trim($path)), '/'));

        return in_array($path, ['/var/www', '/srv/www'], true)
            || str_starts_with($path, '/var/www/')
            || str_starts_with($path, '/srv/www/')
            || preg_match('#^/home/[^/]+/(?:public|public_html)(?:/|$)#', $path) === 1
            || preg_match('#(?:^|/)(?:releases?|current)(?:/|$)#', $path) === 1
            || preg_match('#^[a-z]:/inetpub/wwwroot(?:/|$)#', $path) === 1;
    }
}
