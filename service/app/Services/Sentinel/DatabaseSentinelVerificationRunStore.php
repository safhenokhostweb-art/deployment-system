<?php

namespace App\Services\Sentinel;

use App\Models\SentinelVerificationRun;

final class DatabaseSentinelVerificationRunStore implements SentinelVerificationRunStore
{
    public function record(array $attributes): SentinelVerificationRun
    {
        return SentinelVerificationRun::query()->forceCreate($attributes);
    }
}
