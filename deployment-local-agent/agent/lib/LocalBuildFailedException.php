<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (full local build + artifact pipeline): carries one of
 * PublishJob::AGENT_REPORTABLE_FAILURE_CODES (Hub-side) alongside the
 * human-readable message, so ArtifactUploader/agent.php can report the
 * exact stage that failed rather than a generic "publish failed".
 */
final class LocalBuildFailedException extends \RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
