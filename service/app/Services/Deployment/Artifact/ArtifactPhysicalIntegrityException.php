<?php

namespace App\Services\Deployment\Artifact;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fail-closed deployment preflight failure for an Artifact whose database
 * metadata and physical storage object no longer agree.
 */
class ArtifactPhysicalIntegrityException extends HttpException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct(409, $message);
    }
}
