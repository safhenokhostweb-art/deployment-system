<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelLocalArtifactAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalReleaseAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalHubAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalRuntimeAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalProofOrchestrator;
use App\Services\Sentinel\SentinelReleaseExpectation;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Component tests only. No harness execute(), build, real artifact, DB, HTTP or process. */
class SentinelLocalProofIsolationTest extends TestCase
{
    private SentinelProofRunConfig $config;
    private SentinelLocalProofFilesystem $files;
    private SentinelLocalArtifactAdapter $artifacts;
    private SentinelLocalHubAdapter $hub;
    private SentinelLocalReleaseAdapter $releases;
    private SentinelLocalRuntimeAdapter $runtime;

    protected function setUp(): void
    {
        $repo = dirname(__DIR__, 5);
        $this->config = SentinelProofRunConfig::create($repo, str_repeat('a', 40), 'fixture-branch', 18101, 18102, 'fixture-project');
        $this->files = new SentinelLocalProofFilesystem($this->config);
        $this->artifacts = new SentinelLocalArtifactAdapter($this->files);
        $this->hub = new SentinelLocalHubAdapter($this->files);
        $this->releases = new SentinelLocalReleaseAdapter($this->files, $this->artifacts, $this->hub);
        $this->runtime = new SentinelLocalRuntimeAdapter($this->files);
    }

    private function rejected(callable $operation): void
    {
        try { $operation(); self::fail('Unsafe fixture was accepted'); }
        catch (\InvalidArgumentException|RuntimeException $error) {
            self::assertStringNotContainsString('fixture-secret-content', $error->getMessage());
        }
    }

    private function manifest(): array
    {
        return ['format' => 'deployment-system-release-manifest', 'version' => 1,
            'project_key' => $this->config->projectKey, 'commit_sha' => $this->config->commitSha,
            'branch' => $this->config->branch, 'composer_lock_sha256' => hash('sha256', 'fixture-lock'),
            'required_paths' => ['vendor/autoload.php', 'public/build/manifest.json', '.deployment/release-manifest.json'],
            'source_paths' => ['app.php'], 'build_manifest_present' => true,
            'build_result' => ['ran_composer' => true, 'ran_npm_build' => true],
            'agent_version' => 'fixture-version', 'generated_at' => gmdate('c'), 'status' => 'pass'];
    }

    public function test_construction_is_lazy_and_all_paths_are_independent(): void
    {
        self::assertFalse(file_exists($this->config->root));
        self::assertNull((new \ReflectionProperty($this->hub, 'pdo'))->getValue($this->hub));
        foreach ([$this->config->databasePath(), $this->config->runtimeDatabasePath(),
            $this->config->storagePath(), $this->config->releaseRoot()] as $path) {
            self::assertStringStartsWith($this->config->root . '/', $path);
            self::assertStringNotContainsString('/deployment-system/', $path);
        }
        foreach ([true, false] as $hub) {
            $environment = $this->config->environment($hub);
            self::assertSame('sqlite', $environment['DB_CONNECTION']);
            self::assertSame('', $environment['DB_URL']);
            self::assertSame('null', $environment['QUEUE_CONNECTION']);
            self::assertStringStartsWith($this->config->root . '/', $environment['APP_CONFIG_CACHE']);
            self::assertStringStartsWith($this->config->root . '/', $environment['LARAVEL_STORAGE_PATH']);
        }
        $this->rejected(fn () => $this->hub->initialize());
        $this->rejected(fn () => $this->files->initialize());
        self::assertFalse(file_exists($this->config->root));
    }

    public function test_failed_step_retains_original_exception_and_stage_and_stays_terminal(): void
    {
        $orchestrator = new SentinelLocalProofOrchestrator($this->files, $this->artifacts, $this->releases, $this->hub, $this->runtime);
        $root = new \LogicException('fixture-private-diagnostic');
        $step = new \ReflectionMethod($orchestrator, 'step');
        try {
            $step->invoke($orchestrator, 'SOURCE_VERIFIED', function () use ($root): never { throw $root; });
            self::fail('Failure was accepted');
        } catch (\App\Services\Sentinel\LocalProof\SentinelProofFailure $error) {
            self::assertSame($root, $error->getPrevious());
            self::assertSame('SOURCE_VERIFIED', $error->failureStage);
            self::assertSame('FAILED', $orchestrator->state());
            self::assertSame('SOURCE_VERIFIED', $orchestrator->failureStage());
            self::assertSame([], $orchestrator->observations());
        }
        $this->rejected(fn () => $step->invoke($orchestrator, 'ARTIFACT_VERIFIED', fn () => true));
    }

