<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

use InvalidArgumentException;
use JsonSerializable;

/** Pure failure/cleanup model; all execution gates are closed without a real observer. */
final class MysqlLifecycle implements JsonSerializable
{
    use MysqlContractObject;

    private const ACCESSIBLE_FIELDS = ['state', 'originalFailure', 'cleanupFailure'];

    private function __construct(MysqlLifecycleState $state, ?MysqlFailureCategory $originalFailure = null,
        ?MysqlFailureCategory $cleanupFailure = null, ?self $previous = null)
    {
        if (in_array($state, [MysqlLifecycleState::INITIALIZING, MysqlLifecycleState::BOOTSTRAPPING_CREDENTIALS,
            MysqlLifecycleState::STARTING, MysqlLifecycleState::READY, MysqlLifecycleState::MIGRATING], true)) {
            throw new InvalidArgumentException('proof_mysql_trusted_observer_unavailable');
        }
        if (($state === MysqlLifecycleState::PREFLIGHT && ($originalFailure !== null || $cleanupFailure !== null))
            || ($state === MysqlLifecycleState::FAILED && $originalFailure === null)
            || ($state === MysqlLifecycleState::CLEANUP_FAILED && $cleanupFailure !== MysqlFailureCategory::CLEANUP_FAILED)
            || ($state !== MysqlLifecycleState::CLEANUP_FAILED && $cleanupFailure !== null)
            || $originalFailure === MysqlFailureCategory::CLEANUP_FAILED) {
            throw new InvalidArgumentException('proof_mysql_transition_rejected');
        }
        if ($state === MysqlLifecycleState::PREFLIGHT) {
            if ($previous !== null) { throw new InvalidArgumentException('proof_mysql_transition_rejected'); }
        } else {
            if ($previous === null) { throw new InvalidArgumentException('proof_mysql_transition_rejected'); }
            $validPrevious = match ($state) {
                MysqlLifecycleState::FAILED => [MysqlLifecycleState::PREFLIGHT, MysqlLifecycleState::FAILED],
                MysqlLifecycleState::STOPPING => [MysqlLifecycleState::FAILED],
                MysqlLifecycleState::STOPPED, MysqlLifecycleState::CLEANUP_FAILED => [MysqlLifecycleState::STOPPING],
                default => [],
            };
            if (!in_array($previous->state, $validPrevious, true)
                || ($previous->originalFailure !== null && $originalFailure !== $previous->originalFailure)
                || ($state !== MysqlLifecycleState::FAILED && $originalFailure !== $previous->originalFailure)) {
                throw new InvalidArgumentException('proof_mysql_transition_rejected');
            }
        }
        $this->remember(['state' => $state, 'originalFailure' => $originalFailure, 'cleanupFailure' => $cleanupFailure]);
    }

    public static function preflight(): self { return new self(MysqlLifecycleState::PREFLIGHT); }

    public function transition(MysqlLifecycleState $next, ?MysqlSecurityEvidence $evidence = null): self
    {
        $allowed = match ($this->state) {
            MysqlLifecycleState::PREFLIGHT => [MysqlLifecycleState::INITIALIZING],
            MysqlLifecycleState::FAILED => [MysqlLifecycleState::STOPPING],
            MysqlLifecycleState::STOPPING => [MysqlLifecycleState::STOPPED],
            default => [],
        };
        if (!in_array($next, $allowed, true)) { throw new InvalidArgumentException('proof_mysql_transition_rejected'); }
        if ($next === MysqlLifecycleState::INITIALIZING) {
            throw new InvalidArgumentException('proof_mysql_trusted_observer_unavailable');
        }
        return new self($next, $this->originalFailure, $this->cleanupFailure, $this);
    }

    public function fail(MysqlFailureCategory $category): self
    {
        if (!in_array($this->state, [MysqlLifecycleState::PREFLIGHT, MysqlLifecycleState::FAILED], true)
            || $category === MysqlFailureCategory::CLEANUP_FAILED) {
            throw new InvalidArgumentException('proof_mysql_transition_rejected');
        }
        return new self(MysqlLifecycleState::FAILED, $this->originalFailure ?? $category, null, $this);
    }

    public function cleanupFailed(): self
    {
        if ($this->state !== MysqlLifecycleState::STOPPING) { throw new InvalidArgumentException('proof_mysql_transition_rejected'); }
        return new self(MysqlLifecycleState::CLEANUP_FAILED, $this->originalFailure, MysqlFailureCategory::CLEANUP_FAILED, $this);
    }

    public function hasFailed(): bool { return $this->originalFailure !== null || $this->cleanupFailure !== null; }

    public function jsonSerialize(): array
    {
        $data = $this->contractState();
        if (array_keys($data) !== ['state', 'originalFailure', 'cleanupFailure']
            || !(($data['state'] ?? null) instanceof MysqlLifecycleState)
            || ($data['originalFailure'] !== null && !($data['originalFailure'] instanceof MysqlFailureCategory))
            || ($data['cleanupFailure'] !== null && !($data['cleanupFailure'] instanceof MysqlFailureCategory))
            || !in_array($data['state'], [MysqlLifecycleState::PREFLIGHT, MysqlLifecycleState::FAILED,
                MysqlLifecycleState::STOPPING, MysqlLifecycleState::STOPPED, MysqlLifecycleState::CLEANUP_FAILED], true)) {
            throw new InvalidArgumentException('proof_mysql_evidence_rejected');
        }
        if (($data['state'] === MysqlLifecycleState::PREFLIGHT
                && ($data['originalFailure'] !== null || $data['cleanupFailure'] !== null))
            || ($data['state'] !== MysqlLifecycleState::PREFLIGHT && $data['originalFailure'] === null)
            || $data['originalFailure'] === MysqlFailureCategory::CLEANUP_FAILED
            || ($data['state'] === MysqlLifecycleState::CLEANUP_FAILED
                && $data['cleanupFailure'] !== MysqlFailureCategory::CLEANUP_FAILED)
            || ($data['state'] !== MysqlLifecycleState::CLEANUP_FAILED && $data['cleanupFailure'] !== null)) {
            throw new InvalidArgumentException('proof_mysql_evidence_rejected');
        }
        return ['lifecycle_state' => $data['state']->value, 'failure_category' => $data['originalFailure']?->value,
            'cleanup_failure_category' => $data['cleanupFailure']?->value];
    }
}
