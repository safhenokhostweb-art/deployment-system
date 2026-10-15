<?php

namespace App\Services\Sentinel\Readiness;

/**
 * HostReadinessReasonCodes — the stable, machine-readable reason codes
 * emitted by the readiness checkers.
 *
 * These exist as a single registry so that:
 *
 *  - a caller can match a blocker without parsing human prose,
 *  - a test can assert on a stable identifier,
 *  - no reason code can be invented at runtime and smuggled past a
 *    consumer that allowlists these constants.
 *
 * No value here conveys authorization of any kind.
 */
final class HostReadinessReasonCodes
{
    public const STORAGE_ROOT_MISSING = 'STORAGE_ROOT_MISSING';
    public const STORAGE_ROOT_PATH_MISSING = 'STORAGE_ROOT_PATH_MISSING';
    public const STORAGE_ROOT_NOT_CREATED = 'storage_root_not_created';
    public const STORAGE_FIXED_VOLUME_REQUIRED = 'storage_fixed_volume_required';
    public const STORAGE_NTFS_REQUIRED = 'storage_ntfs_required';
    public const STORAGE_CAPACITY_BELOW_EFFECTIVE_FLOOR = 'storage_capacity_below_effective_floor';
    public const STORAGE_ROOT_IS_REPARSE_POINT = 'storage_root_is_reparse_point';
    public const STORAGE_CANONICAL_PATH_UNAVAILABLE = 'storage_canonical_path_unavailable';
    public const STORAGE_OBSERVATION_UNAVAILABLE = 'storage_observation_unavailable';

    public const HYPERV_MODULE_NOT_IMPORTABLE = 'hyperv_module_not_importable';
    public const HYPERV_VMMS_NOT_INSTALLED = 'hyperv_vmms_not_installed';
    public const HYPERV_VMMS_ACCESS_DENIED = 'hyperv_vmms_access_denied';
    public const HYPERV_GROUP_NOT_MEMBER = 'hyperv_group_not_member';
    public const HYPERV_PROCESS_NOT_ELEVATED = 'hyperv_process_not_elevated';
    public const HYPERV_OBSERVATION_UNAVAILABLE = 'hyperv_observation_unavailable';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [
            self::STORAGE_ROOT_MISSING,
            self::STORAGE_ROOT_PATH_MISSING,
            self::STORAGE_ROOT_NOT_CREATED,
            self::STORAGE_FIXED_VOLUME_REQUIRED,
            self::STORAGE_NTFS_REQUIRED,
            self::STORAGE_CAPACITY_BELOW_EFFECTIVE_FLOOR,
            self::STORAGE_ROOT_IS_REPARSE_POINT,
            self::STORAGE_CANONICAL_PATH_UNAVAILABLE,
            self::STORAGE_OBSERVATION_UNAVAILABLE,
            self::HYPERV_MODULE_NOT_IMPORTABLE,
            self::HYPERV_VMMS_NOT_INSTALLED,
            self::HYPERV_VMMS_ACCESS_DENIED,
            self::HYPERV_GROUP_NOT_MEMBER,
            self::HYPERV_PROCESS_NOT_ELEVATED,
            self::HYPERV_OBSERVATION_UNAVAILABLE,
        ];
    }
}
