<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Defense in depth for the `restart_service`/`verify_service_active`
 * recipe step types (mirrors OperationRegistry's role for Operations
 * Command Center commands): RecipeValidator only checks that a `service`
 * value on the Hub *looks like* a systemd unit name — it has no idea
 * which units actually exist on any given server, let alone which ones
 * are safe to restart. This agent-local, per-project allowlist (from
 * config.php, never sent by or visible to the Hub) is what a compromised
 * or buggy Hub still cannot get past: it can request a restart of
 * `sandogh-web.service`, never `ssh.service` or `sandogh-api.service`
 * from a recipe belonging to a different project.
 */
final class ServiceAllowlist
{
    /**
     * @param array<int, string> $allowedServices
     */
    public static function isAllowed(string $service, array $allowedServices): bool
    {
        return in_array($service, $allowedServices, true);
    }
}
