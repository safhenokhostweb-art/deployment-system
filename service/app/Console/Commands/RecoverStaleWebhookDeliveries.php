<?php

namespace App\Console\Commands;

use App\Services\Webhooks\WebhookDeliveryOwnershipService;
use Illuminate\Console\Command;

/**
 * Root-cause fix: a WebhookDelivery stuck at PROCESSING after a hard
 * worker death (kill -9, OOM, host crash) has no exception path to
 * release it -- nothing transitions it back to PENDING on its own. Native
 * queue-level retry (a database/redis retry_after reservation timeout) or
 * a fresh GitHub redelivery CAN both recover it, but neither is
 * guaranteed: retry_after only fires if some worker is still alive to
 * poll again, and GitHub redelivery depends on GitHub's own UI/webhook
 * settings and an operator noticing. This command is the deterministic,
 * application-level guarantee that closes that gap -- the same
 * thin-command-over-a-service pattern as deployments:recover-stale for
 * the separate PublishJob domain (see StalePublishJobRecoveryService's
 * own docblock for why that command doesn't overlap this one either).
 */
class RecoverStaleWebhookDeliveries extends Command
{
    protected $signature = 'webhooks:recover-stale {--dry-run : Report what would be reclaimed without changing anything}';

    protected $description = 'Requeue a WebhookDelivery stuck PROCESSING past its lease with no exception ever thrown to release it';

    public function handle(WebhookDeliveryOwnershipService $deliveries): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $deliveries->reclaimExpiredProcessing($dryRun);

        $prefix = $dryRun ? '[dry-run] Would reclaim' : 'Reclaimed';

        $this->info(sprintf(
            '%s %d stale webhook delivery/deliveries checked: %d reclaimed, %d exhausted (permanently failed after %d attempts).',
            $prefix,
            $result['checked'],
            $result['reclaimed'],
            $result['exhausted'],
            WebhookDeliveryOwnershipService::MAX_RECLAIM_ATTEMPTS,
        ));

        return self::SUCCESS;
    }
}
