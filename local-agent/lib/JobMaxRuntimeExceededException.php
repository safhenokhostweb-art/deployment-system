<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Thrown by agent.php's assertWithinMaxRuntime() — a hard, whole-job
 * ceiling checked between steps (never mid-command), independent of any
 * single git command's own per-purpose timeout. Always reported to the
 * Hub with failure_code=process_timeout, same as a single command timing
 * out via GitCommandException.
 */
final class JobMaxRuntimeExceededException extends \RuntimeException
{
}