    public function test_proof_report_preserves_evidence_contract_and_exposes_recomputed_verdict(): void
    {
        $data = json_decode(file_get_contents(__DIR__ . '/fixtures/sentinel-verifiable.json'), true);
        $data['result'] = 'FAILED'; // Advisory claim must never determine the actual verifier verdict.
        $evidence = \App\Services\Sentinel\SentinelEvidenceContract::fromArray($data);
        $result = (new \App\Services\Sentinel\SentinelVerifier())->verify($evidence, [
            'release_id' => $data['release_id'],
            'deployment_id' => 'dep-0001',
        ]);
        $outcome = \App\Services\Sentinel\SentinelCollectionOutcome::captured($evidence, $result);
        $orchestrator = new SentinelLocalProofOrchestrator($this->files, $this->artifacts, $this->releases, $this->hub, $this->runtime);
        $report = (new \ReflectionMethod($orchestrator, 'completedResult'))->invoke($orchestrator, $outcome);
        self::assertSame($evidence->toArray(), $report['evidence']);
        self::assertSame('FAILED', $report['evidence']['result']);
        self::assertSame('VERIFIED', $report['verification']['verdict']);
        self::assertSame($result->evidenceHash(), $report['verification']['evidence_hash']);
    }

    public function test_path_traversal_absolute_paths_and_streams_are_rejected(): void
    {
        foreach (['../outside', '/etc/passwd', 'C:outside', 'C:/outside', '\\\\server\\share', 'hub/../../outside',
            'php://filter', 'hub/file:alternate', '.', 'hub/./file'] as $path) {
            $this->rejected(fn () => $this->config->path($path));
        }
        $this->rejected(fn () => $this->files->assertContained($this->config->sourceRepository . '/service/database/database.sqlite'));
        $this->rejected(fn () => $this->files->assertContained($this->config->root . '-sibling/file'));
        $this->rejected(fn () => $this->files->cleanup());
    }

