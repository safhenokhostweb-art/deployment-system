<?php

namespace App\Services\Deployment\Artifact;

/**
 * Carries a short, machine-readable `reason` (checksum_mismatch|
 * corrupt_zip|empty_archive|missing_vendor|missing_build) alongside the
 * human message, so LocalAgentArtifactController's response lets the
 * Local Agent's ArtifactUploader report a specific
 * PublishJob::FAILURE_CODE_ARTIFACT_MISSING_VENDOR/_BUILD instead of a
 * generic "upload failed" whenever this is what actually happened.
 */
class ArtifactValidationException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
