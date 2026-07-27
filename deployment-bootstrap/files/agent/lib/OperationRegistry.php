<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Phase 4-K: the Agent's own copy of the Allowed Operations Registry
 * (service/app/Support/Operations/OperationRegistry.php) — a different
 * runtime with no shared autoload, so it can't literally reuse that
 * class, only mirror its `type` keys and their meaning. This is the
 * ONLY place an operation `type` maps to an actual shell command string;
 * the Hub never sends a command, only a type, so a compromised or buggy
 * Hub can still never make this agent run something outside this list
 * (defense in depth — Task 12).
 */
final class OperationRegistry
{
    private const COMMANDS = [
        // Laravel group — need {working_directory}/{php_binary} from the
        // project config the same way a deployment pipeline step does.
        'cache_clear' => '{php_binary} artisan cache:clear',
        'config_cache' => '{php_binary} artisan config:cache',
        'route_cache' => '{php_binary} artisan route:cache',
        'queue_restart' => '{php_binary} artisan queue:restart',
        // Server group — no project context needed.
        'disk_check' => 'df -h',
        'service_status' => '(systemctl status --no-pager 2>&1) || (service --status-all 2>&1)',
        'system_health' => 'uptime; echo ---; free -m; echo ---; df -h',
    ];

    private const REQUIRES_PROJECT = ['cache_clear', 'config_cache', 'route_cache', 'queue_restart'];

    public static function isAllowed(string $type): bool
    {
        return array_key_exists($type, self::COMMANDS);
    }

    public static function requiresProject(string $type): bool
    {
        return in_array($type, self::REQUIRES_PROJECT, true);
    }

    public static function commandFor(string $type): ?string
    {
        return self::COMMANDS[$type] ?? null;
    }
}
