<?php

declare(strict_types=1);

namespace LocalAgent;

/** Resolves artifact input only from an existing, non-production checkout. */
final class ArtifactSourceResolver
{
    /**
     * @param array<string, mixed> $project
     */
    public static function resolve(array $project, string $projectKey): string
    {
        $candidates = [];
        foreach (['source_workspace_path', 'local_path'] as $field) {
            $value = $project[$field] ?? null;
            if (is_string($value) && trim($value) !== '' && !in_array(trim($value), $candidates, true)) {
                $candidates[] = trim($value);
            }
        }

        $forbidden = [];
        foreach ($candidates as $candidate) {
            if (SafetyChecker::productionSourcePathForbidden($candidate)) {
                $forbidden[] = $candidate;
                continue;
            }
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        if ($forbidden !== []) {
            throw new LocalBuildFailedException(
                'production_source_forbidden',
                "Refusing to build artifact for '{$projectKey}' from production-like path(s): " . implode(', ', $forbidden) . '. Configure a dedicated source_workspace_path.',
            );
        }

        throw new LocalBuildFailedException(
            'local_workspace_prepare_failed',
            "No existing isolated source workspace is configured for project '{$projectKey}'.",
        );
    }
}
