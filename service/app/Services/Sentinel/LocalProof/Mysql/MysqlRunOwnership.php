<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof\Mysql;

use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use InvalidArgumentException;
use JsonSerializable;

/** Validated expected descriptions; never authenticated process/path ownership. */
final class MysqlRunOwnership implements JsonSerializable
{
    use MysqlContractObject;

    private const ACCESSIBLE_FIELDS = ['runId', 'runRoot', 'databaseDirectory', 'configurationDirectory',
        'temporaryDirectory', 'expectedExecutable', 'expectedExecutableSha256', 'schemaName', 'endpoint'];

    private function __construct(SentinelProofRunConfig $run, MysqlEndpoint $endpoint, string $binarySha256)
    {
        $temp = str_replace('\\', '/', (string) realpath(sys_get_temp_dir()));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $run->runId)
            || $run->root !== $temp . '/SentinelProof/' . $run->runId
            || $endpoint->runId !== $run->runId
            || !preg_match('/^[0-9a-f]{64}$/D', $binarySha256)) {
            throw new InvalidArgumentException('proof_mysql_owner_rejected');
        }
        MysqlEndpoint::fromArray($run, ['host' => $endpoint->host, 'port' => $endpoint->port, 'transport' => $endpoint->transport]);
        $files = new SentinelLocalProofFilesystem($run);
        $paths = [];
        foreach (['database/data', 'database/config', 'database/tmp', 'database/engine/bin/mysqld.exe'] as $relative) {
            $path = $files->assertContained($run->path($relative));
            for ($ancestor = $path; strlen($ancestor) >= strlen($run->root); $ancestor = dirname($ancestor)) {
                if (file_exists($ancestor) || is_link($ancestor)) {
                    $resolved = realpath($ancestor);
                    if (is_link($ancestor) || $resolved === false
                        || strcasecmp(str_replace('\\', '/', $resolved), $ancestor) !== 0) {
                        throw new InvalidArgumentException('proof_mysql_path_rejected');
                    }
                }
            }
            $paths[] = $path;
        }
        $this->remember(['runId' => $run->runId, 'runRoot' => $run->root,
            'databaseDirectory' => $paths[0], 'configurationDirectory' => $paths[1],
            'temporaryDirectory' => $paths[2], 'expectedExecutable' => $paths[3],
            'expectedExecutableSha256' => $binarySha256, 'schemaName' => 'sentinel_' . str_replace('-', '', $run->runId),
            'endpoint' => $endpoint, 'filesystem' => $files]);
    }

    public static function forRun(SentinelProofRunConfig $run, MysqlEndpoint $endpoint, string $binarySha256): self
    {
        return new self($run, $endpoint, $binarySha256);
    }

    public function assertSchema(string $schema): void
    {
        if ($schema !== $this->schemaName) { throw new InvalidArgumentException('proof_mysql_schema_rejected'); }
    }

    public function assertRun(string $runId): void
    {
        if ($runId !== $this->runId) { throw new InvalidArgumentException('proof_mysql_owner_rejected'); }
    }

    /** Lexical/known-ancestor validation only; NOT native containment authorization. */
    public function assertPathDescription(string $path): void
    {
        $state = $this->contractState();
        if (!in_array($path, [$state['databaseDirectory'], $state['configurationDirectory'],
            $state['temporaryDirectory'], $state['expectedExecutable']], true)) {
            throw new InvalidArgumentException('proof_mysql_path_rejected');
        }
        $state['filesystem']->assertContained($path);
    }

    /** Caller flags/canonical strings can never substitute for a future native inspector. */
    public function assertInspectedPath(string $path, string $canonicalPath, bool $reparsePoint, bool $ancestorsInspected): never
    {
        $this->contractState();
        throw new InvalidArgumentException('proof_mysql_path_observation_untrusted');
    }

    /** Explicit expected metadata, not a diagnostic export or observation receipt. */
    public function expectedProcessIdentity(): array
    {
        return ['run_id' => $this->runId, 'role' => 'MYSQL_SERVER', 'executable_sha256' => $this->expectedExecutableSha256];
    }

    public function expectedListenerIdentity(): array
    {
        return ['run_id' => $this->runId, 'process_role' => 'MYSQL_SERVER',
            'host' => $this->endpoint->host, 'port' => $this->endpoint->port, 'transport' => 'tcp'];
    }
}