    public function test_credential_files_are_rejected_without_rejecting_composer_credentials_classes(): void
    {
        $requiredPaths = ['vendor/autoload.php'];
        foreach ([
            'vendor/package/credentials',
            'vendor/package/credentials.json',
            'credentials',
            'credentials.json',
            'credentials-prod',
            'vendor/aws/aws-sdk-php/src/Credentials/credentials.json',
            'vendor/aws/aws-sdk-php/src/Credentials/credentials-prod',
            'vendor/aws/aws-sdk-php/src/Credentials/.env',
            'vendor/aws/aws-sdk-php/src/Credentials/private.pem',
            'vendor/aws/aws-sdk-php/src/Credentials/private.key',
            'vendor/aws/aws-sdk-php/src/Credentials/private.p12',
            'vendor/aws/aws-sdk-php/src/Credentials/private.pfx',
            'vendor/aws/aws-sdk-php/src/Credentials/.aws/config',
            'vendor/aws/aws-sdk-php/src/Credentials/.ssh/config',
            'vendor/aws/aws-sdk-php/src/Credentials/auth.json',
            'vendor/aws/aws-sdk-php/src/Credentials/id_rsa',
            'vendor/aws/aws-sdk-php/src/Credentials/id_ed25519',
            'vendor/package/credentials.yaml',
            'vendor/package/credentials.yml',
            'vendor/package/credentials.ini',
            'vendor/package/credentials.conf',
            'vendor/package/credentials.env',
            'vendor/package/credentials-production',
            'vendor/package/credentials-dev',
            'vendor/package/credentials-staging',
            'vendor/package/credentials-prod.json',
            'vendor/package/CREDENTIALS.YAML',
            '.aws',
            '.aws/config',
            '.ssh',
            '.ssh/config',
            '.npmrc',
            '.netrc',
            'vendor/package/auth.json',
            'vendor/package/id_rsa',
            'vendor/package/id_ed25519',
            'vendor/package/credentials.local',
            'vendor/package/credentials-prod',
            '.env',
            '.env.testing',
            'auth.json',
            'id_rsa',
            'id_ed25519',
            'vendor/package/private.pem',
            'vendor/package/private.key',
            'vendor/package/private.p12',
            'vendor/package/private.pfx',
            '../outside',
            '/absolute/path',
            'C:/absolute/path',
            'C:relative',
            'storage/runtime.json',
        ] as $entry) {
            $this->rejected(fn () => $this->artifacts->assertEntry($entry, $requiredPaths));
            $this->rejected(fn () => $this->artifacts->assertZipEntry($entry, $requiredPaths, [], '', 0100644));
        }

        foreach ([
            'vendor/aws/aws-crt-php/src/AWS/CRT/Auth/CredentialsProvider.php',
            'vendor/aws/aws-sdk-php/src/Credentials/ApiKeyCredential.php',
            'vendor/aws/aws-sdk-php/src/Credentials/Credentials.php',
            'vendor/package/credentials/credentials_provider.php',
            'vendor/package/Credentials.php',
            'vendor/package/CredentialsProvider.php',
            'vendor/package/credentials_provider.php',
            'vendor/package/CredentialsInterface.php',
        ] as $entry) {
            $this->artifacts->assertEntry($entry, $requiredPaths);
            self::assertFalse(SentinelLocalArtifactAdapter::isSensitiveEntry($entry));
            $this->artifacts->assertZipEntry($entry, $requiredPaths, [], '<?php class FixtureCredentials {}', 0100644);
        }

        foreach (['vendor/aws/aws-sdk-php/src/Credentials/', 'vendor/package/credentials/'] as $directory) {
            self::assertFalse(SentinelLocalArtifactAdapter::isSensitiveEntry($directory));
            $this->artifacts->assertEntry($directory, $requiredPaths);
            $this->artifacts->assertZipEntry($directory, $requiredPaths, [], '', 0040755);
        }

        foreach (SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES as $fixture) {
            $this->artifacts->assertEntry($fixture, $requiredPaths);
        }
    }

    public function test_urls_are_exact_loopback_run_endpoints(): void
    {
        $this->config->assertUrl($this->config->runtimeUrl() . '/health/release');
        $this->config->assertUrl($this->config->hubUrl() . '/api/local-agent/heartbeat', false, '/api/local-agent/heartbeat');
        self::assertTrue(true);
        foreach (['https://127.0.0.1:18102/health/release', 'http://example.com:18102/health/release',
            'http://127.0.0.1:8000/health/release', 'http://user@127.0.0.1:18102/health/release',
            'http://127.0.0.1:18102/health/release?', 'http://127.0.0.1:18102/health/release#',
            'http://127.0.0.1:18102/health/live', 'http://localhost:18102/health/release', 'http://:'] as $url) {
            $this->rejected(fn () => $this->config->assertUrl($url));
        }
    }

    public function test_normal_manifest_requires_npm_build_and_accepts_true(): void
    {
        $manifest = $this->manifest();
        self::assertTrue($manifest['build_result']['ran_npm_build']);
        $this->artifacts->validateManifest($manifest);

        $manifest['build_result']['ran_npm_build'] = false;
        $this->rejected(fn () => $this->artifacts->validateManifest($manifest));
    }
    public function test_manifest_checksum_commit_and_live_source_are_checked(): void
    {
        $manifest = $this->manifest();
        $this->artifacts->validateManifest($manifest);
        $digest = hash('sha256', 'fixture-final-bytes');
        $this->artifacts->verifyChecksum($digest, $digest);
        self::assertTrue(true);
        foreach (['commit_sha' => str_repeat('b', 40), 'project_key' => 'other', 'branch' => 'other',
            'build_manifest_present' => false, 'composer_lock_sha256' => '', 'generated_at' => 'invalid'] as $key => $value) {
            $this->rejected(fn () => $this->artifacts->validateManifest(array_replace($manifest, [$key => $value])));
        }
        $incomplete = $manifest; unset($incomplete['source_paths']);
        $this->rejected(fn () => $this->artifacts->validateManifest($incomplete));
        $this->rejected(fn () => $this->artifacts->verifyChecksum(hash('sha256', 'wrong-bytes'), $digest));
        $committed = ['app.php' => hash('sha256', 'committed')];
        $this->artifacts->verifySourceEntries($committed, $committed);
        $this->rejected(fn () => $this->artifacts->verifySourceEntries($committed, ['app.php' => hash('sha256', 'live-edit')]));
        $this->rejected(fn () => $this->artifacts->verifySourceEntries($committed, $committed + ['untracked.txt' => $digest]));
        foreach (['.env', '.env.local', 'node_modules/file.js', '.aws/credentials', 'storage/session.data',
            'bootstrap/cache/config.php', '../escape', 'vendor/autoload.php', 'file:stream'] as $entry) {
            $this->rejected(fn () => $this->artifacts->assertEntry($entry, []));
        }
    }

