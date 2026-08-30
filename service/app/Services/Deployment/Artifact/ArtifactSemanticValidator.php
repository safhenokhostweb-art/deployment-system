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
}
