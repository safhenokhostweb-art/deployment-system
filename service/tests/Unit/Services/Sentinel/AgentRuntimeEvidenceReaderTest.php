<?php

namespace Tests\Unit\Services\Sentinel;

use App\Models\Agent;
use App\Services\Sentinel\AgentRuntimeEvidenceReader;
use App\Services\Sentinel\SentinelCollectionTarget;
use App\Services\Sentinel\SentinelReleaseExpectation;
use App\Services\Sentinel\SentinelRuntimeEvidenceContract;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class AgentRuntimeEvidenceReaderTest extends TestCase
{
    public function test_reader_uses_factory_and_reads_validated_in_memory_snapshots(): void
    {
        $reader = new AgentRuntimeEvidenceReader();
        self::assertInstanceOf(AgentRuntimeEvidenceReader::class, $reader);
        self::assertTrue((new \ReflectionMethod(SentinelRuntimeEvidenceContract::class, '__construct'))->isPrivate());
        self::assertStringNotContainsString('new SentinelRuntimeEvidenceContract(', file_get_contents(dirname(__DIR__, 4) . '/app/Services/Sentinel/AgentRuntimeEvidenceReader.php'));
        $previousContainer = Container::getInstance();
        $previousResolver = Agent::getConnectionResolver();
        $container = new Container();
        $container->instance('config', new Repository(['deployment' => ['runtime_snapshot_freshness_seconds' => 300]]));
        Container::setInstance($container);
        $database = new Manager($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database->bootEloquent();
        try {
            foreach (['agents', 'local_agents'] as $table) {
                $database->getConnection()->getSchemaBuilder()->create($table, function (Blueprint $schema): void {
                    $schema->id();
                    $schema->string('public_id');
                    $schema->string('status');
                    $schema->json('runtime_snapshot')->nullable();
                    $schema->unsignedBigInteger('runtime_snapshot_sequence')->nullable();
                });
            }
            $sha = str_repeat('a', 40);
            $payload = ['evidence_version' => 'sentinel.runtime.v1', 'agent_id' => 'agent-local', 'agent_version' => '1.0', 'project_key' => 'project', 'environment' => 'local', 'release_id' => 'rel-1', 'deployment_id' => 'dep-1', 'current_release_path' => 'releases/dep-1', 'runtime_marker' => 'release:dep-1|commit:' . $sha, 'release_manifest_commit_sha' => $sha, 'artifact_checksum_sha256' => str_repeat('b', 64), 'timestamp' => gmdate('c'), 'sequence' => 42, 'nonce' => 'test-nonce', 'snapshot_status' => 'complete'];
            self::assertTrue(SentinelRuntimeEvidenceContract::fromArray($payload)->isComplete());
            $target = new SentinelCollectionTarget('project', 'local', 'rel-1');
            $expectation = new SentinelReleaseExpectation('project', 'local', 'rel-1', $sha, 'artifact-1', $payload['artifact_checksum_sha256'], $payload['runtime_marker'], 'dep-1', null, 200, 'agent-local');
            foreach (['local_agents' => \App\Models\LocalAgent::class, 'agents' => Agent::class] as $tableName => $modelClass) {
                $table = $database->getConnection()->table($tableName);
                $table->insert(['public_id' => 'agent-local', 'status' => 'approved', 'runtime_snapshot' => json_encode($payload), 'runtime_snapshot_sequence' => 42]);
                foreach ([1, 42] as $sequence) {
                    $table->update(['runtime_snapshot' => json_encode(array_replace($payload, ['sequence' => $sequence])), 'runtime_snapshot_sequence' => $sequence]);
                    $observed = $reader->read($target, $expectation);
                    if ($tableName === 'local_agents') {
                        self::assertNull($observed, 'A Local Agent source workspace is not evidence of deployed runtime.');
                    } else {
                        self::assertSame($sha, $observed?->observedCommitSha);
                    }
                }
                foreach ([['runtime_marker' => null, 'snapshot_status' => 'incomplete'], ['runtime_marker' => 'Bearer test-fixture-value'], ['runtime_marker' => 'release:other|commit:' . $sha], ['release_manifest_commit_sha' => str_repeat('c', 40)], ['artifact_checksum_sha256' => str_repeat('c', 64)], ['project_key' => 'other'], ['environment' => 'other'], ['timestamp' => '2000-01-01T00:00:00Z'], ['timestamp' => 'invalid-timestamp'], ['timestamp' => gmdate('c', time() + 3600)], ['sequence' => 1], ['sequence' => 43], ['sequence' => null], ['sequence' => '42'], ['sequence' => 'invalid'], ['sequence' => 0], ['sequence' => -1]] as $change) {
                    $table->update(['runtime_snapshot' => json_encode(array_replace($payload, $change)), 'runtime_snapshot_sequence' => 42]);
                    self::assertNull($reader->read($target, $expectation));
                }
                foreach ([null, 0, -1, 'malformed', '42invalid'] as $watermark) {
                    $table->update(['runtime_snapshot' => json_encode($payload), 'runtime_snapshot_sequence' => $watermark]);
                    self::assertNull($reader->read($target, $expectation));
                }
                $table->update(['runtime_snapshot' => null]);
                self::assertNull($reader->read($target, $expectation));
                if ($tableName === 'local_agents') {
                    $readStored = new \ReflectionMethod(AgentRuntimeEvidenceReader::class, 'readLatestValidSnapshot');
                    self::assertNull($readStored->invoke($reader, $modelClass::query()->firstOrFail(), $target, $expectation, true));
                }

                // Ingest replay semantics remain strict even though read semantics use equality.
                $controllerClass = $tableName === 'local_agents'
                    ? \App\Http\Controllers\Api\LocalAgentJobController::class
                    : \App\Http\Controllers\Api\AgentJobController::class;
                $controller = (new \ReflectionClass($controllerClass))->newInstanceWithoutConstructor();
                $store = new \ReflectionMethod($controllerClass, 'storeRuntimeSnapshot');
                $table->update(['runtime_snapshot' => json_encode($payload), 'runtime_snapshot_sequence' => 42]);
                $agent = $modelClass::query()->firstOrFail();
                $before = $table->first();
                foreach ([41, 42] as $replayedSequence) {
                    self::assertFalse($store->invoke($controller, $agent, array_replace($payload, ['sequence' => $replayedSequence])));
                    self::assertEquals($before, $table->first());
                }
                self::assertTrue($store->invoke($controller, $agent, array_replace($payload, ['sequence' => 43])));
                $observed = $reader->read($target, $expectation);
                if ($tableName === 'local_agents') {
                    self::assertNull($observed, 'A Local Agent source workspace is not evidence of deployed runtime.');
                } else {
                    self::assertSame($sha, $observed?->observedCommitSha);
                }
                $table->delete();
            }
        } finally {
            $database->getConnection()->disconnect();
            if ($previousResolver !== null) { Agent::setConnectionResolver($previousResolver); }
            else { Agent::unsetConnectionResolver(); }
            Container::setInstance($previousContainer);
        }
    }
}
