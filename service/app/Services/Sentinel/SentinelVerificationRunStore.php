<?php

namespace App\Services\Sentinel;

use App\Models\SentinelVerificationRun;

interface SentinelVerificationRunStore
{
    /** @param array<string, mixed> $attributes */
    public function record(array $attributes): SentinelVerificationRun;
}
