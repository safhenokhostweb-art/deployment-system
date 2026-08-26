<?php

namespace App\Support\Ui;

/**
 * Presentation metadata for operational statuses.
 *
 * This registry deliberately contains no workflow transitions or business
 * rules. Backend status values remain authoritative; the UI only asks this
 * class how a known value should be labelled and presented.
 */
final class StatusRegistry
{
    /** @var array<string, array<string, array{label: string, translation_key: string, level: string, icon: string, actionable: bool, terminal: bool}>> */
    private const DEFINITIONS = [
        'deployment' => [
            'pending_approval' => ['label' => 'Pending approval', 'translation_key' => 'status.pending_approval', 'level' => 'warning', 'icon' => 'clock', 'actionable' => true, 'terminal' => false],
            'queued' => ['label' => 'Queued', 'translation_key' => 'status.queued', 'level' => 'neutral', 'icon' => 'clock', 'actionable' => false, 'terminal' => false],
            'running' => ['label' => 'Running', 'translation_key' => 'status.running', 'level' => 'info', 'icon' => 'progress', 'actionable' => false, 'terminal' => false],
            'building' => ['label' => 'Building', 'translation_key' => 'status.building', 'level' => 'info', 'icon' => 'build', 'actionable' => false, 'terminal' => false],
            'migrating' => ['label' => 'Migrating', 'translation_key' => 'status.migrating', 'level' => 'warning', 'icon' => 'progress', 'actionable' => false, 'terminal' => false],
            'transferring' => ['label' => 'Transferring', 'translation_key' => 'status.transferring', 'level' => 'info', 'icon' => 'transfer', 'actionable' => false, 'terminal' => false],
            'activating' => ['label' => 'Activating', 'translation_key' => 'status.activating', 'level' => 'warning', 'icon' => 'activate', 'actionable' => false, 'terminal' => false],
            'verifying' => ['label' => 'Verifying', 'translation_key' => 'status.verifying', 'level' => 'info', 'icon' => 'verify', 'actionable' => false, 'terminal' => false],
            'succeeded' => ['label' => 'Succeeded', 'translation_key' => 'status.succeeded', 'level' => 'success', 'icon' => 'check', 'actionable' => false, 'terminal' => true],
            'failed' => ['label' => 'Failed', 'translation_key' => 'status.failed', 'level' => 'danger', 'icon' => 'error', 'actionable' => true, 'terminal' => true],
            'cancelled' => ['label' => 'Cancelled', 'translation_key' => 'status.cancelled', 'level' => 'neutral', 'icon' => 'neutral', 'actionable' => false, 'terminal' => true],
            'partial_success' => ['label' => 'Partial success', 'translation_key' => 'status.partial_success', 'level' => 'warning', 'icon' => 'health', 'actionable' => true, 'terminal' => true],
            'rolled_back' => ['label' => 'Rolled back', 'translation_key' => 'status.rolled_back', 'level' => 'neutral', 'icon' => 'rollback', 'actionable' => false, 'terminal' => true],
        ],
        'artifact' => [
            'uploading' => ['label' => 'Uploading', 'translation_key' => 'status.uploading', 'level' => 'info', 'icon' => 'upload', 'actionable' => false, 'terminal' => false],
            'available' => ['label' => 'Available', 'translation_key' => 'status.available', 'level' => 'success', 'icon' => 'package', 'actionable' => false, 'terminal' => true],
            'failed' => ['label' => 'Failed', 'translation_key' => 'status.failed', 'level' => 'danger', 'icon' => 'error', 'actionable' => true, 'terminal' => true],
            'expired' => ['label' => 'Expired', 'translation_key' => 'status.expired', 'level' => 'warning', 'icon' => 'clock', 'actionable' => true, 'terminal' => true],
            'missing' => ['label' => 'Missing', 'translation_key' => 'status.missing', 'level' => 'danger', 'icon' => 'missing', 'actionable' => true, 'terminal' => true],
        ],
        'agent' => [
            'online' => ['label' => 'Online', 'translation_key' => 'status.online', 'level' => 'success', 'icon' => 'connected', 'actionable' => false, 'terminal' => false],
            'offline' => ['label' => 'Offline', 'translation_key' => 'status.offline', 'level' => 'danger', 'icon' => 'disconnected', 'actionable' => true, 'terminal' => false],
            'pending' => ['label' => 'Pending', 'translation_key' => 'status.pending', 'level' => 'warning', 'icon' => 'clock', 'actionable' => true, 'terminal' => false],
            'outdated' => ['label' => 'Outdated', 'translation_key' => 'status.outdated', 'level' => 'warning', 'icon' => 'update', 'actionable' => true, 'terminal' => false],
            'unhealthy' => ['label' => 'Unhealthy', 'translation_key' => 'status.unhealthy', 'level' => 'danger', 'icon' => 'health', 'actionable' => true, 'terminal' => false],
        ],
        'health' => [
            'pending' => ['label' => 'Pending', 'translation_key' => 'status.pending', 'level' => 'info', 'icon' => 'clock', 'actionable' => false, 'terminal' => false],
            'verified' => ['label' => 'Verified', 'translation_key' => 'status.verified', 'level' => 'success', 'icon' => 'verified', 'actionable' => false, 'terminal' => true],
            'failed' => ['label' => 'Failed', 'translation_key' => 'status.failed', 'level' => 'danger', 'icon' => 'error', 'actionable' => true, 'terminal' => true],
            'not_configured' => ['label' => 'Not configured', 'translation_key' => 'status.not_configured', 'level' => 'neutral', 'icon' => 'neutral', 'actionable' => true, 'terminal' => true],
        ],
    ];

    /**
     * @return array{label: string, translation_key: string, level: string, icon: string, actionable: bool, terminal: bool}
     */
    public static function resolve(string $domain, string $status): array
    {
        return self::DEFINITIONS[$domain][$status] ?? [
            'label' => str($status)->replace('_', ' ')->headline()->toString(),
            'translation_key' => "status.{$status}",
            'level' => 'neutral',
            'icon' => 'neutral',
            'actionable' => false,
            'terminal' => false,
        ];
    }

    /** @return array<string, array{label: string, translation_key: string, level: string, icon: string, actionable: bool, terminal: bool}> */
    public static function forDomain(string $domain): array
    {
        return self::DEFINITIONS[$domain] ?? [];
    }

    /** @return list<string> */
    public static function domains(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
