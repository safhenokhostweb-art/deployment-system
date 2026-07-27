<?php

namespace App\Services\Bootstrap;

use RuntimeException;

/**
 * Every message thrown here is written to be shown directly to the
 * operator running `php artisan deployment:bootstrap` — always Persian,
 * always specific about which check failed, never a secret value.
 */
class DeploymentBootstrapException extends RuntimeException
{
}
