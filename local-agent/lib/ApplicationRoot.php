<?php

declare(strict_types=1);

namespace LocalAgent;

/** Canonical, repository-relative root of the deployable application. */
final class ApplicationRoot
{
    public static function normalize(mixed $value): string
    {
        $value = is_string($value) ? trim(str_replace('\\', '/', $value)) : '.';
        if ($value === '' || $value === '.') {
            return '.';
        }

        if (str_starts_with($value, '/') || preg_match('#^[A-Za-z]:/#', $value) === 1) {
            throw new LocalBuildFailedException('invalid_application_root', 'application_root must be relative to the repository root.');
        }

        $parts = explode('/', $value);
        if (in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            throw new LocalBuildFailedException('invalid_application_root', 'application_root must be a normalized relative path without traversal.');
        }

        return implode('/', $parts);
    }

    public static function resolve(string $repositoryRoot, mixed $value): string
    {
        $relative = self::normalize($value);
        $path = $relative === '.' ? $repositoryRoot : rtrim($repositoryRoot, '\\/') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_dir($path)) {
            throw new LocalBuildFailedException('invalid_application_root', "application_root '{$relative}' does not exist in the isolated workspace.");
        }

        return $path;
    }
}
