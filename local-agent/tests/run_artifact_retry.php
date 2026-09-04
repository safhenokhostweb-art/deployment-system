<?php

declare(strict_types=1);

/** Focused end-to-end retry coverage: ArtifactUploader -> Client -> fake Hub. */
require __DIR__ . '/../lib/Security.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/SecretRedactor.php';
require __DIR__ . '/../lib/Client.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/ApplicationRoot.php';
require __DIR__ . '/../lib/RetryPolicy.php';
require __DIR__ . '/../lib/GitTransportFailureClassifier.php';
require __DIR__ . '/../lib/RetryableGitFetch.php';
require __DIR__ . '/../lib/LocalBuildRunner.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/BuildWorkspace.php';
require __DIR__ . '/../lib/DependencyRepositoryResolver.php';
require __DIR__ . '/../lib/WorkspaceSourcePreparer.php';
require __DIR__ . '/../lib/ArtifactBuilder.php';
require __DIR__ . '/../lib/ArtifactUploader.php';

use LocalAgent\ArtifactBuilder;
use LocalAgent\ArtifactUploader;
use LocalAgent\Client;
use LocalAgent\GitManager;
use LocalAgent\LocalBuildRunner;
use LocalAgent\Logger;
use LocalAgent\RetryPolicy;
use LocalAgent\RetryableHubException;

$passed = 0;
$failed = [];
function checkRetry(string $name, bool $condition): void { global $passed, $failed; if ($condition) { $passed++; } else { $failed[] = $name; } }
function retryTmp(string $suffix): string { $path = sys_get_temp_dir() . '/local-agent-retry-' . $suffix . '-' . bin2hex(random_bytes(5)); mkdir($path, 0777, true); return $path; }
function retryRm(string $path): void { if (!is_dir($path)) return; foreach (scandir($path) ?: [] as $name) { if ($name === '.' || $name === '..') continue; $entry = "{$path}/{$name}"; if (is_dir($entry)) retryRm($entry); else @unlink($entry); } @rmdir($path); }
function retryGit(GitManager $git, array $args, string $cwd): void { $result = $git->run(array_merge(['git'], $args), $cwd, 30); if ($result['exitCode'] !== 0) throw new RuntimeException($result['output']); }
/** @return array{path:string,commit:string} */
function retryRepo(GitManager $git): array { $root = retryTmp('repo'); $repo = "{$root}/repo"; mkdir($repo, 0777, true); retryGit($git, ['init', '--quiet', '-b', 'main'], $repo); retryGit($git, ['config', 'user.email', 'test@example.test'], $repo); retryGit($git, ['config', 'user.name', 'Retry Test'], $repo); file_put_contents("{$repo}/index.php", "<?php echo 'artifact';\n"); retryGit($git, ['add', '-A'], $repo); retryGit($git, ['commit', '--quiet', '-m', 'fixture'], $repo); return ['path' => $repo, 'commit' => trim($git->run(['git', 'rev-parse', 'HEAD'], $repo, 30)['output'])]; }
/** @return array{process:resource,port:int,storage:string,log:string} */
function retryHub(array $scenario): array { $storage = retryTmp('hub'); $scenarioPath = "{$storage}/scenario.json"; file_put_contents($scenarioPath, json_encode($scenario, JSON_THROW_ON_ERROR)); $port = random_int(25000, 33000); $log = tempnam(sys_get_temp_dir(), 'fake-hub-retry-'); $env = array_merge(getenv() ?: [], ['FAKE_HUB_STORAGE_DIR' => $storage, 'FAKE_HUB_SCENARIO_PATH' => $scenarioPath]); $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/fake_hub_artifact_server.php'], [1 => ['file', $log, 'w'], 2 => ['file', $log, 'w']], $pipes, __DIR__, $env); if (!is_resource($process)) throw new RuntimeException('Unable to start fake Hub.'); for ($i = 0; $i < 50; $i++) { $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1); if ($socket !== false) { fclose($socket); return ['process' => $process, 'port' => $port, 'storage' => $storage, 'log' => $log]; } usleep(100000); } throw new RuntimeException('Fake Hub did not listen.'); }
function retryStop(array $hub): void { proc_terminate($hub['process']); proc_close($hub['process']); @unlink($hub['log']); retryRm($hub['storage']); }
/** @return array<string,mixed> */
function retryState(array $hub): array { $state = json_decode((string) file_get_contents("{$hub['storage']}/state.json"), true); return is_array($state) ? $state : []; }
/** @return array{result:array{succeeded:bool,message:string,failureCode:?string},state:array<string,mixed>,delays:array<int,int>} */
function retryUpload(array $hub, array $fixture, GitManager $git, string $job): array { $delays = []; $policy = new RetryPolicy(static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }); $logger = new Logger(retryTmp('logs')); $client = new Client("http://127.0.0.1:{$hub['port']}", 'retry-agent', 'test-token', 'test-secret', 2, $logger); $uploader = new ArtifactUploader(new LocalBuildRunner($git), new \LocalAgent\WorkspaceSourcePreparer($git), new ArtifactBuilder(), $client, $logger, retryTmp('workspaces'), 86400, [], $policy); $result = $uploader->buildAndUpload('retry-project', $fixture['path'], $fixture['commit'], 'main', $job); return ['result' => $result, 'state' => retryState($hub), 'delays' => $delays]; }

