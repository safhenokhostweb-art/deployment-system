<?php

namespace App\Console\Commands;

use App\Models\AgentRequestNonce;
use Illuminate\Console\Command;

/**
 * Nonces only need to live as long as the request-signing timestamp window
 * (VerifyAgentRequestSignature::MAX_CLOCK_SKEW_SECONDS, 300s) could still
 * accept them — kept longer here purely as a safety margin. Without this,
 * agent_request_nonces grows by one row per authenticated agent call,
 * forever.
 */
class PruneAgentNonces extends Command
{
    protected $signature = 'agent-nonces:prune';

    protected $description = 'Delete replay-protection nonces older than the retention window';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('deployment.nonce_retention_hours'));

        $deleted = AgentRequestNonce::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} agent request nonce(s) older than {$cutoff}.");

        return self::SUCCESS;
    }
}
