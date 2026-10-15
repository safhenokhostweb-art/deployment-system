<?php

namespace App\Services\Sentinel;

use App\Models\Deployment;
use Illuminate\Support\Facades\Storage;

/** Re-hashes the exact private artifact bytes without changing them. */
final class HubArtifactChecksumReader implements ArtifactChecksumReader
{
    public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelArtifactObservation
    {
        if ($expectation->artifactId === null || $expectation->deploymentPublicId === null) {
            return null;
        }

        $deployment = Deployment::query()
            ->where('public_id', $expectation->deploymentPublicId)
            ->whereHas('release', fn ($query) => $query->where('public_id', $target->releaseId))
            ->with('artifact')
            ->first();
        $artifact = $deployment?->artifact;

        if ($artifact === null || ! $artifact->isAvailable()
            || ! hash_equals($expectation->artifactId, $artifact->public_id)
            || ! hash_equals((string) $artifact->checksum_sha256, (string) $expectation->artifactChecksumSha256)
            || ! is_string($artifact->disk_path) || $artifact->disk_path === '') {
            return null;
        }

        $stream = Storage::disk('artifacts')->readStream($artifact->disk_path);
        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            $bytesRead = hash_update_stream($context, $stream);
            if (! is_int($bytesRead) || $bytesRead !== (int) $artifact->size_bytes) {
                return null;
            }
            return new SentinelArtifactObservation(hash_final($context));
        } finally {
            fclose($stream);
        }
    }
}