    public function test_manifest_requires_npm_build_and_rejects_forged_external_handoff(): void
    {
        $manifest = $this->manifest();
        $manifest['build_result'] = ['ran_composer' => true, 'ran_npm_build' => false];
        $this->rejected(fn () => $this->artifacts->validateManifest($manifest));

        $manifest['external_build_attestation'] = [
            'token' => hash_hmac('sha256', $this->config->root . ':' . $this->config->commitSha . ':0', 'sentinel-proof'),
        ];
        $this->rejected(fn () => $this->artifacts->validateManifest($manifest));
    }

    public function test_storage_fixture_paths_and_zip_entries_are_exact_and_fail_closed(): void
    {
        $source = [];
        foreach (SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES as $name) {
            $this->artifacts->assertEntry($name, []);
            $source[$name] = hash('sha256', 'committed-fixture');
        }
        foreach ($source as $name => $digest) {
            $this->artifacts->assertZipEntry($name, [], $source, 'committed-fixture', 0100644);
            $this->rejected(fn () => $this->artifacts->assertZipEntry($name, [], $source, 'committed-fixturf', 0100644));
            $deleted = $source; unset($deleted[$name]);
            $this->rejected(fn () => $this->artifacts->assertZipEntry($name, [], $deleted, 'committed-fixture', 0100644));
            $this->rejected(fn () => $this->artifacts->assertZipEntry($name, [], $source, '../outside', 0120777));
            $this->rejected(fn () => $this->artifacts->assertZipEntry($name . '/', [], $source, '', 0040755));
        }
        foreach (['storage/session.data', 'storage/framework/views/example.php',
            'storage/json-import-samples/unknown.json', 'storage/json-import-samples/../session.data',
            '/absolute/path', 'C:\\absolute\\path', 'C:relative',
            'storage/json-import-samples/bricks-sample.json/extra',
            'storage//json-import-samples/bricks-sample.json',
            'storage/json-import-samples/./bricks-sample.json',
            "storage/json-import-samples/bricks-sample.json\0", 'storage/other/'] as $name) {
            $this->rejected(fn () => $this->artifacts->assertEntry($name, []));
            $this->rejected(fn () => $this->artifacts->assertZipEntry($name, [], $source, '', 0100644));
        }
        foreach (['storage/.gitignore', 'storage/framework/views/.gitignore'] as $name) {
            $this->artifacts->assertEntry($name, []);
        }
        foreach (['storage/', 'storage/json-import-samples/'] as $name) {
            $this->artifacts->assertZipEntry($name, [], $source, '', 0040755);
            $this->rejected(fn () => $this->artifacts->assertZipEntry($name, [], [], '', 0040755));
            $this->rejected(fn () => $this->artifacts->assertZipEntry($name, [], $source, '', 0120777));
        }
        $this->rejected(fn () => $this->artifacts->assertZipEntry('storage//', [], $source, '', 0040755));
        $read = false;
        $bytes = function () use (&$read): string { $read = true; return 'committed-fixture'; };
        $this->rejected(fn () => $this->artifacts->assertZipEntry('storage/json-import-samples/unknown.json', [], $source, $bytes, 0100644));
        self::assertFalse($read);
        $name = SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES[0];
        $this->rejected(fn () => $this->artifacts->assertZipEntry($name, [], [], $bytes, 0100644));
        self::assertFalse($read);
        $this->rejected(fn () => $this->artifacts->assertZipEntry($name, [], $source, $bytes, 0120777));
        self::assertFalse($read);
        self::assertCount(5, $source);
    }

