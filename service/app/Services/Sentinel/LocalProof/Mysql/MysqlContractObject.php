<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

use InvalidArgumentException;
use JsonSerializable;
use WeakMap;

/**
 * In-memory construction guard, NOT an observer, receipt issuer or security sandbox.
 * State is outside instance properties so generic array casts cannot export paths.
 * Arbitrary privileged Reflection against private static storage is outside this
 * API boundary; untrusted Laravel must never share the observer's PHP process.
 */
trait MysqlContractObject
{
    private static ?WeakMap $contractStates = null;

    private function remember(array $state): void
    {
        self::$contractStates ??= new WeakMap();
        if (isset(self::$contractStates[$this])) {
            throw new InvalidArgumentException('proof_mysql_contract_mutation_rejected');
        }
        self::$contractStates[$this] = $state;
    }

    private function contractState(): array
    {
        if (self::$contractStates === null || !isset(self::$contractStates[$this])) {
            throw new InvalidArgumentException('proof_mysql_contract_uninitialized');
        }
        return self::$contractStates[$this];
    }

    final public function __get(string $name): mixed
    {
        $state = $this->contractState();
        if (!in_array($name, self::ACCESSIBLE_FIELDS, true) || !array_key_exists($name, $state)) {
            throw new InvalidArgumentException('proof_mysql_contract_field_rejected');
        }
        return $state[$name];
    }

    final public function __set(string $name, mixed $value): void
    {
        throw new InvalidArgumentException('proof_mysql_contract_mutation_rejected');
    }

    final public function __unset(string $name): void
    {
        throw new InvalidArgumentException('proof_mysql_contract_mutation_rejected');
    }

    final public function __clone(): void
    {
        throw new InvalidArgumentException('proof_mysql_contract_restoration_rejected');
    }

    final public function __serialize(): array
    {
        throw new InvalidArgumentException('proof_mysql_contract_restoration_rejected');
    }

    final public function __unserialize(array $data): void
    {
        throw new InvalidArgumentException('proof_mysql_contract_restoration_rejected');
    }

    final public function __wakeup(): void
    {
        throw new InvalidArgumentException('proof_mysql_contract_restoration_rejected');
    }

    public function jsonSerialize(): array
    {
        $this->contractState();
        return ['contract' => substr(strrchr(self::class, '\\'), 1), 'authorization' => 'UNAVAILABLE'];
    }

    final public function toArray(): array
    {
        return $this->jsonSerialize();
    }

    final public function __debugInfo(): array
    {
        return ['contract' => substr(strrchr(self::class, '\\'), 1), 'internal_state' => '[REDACTED]'];
    }
}
