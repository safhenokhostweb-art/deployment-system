<?php

namespace App\Services\Sentinel;

use RuntimeException;

/**
 * Thrown by a read-only source adapter when it cannot answer:
 * timeout, connection refused/reset, unreadable storage, transport
 * error.
 *
 * The collector treats it exactly like a null response — the affected
 * link becomes `unknown` and the verdict degrades to UNKNOWN. Its
 * message MUST stay free of credentials and raw source payloads: the
 * collector never propagates adapter messages into evidence, results
 * or reasons.
 */
final class SentinelSourceUnavailableException extends RuntimeException
{
}
