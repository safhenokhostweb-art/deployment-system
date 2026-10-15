<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One Sentinel observation. Ordinary model updates/deletes are blocked;
 * this is not DB-level tamper resistance, and deployment retention/FK
 * cascade semantics remain governed separately.
 */
class SentinelVerificationRun extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Completed Sentinel verification runs cannot be updated through model operations.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Completed Sentinel verification runs cannot be deleted through model operations.');
        });
    }

    protected function casts(): array
    {
        return ['evidence' => 'array', 'checks' => 'array', 'reasons' => 'array', 'collected_at' => 'datetime'];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayStatus(): string
    {
        $freshnessSeconds = max(0, (int) config('deployment.runtime_snapshot_freshness_seconds', 300));
        if ($this->collected_at === null || $this->collected_at->lt(now()->subSeconds($freshnessSeconds))) {
            return 'STALE';
        }

        return match ($this->verdict) {
            'VERIFIED' => 'PASS',
            'FAILED' => 'FAIL',
            default => 'INCONCLUSIVE',
        };
    }
}
