<?php

namespace App\Services\Releases;

use App\Models\Project;
use Illuminate\Validation\ValidationException;

final class ReleaseMetadataValidator
{
    /** @return array<string, mixed> */
    public function validate(Project $project, array $metadata): array
    {
        $required = [
            'project_key', 'commit_sha', 'base_sha', 'branch', 'changed_files',
            'migration_files', 'test_results', 'build_result', 'risk_flags',
            'created_timestamp', 'release_ready',
        ];
        foreach ($required as $field) {
            if (! array_key_exists($field, $metadata)) {
                throw ValidationException::withMessages([$field => "Release metadata is missing {$field}."]);
            }
        }
        if ($metadata['project_key'] !== $project->project_key || $metadata['branch'] !== $project->branch) {
            throw ValidationException::withMessages(['release' => 'Release metadata project or production branch mismatch.']);
        }
        foreach (['commit_sha', 'base_sha'] as $field) {
            if (! is_string($metadata[$field]) || preg_match('/^[0-9a-f]{40}$/', $metadata[$field]) !== 1) {
                throw ValidationException::withMessages([$field => "{$field} must be a lowercase 40-character Git SHA."]);
            }
        }
        foreach (['changed_files', 'migration_files', 'test_results', 'risk_flags'] as $field) {
            if (! is_array($metadata[$field])) {
                throw ValidationException::withMessages([$field => "{$field} must be an array."]);
            }
        }
        if (! is_array($metadata['build_result']) || ! is_bool($metadata['release_ready'])) {
            throw ValidationException::withMessages(['release' => 'Release build_result or readiness type is invalid.']);
        }
        foreach (array_merge($metadata['changed_files'], $metadata['migration_files']) as $path) {
            if (! is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
                throw ValidationException::withMessages(['changed_files' => 'Release paths must be safe repository-relative paths.']);
            }
        }
        if (strtotime((string) $metadata['created_timestamp']) === false) {
            throw ValidationException::withMessages(['created_timestamp' => 'Release timestamp is invalid.']);
        }
        return $metadata;
    }
}
