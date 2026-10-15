<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use App\Services\Sentinel\IndependentHealthProbe;
use App\Services\Sentinel\SentinelCollectionTarget;
use App\Services\Sentinel\SentinelHealthObservation;
use App\Services\Sentinel\SentinelReleaseExpectation;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final class SentinelLocalRuntimeAdapter implements IndependentHealthProbe
{
    private ?Process $runtime = null;
    private ?Process $hub = null;
    private ?array $record = null;
    private array $healthStatuses = ['release' => null, 'live' => null];

    public function __construct(
        private readonly SentinelLocalProofFilesystem $filesystem,
        private readonly ?Closure $initializationRunner = null,
    ) {}

    public function healthStatuses(): array { return $this->healthStatuses; }

    private function environment(array $extra): array
    {
        // Symfony Process otherwise inherits parent DB URLs, proxies, credentials and cache paths.
        $environment = array_fill_keys(array_keys(getenv()), false);
        foreach (['SystemRoot', 'WINDIR', 'PATH', 'TEMP', 'TMP'] as $key) {
            $value = getenv($key);
            if ($value !== false) { $environment[$key] = $value; }
        }
        return $environment + $extra; // Removed keys must be replaced explicitly below.
    }

    private function runtimeContext(array $registration, bool $authorized): array
    {
        if (!$authorized) { throw new RuntimeException('proof_execution_not_authorized'); }
        $config = $this->filesystem->config;
        $record = $registration['deployment'];
        if ($record['commit_hash'] !== $config->commitSha || $registration['project']['environment'] !== 'local') {
            throw new RuntimeException('proof_runtime_attribution_mismatch');
        }
        $current = $config->path('runtime/application/current');
        $this->filesystem->assertContained($current, true);
        $release = realpath($current);
        if ($release === false || !is_file($release . '/vendor/autoload.php') || !is_file($release . '/bootstrap/app.php')) {
            throw new RuntimeException('proof_runtime_incomplete');
        }
        $this->record = $record;
        $shared = ['SENTINEL_PROOF_CONFIG' => $config->path('run.json'),
            'SENTINEL_PROOF_DEPLOYMENT_ID' => $record['public_id'],
            'SENTINEL_PROOF_ARTIFACT_PATH' => $registration['artifact']['disk_path'],
            'SENTINEL_PROOF_ARTIFACT_SHA256' => $registration['artifact']['checksum_sha256'],
            'APP_KEY' => 'base64:' . base64_encode(random_bytes(32))];
        $environment = array_replace($this->environment([]), $config->environment(false), $shared);
        $runtimeRouter = dirname(__DIR__, 5) . '/tools/sentinel-proof/runtime-router.php';
        $hubRouter = dirname(__DIR__, 5) . '/tools/sentinel-proof/hub-router.php';
        return [$config, $record, $release, $environment, $runtimeRouter, $hubRouter, $shared];
    }

    private function initializeRuntime(array $context): array
    {
        [$config, , $release, $environment, $runtimeRouter] = $context;
        $command = [PHP_BINARY, $runtimeRouter, '--initialize-runtime'];
        $this->filesystem->write('evidence/runtime-initialization-started.json',
            json_encode(['started' => true], JSON_THROW_ON_ERROR));
        if ($this->initializationRunner !== null) {
            $result = ($this->initializationRunner)($command, $release, $environment);
            if (!is_array($result) || !array_key_exists('exit_code', $result)
                || !array_key_exists('successful', $result) || !is_bool($result['successful'])) {
                throw new RuntimeException('proof_runtime_initialization_result_invalid');
            }
        } else {
            $prepare = new Process($command, $release, $environment);
            $prepare->setTimeout(300);
            $prepare->run();
            $result = ['exit_code' => $prepare->getExitCode(), 'successful' => $prepare->isSuccessful()];
        }
        $diagnosticPath = $config->path('runtime/logs/router-errors.log');
        $diagnostics = null;
        if (is_file($diagnosticPath)) {
            $this->filesystem->assertContained($diagnosticPath);
            $diagnostics = json_decode((string) file_get_contents($diagnosticPath), true, 512, JSON_THROW_ON_ERROR);
        }
        $evidence = ['exit_code' => $result['exit_code'], 'successful' => $result['successful']];
        if (is_array($diagnostics)) { $evidence['diagnostics'] = $diagnostics; }
        $this->filesystem->write('evidence/runtime-initialization.json',
            json_encode($evidence, JSON_THROW_ON_ERROR));
        if (!$result['successful']) { throw new RuntimeException('proof_runtime_initialization_failed'); }
        return $evidence;
    }

    /** Runs the existing isolated initializer once and never starts a server. */
    public function initializeOnly(
        array $registration,
        bool $authorized = false,
        bool $runtimeSchemaAuthorized = false,
    ): array {
        if (!$runtimeSchemaAuthorized) { throw new RuntimeException('proof_execution_not_authorized'); }
        return $this->initializeRuntime($this->runtimeContext($registration, $authorized));
    }

    public function start(array $registration, bool $authorized = false, bool $runtimeSchemaAuthorized = false): void
    {
        $context = $this->runtimeContext($registration, $authorized);
        [$config, , $release, $environment, $runtimeRouter, $hubRouter, $shared] = $context;
        if ($runtimeSchemaAuthorized) { $this->initializeRuntime($context); }

        // No existing PID, service, Queue Worker, scheduler or Server Agent is referenced.
        $this->runtime = new Process([PHP_BINARY, '-S', '127.0.0.1:' . $config->agentPort, $runtimeRouter], $release, $environment);
        $this->hub = new Process([PHP_BINARY, '-S', '127.0.0.1:' . $config->hubPort, $hubRouter],
            dirname(__DIR__, 5), array_replace($this->environment([]), $config->environment(true), $shared));
        $this->runtime->setTimeout(null);
        $this->hub->setTimeout(null);
        $this->runtime->disableOutput();
        $this->hub->disableOutput();
        $this->runtime->start();
        $this->hub->start();
        if (!$this->runtime->isRunning() || !$this->hub->isRunning()) {
            $this->stop();
            throw new RuntimeException('proof_process_start_failed');
        }
    }

    public function request(string $url, string $method = 'GET', array $headers = [], string $body = ''): array
    {
        $runtime = $method === 'GET';
        $path = $runtime ? (string) parse_url($url, PHP_URL_PATH) : '/api/local-agent/heartbeat';
        if ($runtime && !in_array($path, ['/health/release', '/health/live'], true)) {
            throw new RuntimeException('proof_health_path_rejected');
        }
        $this->filesystem->config->assertUrl($url, $runtime, $path);
        if (!in_array($method, ['GET', 'POST'], true)) { throw new RuntimeException('proof_method_rejected'); }
        $handle = curl_init($url);
        curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $method === 'POST' ? $body : null,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '',
            CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 10]);
        $bytes = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($bytes === false) { throw new RuntimeException('proof_loopback_connection_failed'); }
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        return ['status' => $status, 'body' => $decoded];
    }

    public function validateHealth(array $response, SentinelReleaseExpectation $expectation): bool
    {
        return ($response['status'] ?? null) === $expectation->expectedHealthStatus
            && ($response['body']['coherent'] ?? null) === true
            && ($response['body']['latest_release']['uuid'] ?? null) === $expectation->deploymentPublicId
            && ($response['body']['latest_release']['git_commit'] ?? null) === $expectation->commitSha;
    }

    public function awaitHealth(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): bool
    {
        $deadline = microtime(true) + 10;
        do {
            if (!$this->runtime?->isRunning() || !$this->hub?->isRunning()) { return false; }
            try {
                $release = $this->request($expectation->healthEndpoint);
                $live = $this->request($this->filesystem->config->runtimeUrl() . '/health/live');
                $this->healthStatuses = ['release' => $release['status'], 'live' => $live['status']];
                if ($this->validateHealth($release, $expectation) && $live['status'] === 200) { return true; }
            } catch (\Throwable) { /* A bounded startup wait, never a heartbeat retry. */ }
            usleep(100000);
        } while (microtime(true) < $deadline);
        return false;
    }

    public function probe(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelHealthObservation
    {
        if ($target->projectKey !== $this->filesystem->config->projectKey || $target->environment !== 'local'
            || $expectation->healthEndpoint === null) { return null; }
        $response = $this->request($expectation->healthEndpoint);
        return new SentinelHealthObservation($this->validateHealth($response, $expectation) ? $response['status'] : null, gmdate('c'));
    }

    public function stop(): void
    {
        // Only process objects created by this adapter; never Stop-Process/kill by external PID.
        $this->runtime?->stop(2);
        $this->hub?->stop(2);
    }
}
