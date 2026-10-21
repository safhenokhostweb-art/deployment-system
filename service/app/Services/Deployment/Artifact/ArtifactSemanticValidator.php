<?php

namespace App\Services\Deployment\Artifact;

use App\Models\Artifact;
use Illuminate\Support\Facades\Storage;

final class ArtifactSemanticValidator
{
    public function validate(Artifact $artifact): void
    {
        $zip = new \ZipArchive;
        if ($zip->open(Storage::disk('artifacts')->path($artifact->disk_path)) !== true) {
            throw new ArtifactValidationException('corrupt_zip', 'The uploaded artifact could not be opened as a zip archive.');
        }

        try {
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                    throw new ArtifactValidationException('unsafe_archive_path', 'The artifact contains an unsafe archive path.');
                }
                $entries[$name] = true;
            }

            foreach ($artifact->required_paths ?? [] as $requiredPath) {
                $path = trim((string) $requiredPath, '/');
                $present = isset($entries[$path]) || isset($entries[$path.'/']);
                if (! $present) {
                    foreach ($entries as $entry => $_) {
                        if (str_starts_with($entry, $path.'/')) {
                            $present = true;
                            break;
                        }
                    }
                }
                if (! $present) {
                    throw new ArtifactValidationException('missing_required_path', "Artifact is missing required path '{$path}'.");
                }
            }

            $rawManifest = $zip->getFromName('.deployment/release-manifest.json');
            if (! is_string($rawManifest)) {
                throw new ArtifactValidationException('missing_release_manifest', 'Artifact is missing .deployment/release-manifest.json.');
            }
            $manifest = json_decode($rawManifest, true);
            if (! is_array($manifest)
                || ($manifest['project_key'] ?? null) !== $artifact->project->project_key
                || ($manifest['commit_sha'] ?? null) !== $artifact->commit_hash
                || ($manifest['branch'] ?? null) !== $artifact->branch
                || ($manifest['status'] ?? null) !== 'pass') {
                throw new ArtifactValidationException('release_manifest_mismatch', 'Artifact release manifest provenance does not match the assigned project, commit, and branch.');
            }

            $this->validatePersistentSourceBoundary($artifact, $manifest);

            // When the artifact declares a file_manifest_hash, the ZIP must
            // contain a structurally valid .deployment/file-manifest.json.
            // The hash itself is verified by ArtifactService::finalizeUpload();
            // here we enforce that the manifest exists and is well-formed.
            if ($artifact->file_manifest_hash !== null) {
                $rawFileManifest = $zip->getFromName('.deployment/file-manifest.json');
                if (! is_string($rawFileManifest)) {
                    throw new ArtifactValidationException('missing_file_manifest', 'Artifact declares a file_manifest_hash but the ZIP contains no .deployment/file-manifest.json.');
                }
                $fileManifest = json_decode($rawFileManifest, true);
                if (! is_array($fileManifest)
                    || ! isset($fileManifest['format'])
                    || ! isset($fileManifest['entries'])
                    || ! is_array($fileManifest['entries'])) {
                    throw new ArtifactValidationException('file_manifest_invalid', 'Artifact .deployment/file-manifest.json is malformed or missing required structure.');
                }
            }

            if (isset($entries['composer.json'])) {
                foreach (['composer.lock', 'vendor/autoload.php', 'artisan', 'bootstrap/app.php', 'public/index.php'] as $required) {
                    if (! isset($entries[$required])) {
                        throw new ArtifactValidationException('missing_laravel_runtime', "Composer/Laravel artifact is missing '{$required}'.");
                    }
                }
            }
            $hasVite = isset($entries['vite.config.js']) || isset($entries['vite.config.ts']);
            if ($hasVite && ! isset($entries['public/build/manifest.json'])) {
                throw new ArtifactValidationException('missing_build', 'Vite artifact is missing public/build/manifest.json.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * New manifests declare the exact tracked source files captured before
     * dependency/build materialization. Reject a persistence parent that
     * would hide any of those files. Manifests created before this field was
     * introduced remain compatible and are guarded later by the Server Agent
     * when the inventory is available.
     */
    private function validatePersistentSourceBoundary(Artifact $artifact, array $manifest): void
    {
        $persistentPaths = $artifact->project->artifact_persistent_paths ?? [];
        if ($persistentPaths === [] || ! array_key_exists('source_paths', $manifest)) {
            return;
        }
        if (! is_array($manifest['source_paths'])) {
            throw new ArtifactValidationException('persistent_source_contract_invalid', 'Artifact source_paths must be an array.');
        }

        $sourcePaths = [];
        foreach ($manifest['source_paths'] as $path) {
            $normalized = is_string($path) ? trim(str_replace('\\', '/', $path), '/') : '';
            if ($normalized === ''
                || str_contains((string) $path, "\0")
                || str_starts_with((string) $path, '/')
                || str_starts_with((string) $path, '\\')
                || preg_match('/^[A-Za-z]:[\\\\\/]/', (string) $path)
                || preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
                throw new ArtifactValidationException('persistent_source_contract_invalid', 'Artifact source_paths contains an unsafe path.');
            }
            $sourcePaths[] = $normalized;
        }

        $releasePaths = [];
        foreach ($persistentPaths as $releaseKey => $targetPath) {
            $releasePaths[] = trim(str_replace('\\', '/', is_string($releaseKey) ? $releaseKey : $targetPath), '/');
        }
        for ($i = 0; $i < count($releasePaths); $i++) {
            for ($j = $i + 1; $j < count($releasePaths); $j++) {
                $left = $releasePaths[$i];
                $right = $releasePaths[$j];
                if ($left === $right
                    || str_starts_with($left . '/', $right . '/')
                    || str_starts_with($right . '/', $left . '/')) {
                    throw new ArtifactValidationException('persistent_path_overlap', "Persistent release paths '{$left}' and '{$right}' overlap.");
                }
            }
        }

        foreach ($persistentPaths as $releaseKey => $targetPath) {
            $persistent = trim(str_replace('\\', '/', is_string($releaseKey) ? $releaseKey : $targetPath), '/');
            foreach ($sourcePaths as $source) {
                if ($source === $persistent) {
                    throw new ArtifactValidationException(
                        'persistent_source_overlap',
                        "Persistent path '{$persistent}' would replace tracked artifact source '{$source}'.",
                    );
                }
                // Descendants prove this is a source-bearing parent directory
                // and are rejected, except empty-directory markers which do
                // not constitute application source.
                if (! str_starts_with($source, $persistent.'/')) {
                    continue;
                }
                if (in_array(basename($source), ['.gitignore', '.gitkeep'], true)) {
                    continue;
                }

                throw new ArtifactValidationException(
                    'persistent_source_overlap',
                    "Persistent path '{$persistent}' would hide tracked artifact source '{$source}'.",
                );
            }
        }
    }
}