    public function test_storage_fixtures_match_exact_committed_blobs_read_only(): void
    {
        // Only read these five public fixtures; never build, archive, boot or write the source.
        $repo = dirname(__DIR__, 6) . '/saas-platform';
        $git = function (array $args) use ($repo): string {
            $process = new \Symfony\Component\Process\Process(array_merge(['git', '-C', $repo], $args));
            $process->setTimeout(30);
            $process->mustRun();
            return $process->getOutput();
        };
        $commit = trim($git(['rev-parse', 'HEAD']));
        $branch = trim($git(['branch', '--show-current']));
        $config = SentinelProofRunConfig::create($repo, $commit, $branch, 18101, 18102, 'fixture-project');
        $adapter = new SentinelLocalArtifactAdapter(new SentinelLocalProofFilesystem($config));
        $containment = new \ReflectionMethod($adapter, 'assertFixtureSourceContained');
        $source = [];
        $blobs = [];
        foreach (SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES as $name) {
            $entry = $git(['ls-tree', $commit, '--', $name]);
            self::assertMatchesRegularExpression('/^100(?:644|755) blob ([0-9a-f]{40})\t' . preg_quote($name, '/') . '$/m', $entry);
            preg_match('/ blob ([0-9a-f]{40})\t/', $entry, $match);
            $blobs[$name] = $git(['cat-file', 'blob', $match[1]]);
            $source[$name] = hash('sha256', $blobs[$name]);
            $containment->invoke($adapter, $name);
            self::assertSame($blobs[$name], file_get_contents($repo . '/' . $name));
        }
        $adapter->verifySourceEntries($source, $source);
        foreach ($blobs as $name => $bytes) {
            $adapter->assertZipEntry($name, [], $source, $bytes, 0100644);
            $mutated = chr(ord($bytes[0]) ^ 1) . substr($bytes, 1);
            $this->rejected(fn () => $adapter->assertZipEntry($name, [], $source, $mutated, 0100644));
            $deleted = $source; unset($deleted[$name]);
            $this->rejected(fn () => $adapter->assertZipEntry($name, [], $deleted, $bytes, 0100644));
        }
        self::assertCount(5, $source);
    }

    public function test_release_records_and_health_cannot_claim_a_different_runtime(): void
    {
        $deployment = (string) Str::uuid();
        $record = ['public_id' => $deployment, 'project_key' => $this->config->projectKey,
            'environment' => 'local', 'commit_hash' => $this->config->commitSha, 'checksum_sha256' => hash('sha256', 'fixture')];
        $artifact = ['manifest' => $this->manifest(), 'checksum' => $record['checksum_sha256']];
        $this->releases->assertRecord($record, $artifact);
        foreach (['environment' => 'production', 'project_key' => 'other', 'commit_hash' => str_repeat('b', 40),
            'checksum_sha256' => hash('sha256', 'other')] as $key => $value) {
            $this->rejected(fn () => $this->releases->assertRecord(array_replace($record, [$key => $value]), $artifact));
        }
        $expectation = new SentinelReleaseExpectation($this->config->projectKey, 'local', (string) Str::uuid(),
            $this->config->commitSha, null, null, 'release:' . $deployment . '|commit:' . $this->config->commitSha,
            $deployment, $this->config->runtimeUrl() . '/health/release', 200);
        $response = ['status' => 200, 'body' => ['coherent' => true, 'latest_release' => ['uuid' => $deployment, 'git_commit' => $this->config->commitSha]]];
        self::assertTrue($this->runtime->validateHealth($response, $expectation));
        self::assertFalse($this->runtime->validateHealth(['status' => 200, 'body' => ['status' => 'alive']], $expectation));
        $response['body']['latest_release']['git_commit'] = str_repeat('b', 40);
        self::assertFalse($this->runtime->validateHealth($response, $expectation));
    }

