<?php

namespace App\Support\Deployment;

/**
 * Root-cause fix (Customer Release #74 / Publish Job #256 — "Dependency
 * contract must pin every Composer path repository exactly once"): this
 * class used to be the ONLY source of truth for a pinned dependency's exact
 * commit — the config carried a hand-maintained commit_sha literal that had
 * no lifecycle for advancing it and no record of which commit a given
 * Release actually built against.
 *
 * It now holds only the trusted, Hub-owned AUTHORIZATION/SAFETY allowlist —
 * which packages, from which project, at which source/destination paths, on
 * which branch — never an exact commit. template() exposes that allowlist.
 * The exact commit is resolved per-Release, once, by
 * App\Services\Publish\ArtifactDependencySnapshotService (via
 * SaasDependencyTargetResolver) and frozen onto the Release; resolveContract()
 * is the pure function that stamps a resolved commit onto this template to
 * produce the same v2 contract shape build-request callers have always
 * received.
 */
final class ArtifactDependencyPolicy
{
    public static function required(string $projectKey): bool
    {
        return (bool) (self::policy($projectKey)['required'] ?? false);
    }

    /**
     * The trusted allowlist/template: every field a caller may rely on
     * EXCEPT the exact commit, which does not belong in static config. See
     * resolveContract() to turn this into a full v2 contract.
     *
     * @return array{version: int, required: bool, dependencies: array<int, array<string, string>>}
     */
    public static function template(string $projectKey): array
    {
        $policy = self::policy($projectKey);
        if (($policy['required'] ?? false) !== true) {
            return ['version' => 2, 'required' => false, 'dependencies' => []];
        }

        $dependencies = array_values($policy['dependencies'] ?? []);
        self::assertValid($dependencies);

        return ['version' => 2, 'required' => true, 'dependencies' => $dependencies];
    }

    /**
     * Stamps a single resolved commit onto every dependency in this
     * project's template, producing the full v2 contract shape. The
     * caller (ArtifactDependencySnapshotService) is responsible for
     * resolving that commit from Hub-owned state exactly once per Release
     * and freezing the result — this method itself is a pure function with
     * no persistence or resolution of its own.
     *
     * @return array<string, mixed>
     */
    public static function resolveContract(string $projectKey, string $commitSha): array
    {
        $template = self::template($projectKey);
        if ($template['required'] !== true) {
            return [];
        }

        if (preg_match('/^[0-9a-f]{40}$/', $commitSha) !== 1) {
            throw new \LogicException('Artifact dependency resolution requires a full lowercase Git SHA.');
        }

        $dependencies = array_map(
            static fn (array $dependency): array => $dependency + ['commit_sha' => $commitSha],
            $template['dependencies'],
        );

        return ['version' => 2, 'required' => true, 'dependencies' => $dependencies];
    }

    /** @return array<int, array<string, string>> */
    public static function allowlist(string $projectKey): array
    {
        return self::template($projectKey)['dependencies'];
    }

    /** @return array<string, mixed> */
    private static function policy(string $projectKey): array
    {
        $policy = config("deployment.artifact_dependency_policies.{$projectKey}", []);
        return is_array($policy) ? $policy : [];
    }

    /**
     * Root-cause fix (Customer Release #74 / Publish Job #256): this Hub
     * policy previously validated each entry independently but never
     * checked the SET for a duplicate destination_path — a config typo or
     * copy/paste error could reach production and only surface later, at
     * PinnedDependencyMaterializer's own (correct, but much later)
     * duplicate check during a real build. Failing here, at policy-read
     * time, catches it before any job is even dispatched.
     */
    private static function assertValid(array $dependencies): void
    {
        if ($dependencies === []) {
            throw new \LogicException('A required artifact dependency policy cannot be empty.');
        }

        $seenDestinations = [];
        foreach ($dependencies as $dependency) {
            foreach (['project_id', 'repository_mapping', 'branch', 'package_name', 'source_path', 'destination_path'] as $field) {
                if (!is_string($dependency[$field] ?? null) || trim($dependency[$field]) === '') {
                    throw new \LogicException("Invalid artifact dependency policy field: {$field}.");
                }
            }
            foreach (['source_path', 'destination_path'] as $field) {
                $path = str_replace('\\', '/', $dependency[$field]);
                if (str_starts_with($path, '/') || ($field === 'source_path' && in_array('..', explode('/', $path), true)) || str_contains($path, '*') || str_contains($path, '?')) {
                    throw new \LogicException("Unsafe artifact dependency policy path: {$field}.");
                }
            }

            $destination = $dependency['destination_path'];
            if (isset($seenDestinations[$destination])) {
                throw new \LogicException("Duplicate artifact dependency policy destination path: {$destination}.");
            }
            $seenDestinations[$destination] = true;
        }
    }
}
