<?php

declare(strict_types=1);

/** Focused Git push retry tests: real repo state, deterministic push attempts. */
require __DIR__ . '/../lib/GitCommandException.php';
require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/RetryPolicy.php';
require __DIR__ . '/../lib/GitPushFailureClassifier.php';
require __DIR__ . '/../lib/PushManager.php';

use LocalAgent\GitManager;
use LocalAgent\GitPushFailureClassifier;
use LocalAgent\PushManager;
use LocalAgent\RetryPolicy;

$passed = 0; $failed = [];
function pushCheck(string $name, bool $condition): void { global $passed, $failed; if ($condition) $passed++; else $failed[] = $name; }
function pushRm(string $path): void { if (!is_dir($path)) return; foreach (scandir($path) ?: [] as $name) { if ($name === '.' || $name === '..') continue; $item = "{$path}/{$name}"; if (is_dir($item)) pushRm($item); else { @chmod($item, 0666); @unlink($item); } } @rmdir($path); }
function pushGit(GitManager $git, array $command, string $cwd): array { return $git->run(array_merge(['git'], $command), $cwd, 30); }
/** @return array{root:string,repo:string,commit:string} */
function pushRepo(GitManager $git): array { $root = sys_get_temp_dir() . '/local-agent-push-retry-' . bin2hex(random_bytes(5)); $repo = "{$root}/repo"; mkdir($repo, 0777, true); foreach ([['init', '--quiet', '-b', 'main'], ['config', 'user.email', 'test@example.test'], ['config', 'user.name', 'Push Retry Test']] as $command) pushGit($git, $command, $repo); file_put_contents("{$repo}/file.txt", "stable\n"); pushGit($git, ['add', '.'], $repo); pushGit($git, ['commit', '--quiet', '-m', 'fixture'], $repo); return ['root' => $root, 'repo' => $repo, 'commit' => $git->lastCommitHash($repo)]; }
/** @return array{0:PushManager,1:array<int,int>} */
function pushManager(GitManager $git, array $outputs, array &$heads, array &$branches): array { $delays = []; $policy = new RetryPolicy(static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }); $attempt = 0; $runner = static function (string $repo, string $remote, string $branch) use (&$attempt, $outputs, &$heads, &$branches, $git): array { $attempt++; $heads[] = $git->lastCommitHash($repo); $branches[] = trim($git->run(['git', 'branch', '--show-current'], $repo, 30)['output']); return $outputs[$attempt - 1] ?? end($outputs); }; return [new PushManager($git, $policy, $runner), $delays]; }

$git = new GitManager(); $fixture = pushRepo($git); $beforeStatus = $git->run(['git', 'status', '--porcelain'], $fixture['repo'], 30)['output'];

$heads = $branches = []; $delays = [];
$policy = new RetryPolicy(static function (int $seconds) use (&$delays): void { $delays[] = $seconds; }); $attempt = 0;
$manager = new PushManager($git, $policy, static function (string $repo, string $remote, string $branch) use (&$attempt, &$heads, &$branches, $git): array { $attempt++; $heads[] = $git->lastCommitHash($repo); $branches[] = trim($git->run(['git', 'branch', '--show-current'], $repo, 30)['output']); return $attempt === 1 ? ['exitCode' => 128, 'output' => 'fatal: Connection reset by peer', 'timedOut' => false] : ['exitCode' => 0, 'output' => 'ok', 'timedOut' => false]; });
pushCheck('one transient push failure retries and succeeds', $manager->push($fixture['repo'], 'origin', 'main') === 'ok' && $attempt === 2 && $delays === [2]);
pushCheck('same commit and branch are used on every retry', $heads === [$fixture['commit'], $fixture['commit']] && $branches === ['main', 'main']);

$heads = $branches = []; $delays = []; $attempt = 0; $policy = new RetryPolicy(static function (int $seconds) use (&$delays): void { $delays[] = $seconds; });
$manager = new PushManager($git, $policy, static function (string $repo) use (&$attempt, &$heads, &$branches, $git): array { $attempt++; $heads[] = $git->lastCommitHash($repo); $branches[] = trim($git->run(['git', 'branch', '--show-current'], $repo, 30)['output']); return $attempt < 3 ? ['exitCode' => 128, 'output' => 'ssh: connection closed', 'timedOut' => false] : ['exitCode' => 0, 'output' => 'ok-third', 'timedOut' => false]; });
pushCheck('two transient failures reach third-attempt success', $manager->push($fixture['repo'], 'origin', 'main') === 'ok-third' && $attempt === 3 && $delays === [2, 5]);
pushCheck('third-attempt success never changes HEAD or branch', $heads === [$fixture['commit'], $fixture['commit'], $fixture['commit']] && $branches === ['main', 'main', 'main']);

$delays = []; $attempt = 0; $policy = new RetryPolicy(static function (int $seconds) use (&$delays): void { $delays[] = $seconds; });
$manager = new PushManager($git, $policy, static function () use (&$attempt): array { $attempt++; return ['exitCode' => 128, 'output' => 'fatal: temporary failure in name resolution', 'timedOut' => false]; }); $message = '';
try { $manager->push($fixture['repo'], 'origin', 'main'); } catch (RuntimeException $e) { $message = $e->getMessage(); }
pushCheck('transient exhaustion stops at three attempts with 2s/5s backoff', $attempt === 3 && $delays === [2, 5]);
pushCheck('transient exhaustion message is secret-safe', !str_contains($message, 'test-token') && !str_contains($message, 'test-secret'));

$nonRetryable = ['authentication failure' => 'fatal: Authentication failed', 'permission denied' => 'Permission denied (publickey).', 'repository not found' => 'remote: Repository not found.', 'non-fast-forward' => '! [rejected] main -> main (non-fast-forward)', 'protected branch' => 'remote: protected branch hook declined', 'policy rejection' => 'remote: push declined due to policy', 'invalid remote' => "fatal: 'bogus' does not appear to be a git repository"];
foreach ($nonRetryable as $label => $output) { $attempt = 0; $policy = new RetryPolicy(static function (int $seconds): void { throw new RuntimeException("unexpected sleep {$seconds}"); }); $manager = new PushManager($git, $policy, static function () use (&$attempt, $output): array { $attempt++; return ['exitCode' => 128, 'output' => $output, 'timedOut' => false]; }); try { $manager->push($fixture['repo'], 'origin', 'main'); } catch (RuntimeException) {} pushCheck("{$label} is not retried", $attempt === 1); }

foreach (['connection reset', 'Connection timed out', 'connection closed', 'temporary failure in name resolution', 'Could not resolve host: git.example', 'kex_exchange_identification: Connection closed', 'remote end hung up unexpectedly'] as $output) pushCheck("classifier accepts transient: {$output}", GitPushFailureClassifier::isRetryable($output));
foreach ($nonRetryable as $label => $output) pushCheck("classifier rejects {$label}", !GitPushFailureClassifier::isRetryable($output));
$afterStatus = $git->run(['git', 'status', '--porcelain'], $fixture['repo'], 30)['output'];
pushCheck('push retries leave the working tree unchanged', $beforeStatus === $afterStatus && $git->lastCommitHash($fixture['repo']) === $fixture['commit']);

pushRm($fixture['root']);
foreach ($failed as $name) echo "FAIL: {$name}\n";
echo "{$passed} passed, " . count($failed) . " failed.\n";
exit($failed === [] ? 0 : 1);