    public function test_state_machine_is_ordered_terminal_and_execution_is_opt_in(): void
    {
        $orchestrator = new SentinelLocalProofOrchestrator($this->files, $this->artifacts, $this->releases, $this->hub, $this->runtime);
        $this->rejected(fn () => $orchestrator->execute('fixture.zip', hash('sha256', 'fixture')));
        self::assertSame('CREATED', $orchestrator->state());
        $step = new \ReflectionMethod($orchestrator, 'step');
        $this->rejected(fn () => $step->invoke($orchestrator, 'HEARTBEAT_ACCEPTED', fn () => true));
        self::assertSame('CREATED', $orchestrator->state());
        $step->invoke($orchestrator, 'SOURCE_VERIFIED', fn () => ['fixture-observation']);
        self::assertSame('SOURCE_VERIFIED', $orchestrator->state());
        $this->rejected(fn () => $step->invoke($orchestrator, 'ARTIFACT_VERIFIED', fn () => false));
        self::assertSame('FAILED', $orchestrator->state());
        $this->rejected(fn () => $step->invoke($orchestrator, 'ARTIFACT_VERIFIED', fn () => true));
        self::assertFalse(file_exists($this->config->root));
    }

    public function test_temporary_fixture_symlinks_and_collector_hash_are_contained(): void
    {
        // Only filesystem component fixtures, never an application artifact/release or harness execution.
        $this->files->initialize(true);
        $link = $this->config->path('outside-link');
        $current = $this->config->path('runtime/application/current');
        try {
            $bytes = 'isolated-checksum-fixture';
            $file = $this->files->write('artifacts/checksum-fixture.bin', $bytes);
            $root = dirname(__DIR__, 5);
            require_once $root . '/local-agent/lib/RuntimeSnapshotCollector.php';
            $method = new \ReflectionMethod(\LocalAgent\RuntimeSnapshotCollector::class, 'readArtifactChecksum');
            $collector = new \LocalAgent\RuntimeSnapshotCollector($this->config->releaseRoot(), '', '', 'local', '', '', 'fixture', $file, $this->config->root);
            self::assertSame(hash('sha256', $bytes), $method->invoke($collector));
            $missing = new \LocalAgent\RuntimeSnapshotCollector($this->config->releaseRoot(), '', '', 'local', '', '', 'fixture', $this->config->path('artifacts/missing.bin'), $this->config->root);
            self::assertNull($method->invoke($missing));
            $outside = new \LocalAgent\RuntimeSnapshotCollector($this->config->releaseRoot(), '', '', 'local', '', '', 'fixture', __FILE__, $this->config->root);
            self::assertNull($method->invoke($outside));
            if (!@symlink(sys_get_temp_dir(), $link)) {
                self::markTestSkipped('Windows symlink permission unavailable for isolated fixture.');
            }
            $this->rejected(fn () => $this->files->assertContained($link . '/outside'));
            $this->rejected(fn () => $this->files->cleanup(true));
            unlink($link);
            $target = $this->files->directory('runtime/application/releases/' . $this->config->runId);
            self::assertTrue(symlink($target, $current));
            $this->rejected(fn () => $this->releases->assertCurrent($this->config->runId));
            $this->files->write('runtime/application/releases/' . $this->config->runId . '/.deployment/runtime-marker', 'wrong-marker');
            $this->rejected(fn () => $this->releases->assertCurrent($this->config->runId));
        } finally {
            foreach ([$link, $current] as $fixtureLink) { if (is_link($fixtureLink)) { unlink($fixtureLink); } }
            $this->files->cleanup(true);
        }
    }

    public function test_collectors_remain_identical(): void
    {
        $root = dirname(__DIR__, 5);
        self::assertFileEquals($root . '/local-agent/lib/RuntimeSnapshotCollector.php', $root . '/deployment-local-agent/agent/lib/RuntimeSnapshotCollector.php');
    }

    public function test_canonical_symlink_targets_outside_run_are_rejected_without_os_privileges(): void
    {
        $this->files->assertResolved($this->config->path('runtime/application/releases/fixture'));
        self::assertTrue(true);
        foreach ([sys_get_temp_dir(), $this->config->root . '-sibling', $this->config->sourceRepository] as $outside) {
            $this->rejected(fn () => $this->files->assertResolved($outside));
        }
    }

