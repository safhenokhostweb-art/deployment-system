<?php

namespace App\Events;

use App\Models\Deployment;
use Illuminate\Foundation\Events\Dispatchable;

class DeploymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Deployment $deployment,
        public readonly ?string $message = null,
    ) {
    }
}
