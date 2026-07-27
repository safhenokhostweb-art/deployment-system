<?php

namespace App\Services\Bootstrap;

use App\Models\Agent;
use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;

/**
 * Everything the Artisan command needs to print/act on after a bootstrap
 * run — plaintext secrets are only ever non-null the one time they were
 * just generated (a found/existing Server, Agent, or Project never
 * repopulates them here, by design: see DeploymentBootstrapService's own
 * docblock on why re-runs must never rotate an existing secret).
 */
final class DeploymentBootstrapReport
{
    /**
     * @param array<int, string> $notices Human-readable, Persian, non-fatal
     *   observations (e.g. a config value that differs from what's already
     *   stored) — never a reason to fail, always worth telling the operator.
     * @param array<int, string> $configuredDifferences Fields that existed
     *   with a different value and were synced to match config.env.
     */
    public function __construct(
        public readonly Server $server,
        public readonly bool $serverWasCreated,
        public readonly ?string $plainAgentToken,
        public readonly ?string $plainSigningSecret,
        public readonly Agent $agent,
        public readonly bool $agentWasCreated,
        public readonly Project $project,
        public readonly bool $projectWasCreated,
        public readonly ?string $plainWebhookSecret,
        public readonly string $webhookUrl,
        public readonly Environment $environment,
        public readonly bool $environmentWasCreated,
        public readonly DeploymentTarget $target,
        public readonly bool $targetWasCreated,
        public readonly bool $webhookAutoCreated,
        public readonly ?string $webhookAutoCreateError,
        public readonly array $notices = [],
        public readonly array $configuredDifferences = [],
    ) {
    }
}