    public function test_current_and_marker_observations_require_exact_containment_and_identity(): void
    {
        $id = (string) Str::uuid();
        $path = $this->config->path('runtime/application/releases/' . $id);
        $marker = 'release:' . $id . '|commit:' . $this->config->commitSha;
        $this->releases->validateCurrentObservation($path, $path, true, $marker, $id);
        self::assertTrue(true);
        $this->rejected(fn () => $this->releases->validateCurrentObservation(sys_get_temp_dir(), $path, true, $marker, $id));
        $this->rejected(fn () => $this->releases->validateCurrentObservation($path, $path, false, $marker, $id));
        $this->rejected(fn () => $this->releases->validateCurrentObservation($path, $path, true, 'wrong-marker', $id));
    }

    public function test_in_memory_hub_models_signed_fixture_ingest_and_reader_share_only_fixture_database(): void
    {
        $this->files->initialize(true);
        try {
            $hub = SentinelLocalHubAdapter::inMemoryFixture($this->files);
            $hub->initialize(true);
            $artifactPath = $this->files->write('artifacts/checksum-fixture.bin', 'fixture-only-bytes');
            $artifact = ['path' => $artifactPath, 'checksum' => hash_file('sha256', $artifactPath),
                'size' => filesize($artifactPath), 'manifest' => $this->manifest()];
            $registered = $hub->register($artifact);
            self::assertSame('pending_local_activation', $registered['deployment']['status']);
            self::assertFalse(file_exists($this->config->databasePath()));
            $identity = $hub->identity();
            $target = new \App\Services\Sentinel\SentinelCollectionTarget($this->config->projectKey, 'local', $registered['release']['public_id']);
            $expected = $hub->expectation($target);
            self::assertNotNull($expected);
            $snapshot = ['evidence_version' => 'sentinel.runtime.v1', 'agent_id' => $identity['public_id'],
                'agent_version' => 'fixture-version', 'project_key' => $this->config->projectKey, 'environment' => 'local',
                'release_id' => $registered['release']['public_id'], 'deployment_id' => $registered['deployment']['public_id'],
                'current_release_path' => 'releases/' . $registered['deployment']['public_id'],
                'runtime_marker' => $expected->expectedRuntimeMarker, 'release_manifest_commit_sha' => $this->config->commitSha,
                'artifact_checksum_sha256' => $artifact['checksum'], 'timestamp' => gmdate('c'),
                'sequence' => 1, 'nonce' => bin2hex(random_bytes(16)), 'snapshot_status' => 'complete'];
            $sendFixture = function (array $payload) use ($hub, $identity): int {
                $body = json_encode(['runtime_snapshot' => $payload], JSON_THROW_ON_ERROR);
                $timestamp = (string) time();
                $nonce = bin2hex(random_bytes(16));
                $signature = (new \App\Services\Security\RequestSigner())->sign('POST', '/api/local-agent/heartbeat', $timestamp, $nonce, $body, $identity['secret']);
                return $hub->ingest('POST', '/api/local-agent/heartbeat', [
                    'Authorization' => 'Bearer ' . $identity['token'], 'X-Deployment-Local-Agent-Id' => $identity['public_id'],
                    'X-Deployment-Timestamp' => $timestamp, 'X-Deployment-Nonce' => $nonce, 'X-Deployment-Signature' => $signature], $body);
            };
            self::assertSame(200, $sendFixture($snapshot));
            self::assertSame(1, $hub->persistedSnapshot()['sequence']);
            self::assertSame($this->config->commitSha, $hub->readRuntime($target, $expected)->observedCommitSha);
            self::assertSame(409, $sendFixture($snapshot));
            self::assertSame(1, $hub->persistedSnapshot()['sequence']);
            self::assertSame(422, $sendFixture(array_replace($snapshot, ['sequence' => 2, 'environment' => 'production'])));
            self::assertSame(422, $sendFixture(array_replace($snapshot, ['sequence' => 2, 'artifact_checksum_sha256' => hash('sha256', 'wrong')])));
            self::assertSame(401, $hub->ingest('POST', '/api/local-agent/heartbeat', [], '{}'));
            self::assertFalse(file_exists($this->config->databasePath()));
        } finally { $this->files->cleanup(true); }
    }
}
