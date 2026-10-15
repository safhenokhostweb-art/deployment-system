<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use InvalidArgumentException;
use JsonSerializable;

/** Configuration description only; no allocation, listener verification or DSN. */
final class MysqlEndpoint implements JsonSerializable
{
    use MysqlContractObject;

    private const ACCESSIBLE_FIELDS = ['host', 'port', 'transport', 'runId'];

    private function __construct(SentinelProofRunConfig $run, array $input)
    {
        if (array_diff(array_keys($input), ['host', 'port', 'transport', 'DB_URL']) !== []
            || ($input['host'] ?? null) !== '127.0.0.1'
            || ($input['transport'] ?? null) !== 'tcp'
            || !is_int($input['port'] ?? null)
            || $input['port'] < 1024 || $input['port'] > 65535
            || in_array($input['port'], [3306, 8000, 8080, $run->hubPort, $run->agentPort], true)
            || (array_key_exists('DB_URL', $input) && $input['DB_URL'] !== '')) {
            throw new InvalidArgumentException('proof_mysql_endpoint_rejected');
        }
        // Scalar copies, never the caller's array or references.
        $port = (int) $input['port'];
        $this->remember(['host' => '127.0.0.1', 'port' => $port, 'transport' => 'tcp', 'runId' => $run->runId]);
    }

    public static function fromArray(SentinelProofRunConfig $run, array $input): self
    {
        return new self($run, $input);
    }
}