$git = new GitManager();
$fixture = retryRepo($git);

// The first create mutates canonical state but returns a retryable failure.
$hub = retryHub(['create_lost_response_attempts' => [1]]);
$case = retryUpload($hub, $fixture, $git, 'lost-create');
$artifact = array_values($case['state']['artifacts'])[0] ?? [];
checkRetry('lost create succeeds through ArtifactUploader', $case['result']['succeeded']);
checkRetry('lost create retries exactly once and preserves one artifact', ($case['state']['create_attempts'] ?? 0) === 2 && count($case['state']['artifacts'] ?? []) === 1);
checkRetry('lost create keeps canonical artifact id and completes upload', ($artifact['id'] ?? '') === 'artifact-1' && ($artifact['status'] ?? '') === 'available' && $case['delays'] === [2]);
retryStop($hub);

// The first upload writes valid bytes and makes the artifact available, then loses its response.
$hub = retryHub(['upload_lost_response_attempts' => [1]]);
$case = retryUpload($hub, $fixture, $git, 'lost-upload');
$artifact = array_values($case['state']['artifacts'])[0] ?? [];
checkRetry('lost upload succeeds through ArtifactUploader', $case['result']['succeeded']);
checkRetry('lost upload retries exactly once with no duplicate artifact', ($case['state']['create_attempts'] ?? 0) === 1 && ($case['state']['upload_attempts'] ?? 0) === 2 && count($case['state']['artifacts'] ?? []) === 1);
checkRetry('lost upload retains available canonical bytes and checksum', ($artifact['id'] ?? '') === 'artifact-1' && ($artifact['status'] ?? '') === 'available' && is_file("{$hub['storage']}/artifact-1.bin") && hash_file('sha256', "{$hub['storage']}/artifact-1.bin") === ($artifact['checksum'] ?? null) && $case['delays'] === [2]);
retryStop($hub);

foreach ([429, 500, 502, 503, 504] as $status) {
    $hub = retryHub(['create_statuses' => [$status]]);
    $case = retryUpload($hub, $fixture, $git, "retryable-create-{$status}");
    checkRetry("HTTP {$status} create retries through ArtifactUploader", $case['result']['succeeded'] && ($case['state']['create_attempts'] ?? 0) === 2 && $case['delays'] === [2]);
    retryStop($hub);
    $hub = retryHub(['upload_statuses' => [$status]]);
    $case = retryUpload($hub, $fixture, $git, "retryable-upload-{$status}");
    checkRetry("HTTP {$status} upload retries through ArtifactUploader", $case['result']['succeeded'] && ($case['state']['upload_attempts'] ?? 0) === 2 && $case['delays'] === [2]);
    retryStop($hub);
}

foreach ([400, 401, 403, 404, 409, 422] as $status) {
    $hub = retryHub(['create_statuses' => [$status]]);
    $case = retryUpload($hub, $fixture, $git, "nonretryable-{$status}");
    checkRetry("HTTP {$status} create has exactly one attempt", !$case['result']['succeeded'] && ($case['state']['create_attempts'] ?? 0) === 1 && $case['delays'] === []);
    retryStop($hub);
}

$hub = retryHub(['create_statuses' => [500, 500, 500]]);
$case = retryUpload($hub, $fixture, $git, 'retry-exhaustion');
checkRetry('retry exhaustion stops at three create attempts with injected 2s/5s backoff', !$case['result']['succeeded'] && ($case['state']['create_attempts'] ?? 0) === 3 && $case['delays'] === [2, 5]);
checkRetry('retry exhaustion final error is secret-safe', !str_contains($case['result']['message'], 'test-token') && !str_contains($case['result']['message'], 'test-secret'));
retryStop($hub);

// A real connection error reaches ArtifactUploader as Client RuntimeException and is retried by the same policy.
$delays = [];
$logger = new Logger(retryTmp('dead-logs'));
$dead = new ArtifactUploader(new LocalBuildRunner($git), new \LocalAgent\WorkspaceSourcePreparer($git), new ArtifactBuilder(), new Client('http://127.0.0.1:1', 'retry-agent', 'test-token', 'test-secret', 1, $logger), $logger, retryTmp('dead-workspaces'), 86400, [], new RetryPolicy(static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }));
$deadResult = $dead->buildAndUpload('retry-project', $fixture['path'], $fixture['commit'], 'main', 'transport');
checkRetry('connection/transport failure is retried then fails safely', !$deadResult['succeeded'] && $delays === [2, 5] && !str_contains($deadResult['message'], 'test-token') && !str_contains($deadResult['message'], 'test-secret'));

// Policy-level exception labels cover the Client abstraction's timeout/DNS/connect failure channel identically, with no wall-clock sleep.
foreach (['timeout', 'DNS resolution failure', 'connection reset'] as $label) { $attempts = 0; $delays = []; $policy = new RetryPolicy(static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }); try { $policy->run(static function () use (&$attempts, $label): never { $attempts++; throw new RetryableHubException($label); }); } catch (RuntimeException) {} checkRetry("{$label} is bounded at three attempts", $attempts === 3 && $delays === [2, 5]); }

retryRm(dirname($fixture['path']));
foreach ($failed as $name) echo "FAIL: {$name}\n";
echo "{$passed} passed, " . count($failed) . " failed.\n";
exit($failed === [] ? 0 : 1);
