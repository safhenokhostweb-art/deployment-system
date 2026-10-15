<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use App\Services\Sentinel\AgentRuntimeReader;
use App\Services\Sentinel\SentinelCollectionTarget;
use App\Services\Sentinel\SentinelEvidenceCollector;
use App\Services\Sentinel\SentinelRuntimeSnapshot;
use RuntimeException;

final class SentinelLocalProofOrchestrator
{
    public const STATES = ['CREATED', 'SOURCE_VERIFIED', 'ARTIFACT_VERIFIED', 'RELEASE_REGISTERED',
        'RELEASE_ACTIVATED', 'RUNTIME_STARTED', 'HEARTBEAT_ACCEPTED', 'SNAPSHOT_PERSISTED',
        'EVIDENCE_COLLECTED', 'VERIFIED'];
    private string $state = 'CREATED';
    private array $observations = [];
    private string $failureStage = 'CREATED';
    private ?array $verification = null;

    public function __construct(
        private readonly SentinelLocalProofFilesystem $filesystem,
        private readonly SentinelLocalArtifactAdapter $artifacts,
        private readonly SentinelLocalReleaseAdapter $releases,
        private readonly SentinelLocalHubAdapter $hub,
        private readonly SentinelLocalRuntimeAdapter $runtime,
    ) {}

    public function state(): string { return $this->state; }
    public function failureStage(): string { return $this->failureStage; }
    public function observations(): array { return $this->observations; }
    public function verification(): ?array { return $this->verification; }

    /** Observation is executed before advancement; a failed step is terminal. */
    private function step(string $next, callable $observe): mixed
    {
        $index = array_search($this->state, self::STATES, true);
        if ($index === false || (self::STATES[$index + 1] ?? null) !== $next) {
            throw new RuntimeException('proof_state_prerequisite_missing');
        }
        $this->failureStage = $next;
        try {
            $result = $observe();
            if ($result === null || $result === false) { throw new RuntimeException('proof_observation_unavailable'); }
            $this->state = $next;
            $this->observations[] = ['state' => $next, 'observed_at' => gmdate('c')];
            return $result;
        } catch (\Throwable $error) {
            $this->state = 'FAILED';
            throw new SentinelProofFailure($next, $error);
        }
    }

    /**
     * Receives a real, already built exact-commit ZIP inside this run.
     * Explicit approvals are required for execution and the application's isolated schema.
     * This method is NEVER called by installation, tests, existing routes or schedulers.
     */
    public function execute(string $zipPath, string $checksum, bool $authorized = false, bool $runtimeSchemaAuthorized = false): array
    {
        if (!$authorized || !$runtimeSchemaAuthorized) { throw new RuntimeException('proof_execution_not_authorized'); }
        if ($this->state !== 'CREATED') { throw new RuntimeException('proof_run_already_started'); }
        try {
            $this->step('SOURCE_VERIFIED', fn () => $this->artifacts->sourceInventory());
            $artifact = $this->step('ARTIFACT_VERIFIED', fn () => $this->artifacts->receive($zipPath, $checksum));
            $registration = $this->step('RELEASE_REGISTERED', fn () => $this->hub->register($artifact));
            $deploymentId = $registration['deployment']['public_id'];
            $this->step('RELEASE_ACTIVATED', fn () => $this->releases->activate($deploymentId, $artifact['checksum']));
            $target = new SentinelCollectionTarget(
                $this->filesystem->config->projectKey,
                'local',
                $registration['release']['public_id'],
                deploymentPublicId: $deploymentId,
            );
            $this->failureStage = 'EXPECTATION_RESOLUTION';
            $expectation = $this->hub->expectation($target);
            if ($expectation === null) { throw new RuntimeException('proof_expectation_missing'); }
            $this->step('RUNTIME_STARTED', function () use ($registration, $target, $expectation): bool {
                $this->runtime->start($registration, true, true);
                // A liveness-only response is never sufficient.
                return $this->runtime->awaitHealth($target, $expectation);
            });
            $this->failureStage = 'LOCAL_AGENT_SNAPSHOT_COLLECTION';
            $identity = $this->hub->identity();
            $root = dirname(__DIR__, 5);
            require_once $root . '/local-agent/lib/Security.php';
            require_once $root . '/local-agent/lib/RuntimeSnapshotCollector.php';
            $collector = new \LocalAgent\RuntimeSnapshotCollector($this->filesystem->config->releaseRoot(),
                $deploymentId, $target->projectKey, 'local', $target->releaseId, $identity['public_id'],
                'proof-' . substr(hash_file('sha256', $root . '/local-agent/lib/RuntimeSnapshotCollector.php'), 0, 12),
                $artifact['path'], $this->filesystem->config->root);
            $snapshot = $collector->collect();
            $body = json_encode(['runtime_snapshot' => $snapshot], JSON_THROW_ON_ERROR);
            $path = '/api/local-agent/heartbeat';
            $timestamp = (string) time();
            $nonce = \LocalAgent\Security::nonce();
            $signature = \LocalAgent\Security::sign('POST', $path, $timestamp, $nonce, $body, $identity['secret']);
            $this->step('HEARTBEAT_ACCEPTED', function () use ($identity, $path, $timestamp, $nonce, $signature, $body): bool {
                $response = $this->runtime->request($this->filesystem->config->hubUrl() . $path, 'POST', [
                    'Authorization: Bearer ' . $identity['token'], 'X-Deployment-Local-Agent-Id: ' . $identity['public_id'],
                    'X-Deployment-Timestamp: ' . $timestamp, 'X-Deployment-Nonce: ' . $nonce,
                    'X-Deployment-Signature: ' . $signature, 'Content-Type: application/json'], $body);
                return $response['status'] === 200 && ($response['body']['hmac_verified'] ?? null) === true;
            });
            $this->step('SNAPSHOT_PERSISTED', function () use ($snapshot): bool {
                $stored = $this->hub->persistedSnapshot();
                return $stored !== null && $stored['sequence'] === $snapshot['sequence']
                    && $stored['nonce'] === $snapshot['nonce'];
            });
            $runtimeReader = new class($this->hub) implements AgentRuntimeReader {
                public function __construct(private readonly SentinelLocalHubAdapter $hub) {}
                public function read(SentinelCollectionTarget $target, \App\Services\Sentinel\SentinelReleaseExpectation $expectation): ?SentinelRuntimeSnapshot
                { return $this->hub->readRuntime($target, $expectation); }
            };
            $collector = new SentinelEvidenceCollector($this->hub, $runtimeReader, $this->artifacts, $this->runtime);
            $outcome = $this->step('EVIDENCE_COLLECTED', fn () => $collector->collect($target));
            $this->verification = $outcome->toArray();
            $this->step('VERIFIED', fn () => $outcome->verdict() === 'VERIFIED');
            return $this->completedResult($outcome);
        } catch (\Throwable $error) {
            $this->state = 'FAILED';
            throw new RuntimeException('proof_execution_failed', 0, $error);
        } finally { $this->runtime->stop(); }
    }

    private function completedResult(\App\Services\Sentinel\SentinelCollectionOutcome $outcome): array
    {
        return ['run_id' => $this->filesystem->config->runId, 'state' => $this->state,
            'observations' => $this->observations, 'evidence' => $outcome->evidence()?->toArray(),
            'verification' => $outcome->toArray()];
    }
}
