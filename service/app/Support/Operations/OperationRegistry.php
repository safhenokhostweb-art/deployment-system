<?php

namespace App\Support\Operations;

/**
 * Phase 4-K Task 4: the Allowed Operations Registry. A user can only ever
 * pick a `type` key from this fixed list — never type in or influence any
 * part of the actual shell command string. The Hub validates the type
 * against this registry before ever queuing an OperationJob; the Agent
 * independently validates it again against its own copy (agent/lib/
 * OperationRegistry.php — a different runtime, no shared autoload, so it
 * can't literally reuse this class, only mirror its content) before ever
 * executing anything. Neither side ever receives or forwards a raw
 * command string from the network — only this `type` key travels over
 * the wire (see AgentOperationController), which is exactly what prevents
 * command injection by construction rather than by sanitization.
 */
class OperationRegistry
{
    public const CACHE_CLEAR = 'cache_clear';
    public const CONFIG_CACHE = 'config_cache';
    public const ROUTE_CACHE = 'route_cache';
    public const QUEUE_RESTART = 'queue_restart';
    public const DISK_CHECK = 'disk_check';
    public const SERVICE_STATUS = 'service_status';
    public const SYSTEM_HEALTH = 'system_health';

    public const GROUP_LARAVEL = 'laravel';
    public const GROUP_SERVER = 'server';

    /**
     * @return array<string, array{label: string, group: string, requires_project: bool}>
     */
    public static function definitions(): array
    {
        return [
            self::CACHE_CLEAR => ['label' => 'Clear cache', 'group' => self::GROUP_LARAVEL, 'requires_project' => true],
            self::CONFIG_CACHE => ['label' => 'Cache config', 'group' => self::GROUP_LARAVEL, 'requires_project' => true],
            self::ROUTE_CACHE => ['label' => 'Cache routes', 'group' => self::GROUP_LARAVEL, 'requires_project' => true],
            self::QUEUE_RESTART => ['label' => 'Restart queue', 'group' => self::GROUP_LARAVEL, 'requires_project' => true],
            self::DISK_CHECK => ['label' => 'Check disk', 'group' => self::GROUP_SERVER, 'requires_project' => false],
            self::SERVICE_STATUS => ['label' => 'Service status', 'group' => self::GROUP_SERVER, 'requires_project' => false],
            self::SYSTEM_HEALTH => ['label' => 'System health', 'group' => self::GROUP_SERVER, 'requires_project' => false],
        ];
    }

    public static function isAllowed(string $type): bool
    {
        return array_key_exists($type, self::definitions());
    }

    public static function requiresProject(string $type): bool
    {
        return self::definitions()[$type]['requires_project'] ?? false;
    }

    /**
     * @return array<int, string>
     */
    public static function laravelGroupTypes(): array
    {
        return array_keys(array_filter(self::definitions(), fn (array $def) => $def['group'] === self::GROUP_LARAVEL));
    }

    /**
     * @return array<int, string>
     */
    public static function serverGroupTypes(): array
    {
        return array_keys(array_filter(self::definitions(), fn (array $def) => $def['group'] === self::GROUP_SERVER));
    }
}
