<?php

namespace App\Support\Deployment;

final class ArtifactDependencyPolicy
{
    public static function required(string $projectKey): bool
    {
        return (bool) (self::policy($projectKey)['required'] ?? false);
    }

    /** @return array<string, mixed> */
    public static function contract(string $projectKey): array
    {
        $policy = self::policy($projectKey);
        if (($policy['required'] ?? false) !== true) {
            return [];
        }

        $dependencies = array_values($policy['dependencies'] ?? []);
        self::assertValid($dependencies);

        return ['version' => 2, 'required' => true, 'dependencies' => $dependencies];
    }

    /** @return array<int, array<string, string>> */
    public static function allowlist(string $projectKey): array
    {
        return array_map(static function (array $dependency): array {
            unset($dependency['commit_sha']);
            return $dependency;
        }, self::contract($projectKey)['dependencies'] ?? []);
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
            foreach (['project_id', 'repository_mapping', 'branch', 'commit_sha', 'package_name', 'source_path', 'destination_path'] as $field) {
                if (!is_string($dependency[$field] ?? null) || trim($dependency[$field]) === '') {
                    throw new \LogicException("Invalid artifact dependency policy field: {$field}.");
                }
            }
            if (preg_match('/^[0-9a-f]{40}$/', $dependency['commit_sha']) !== 1) {
                throw new \LogicException('Artifact dependency policy requires a full lowercase Git SHA.');
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
