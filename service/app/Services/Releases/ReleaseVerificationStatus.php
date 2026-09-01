<?php

namespace App\Services\Releases;

use App\Models\Release;

final class ReleaseVerificationStatus
{
    /** @return array{code:string,label:string} */
    public function for(?Release $release): array
    {
        $metadata = $release?->metadata;
        if (! is_array($metadata) || ! is_array($metadata['build_result'] ?? null)) {
            return $this->state('not_available', 'Not available');
        }

        $buildResult = $metadata['build_result'];
        if (($buildResult['required'] ?? null) === false) {
            return $this->state('not_required', 'Not required');
        }
        if (($buildResult['required'] ?? null) !== true) {
            return $this->state('not_available', 'Not available');
        }

        $status = is_string($buildResult['status'] ?? null)
            ? strtolower($buildResult['status'])
            : null;

        return match ($status) {
            'passed', 'succeeded', 'success' => $this->state('passed', 'Passed'),
            'failed', 'failure', 'errored', 'error' => $this->state('failed', 'Failed'),
            'pending', 'queued', 'running', 'building' => $this->state('pending', 'Pending'),
            default => $this->state('not_available', 'Not available'),
        };
    }

    /** @return array{code:string,label:string} */
    private function state(string $code, string $label): array
    {
        return compact('code', 'label');
    }
}
