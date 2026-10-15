<?php

namespace App\Services\Sentinel;

final class SentinelReleasePath
{
    public static function isExpectedRelativePath(mixed $path, mixed $deploymentId): bool
    {
        if (! is_string($deploymentId)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/D', $deploymentId) !== 1
            || ! is_string($path)) {
            return false;
        }

        return hash_equals('releases/'.$deploymentId, $path);
    }
}
