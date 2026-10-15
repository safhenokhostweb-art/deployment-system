<?php

namespace App\Services\Sentinel;

interface SentinelEvidenceCollection
{
    public function collect(SentinelCollectionTarget $target): SentinelCollectionOutcome;
}
