<?php

namespace App\Services\Sentinel\Readiness;

use RuntimeException;

/**
 * StoragePolicyOverflowException — thrown when the documented storage
 * policy values cannot be summed without exceeding PHP_INT_MAX.
 *
 * The effective floor is a policy value compared against a measured
 * byte count. An integer overflow would wrap it to a small or
 * negative number, and any measurement — including a host with almost
 * no free space — would then satisfy the floor and PASS.
 *
 * This is a fail-closed exception, not a clamp: the policy itself is
 * unsatisfiable and must be corrected by whoever owns the policy
 * values. Silently clamping would hide an impossible policy behind a
 * number that looks plausible.
 */
final class StoragePolicyOverflowException extends RuntimeException
{
}
