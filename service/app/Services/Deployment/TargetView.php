<?php

namespace App\Services\Deployment;

/**
 * Plain, non-Eloquent DTO returned by DeploymentTargetResolver — the same
 * shape whether it wraps a real DeploymentTarget row or is synthesized on
 * the fly for a legacy Project. Deliberately not a lightweight Eloquent
 * model: it must be structurally impossible to accidentally ->save() a
 * synthesized view into the database (see
 * docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md §10).
 */
final class TargetView
{
    public function __construct(
        public readonly ?int $deploymentTargetId,
        public readonly int $serverId,
        public readonly ?int $agentId,
        public readonly string $targetKey,
        public readonly bool $isSynthesized,
    ) {
    }
}
