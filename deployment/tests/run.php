<?php

declare(strict_types=1);

require dirname(__DIR__).'/lib/ReleasePreparer.php';

use DeploymentContract\ReleasePreparer;

$passed = 0;
$failed = 0;

function command(string $cwd, array $args): string
{
    $cmd = implode(' ', array_map('escapeshellarg', $args));
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptor, $pipes, $cwd);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    if ($code !== 0) {
        throw new RuntimeException("Command failed: {$cmd}\n{$out}\n{$err}");
    }
    return $out;
}

function writeJson(string $path, array $value): void
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

function fixture(array $overrides = []): array
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'release-contract-'.bin2hex(random_bytes(5));
    $remote = $root.'-remote.git';
    mkdir($root, 0777, true);
    command($root, ['git', 'init', '--initial-branch=main']);
    command($root, ['git', 'config', 'user.name', 'Release Contract Test']);
    command($root, ['git', 'config', 'user.email', 'release@example.test']);
    mkdir($root.'/.deployment', 0777, true);
    mkdir($root.'/src', 0777, true);
    mkdir($root.'/database/migrations', 0777, true);
    file_put_contents($root.'/src/app.php', "<?php\nreturn 1;\n");
    $policy = [
        'schema_version' => 1,
        'project_key' => 'fixture',
        'production_branch' => 'main',
        'remote' => 'origin',
        'allowed_release_paths' => ['src/**', 'database/migrations/**', 'composer.json', 'composer.lock'],
        'excluded_runtime_paths' => ['storage/**', '*.log'],
        'required_clean_state' => ['no_pre_staged_changes' => true],
        'checks' => [],
        'migration_policy' => [
            'paths' => ['database/migrations/**'],
            'review_required_patterns' => ['dropColumn\\s*\\(', 'Schema::drop\\s*\\('],
        ],
        'dependency_rules' => [['when' => 'composer.json', 'requires' => 'composer.lock']],
        'build' => ['required' => true, 'artifact' => 'exact-commit'],
        'health' => ['required' => true, 'endpoint' => '/health'],
        'metadata' => ['local_directory' => '.git/deployment/releases'],
    ];
    $policy = array_replace_recursive($policy, $overrides['policy'] ?? []);
    writeJson($root.'/.deployment/release-policy.json', $policy);
    command($root, ['git', 'add', '--', '.deployment/release-policy.json', 'src/app.php']);
    command($root, ['git', 'commit', '-m', 'baseline']);
    $base = trim(command($root, ['git', 'rev-parse', 'HEAD']));
    command(dirname($remote), ['git', 'init', '--bare', $remote]);
    command($root, ['git', 'remote', 'add', 'origin', $remote]);
    command($root, ['git', 'push', '-u', 'origin', 'main']);
    $task = [
        'schema_version' => 1,
        'task_id' => 'TASK-1',
        'summary' => 'Fixture release',
        'base_sha' => $base,
        'owned_paths' => ['src/**'],
        'risk_flags' => [],
    ];
    $task = array_replace_recursive($task, $overrides['task'] ?? []);
    writeJson($root.'/.deployment/release-task.json', $task);
    return [$root, $remote, $base];
}

function expectFailure(callable $callback, string $contains): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if (! str_contains($exception->getMessage(), $contains)) {
            throw new RuntimeException("Expected failure containing '{$contains}', got: {$exception->getMessage()}");
        }
        return;
    }
    throw new RuntimeException("Expected failure containing '{$contains}', but preparation succeeded.");
}

function test(string $name, callable $callback): void
{
    global $passed, $failed;
    $filter = getenv('RELEASE_CONTRACT_TEST_FILTER');
    if (is_string($filter) && $filter !== '' && ! str_contains($name, $filter)) {
        return;
    }
    try {
        $callback();
        $passed++;
        echo "PASS {$name}\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "FAIL {$name}: {$exception->getMessage()}\n";
    }
}

test('one coherent task produces an automatic candidate', function (): void {
    [$root] = fixture();
    file_put_contents($root.'/src/app.php', "<?php\nreturn 2;\n");
    $result = (new ReleasePreparer($root))->run(false);
    if ($result['status'] !== 'READY' || $result['changed_files'] !== ['src/app.php']) {
        throw new RuntimeException('Unexpected candidate.');
    }
});

test('unrelated dirty files fail closed', function (): void {
    [$root] = fixture();
    file_put_contents($root.'/src/app.php', "<?php\nreturn 2;\n");
    file_put_contents($root.'/other.php', "<?php\n");
    expectFailure(fn () => (new ReleasePreparer($root))->run(false), 'MIXED WORKTREE');
});

test('untracked runtime files are excluded', function (): void {
    [$root] = fixture();
    mkdir($root.'/storage', 0777, true);
    file_put_contents($root.'/storage/runtime.log', 'runtime');
    file_put_contents($root.'/src/app.php', "<?php\nreturn 2;\n");
    $result = (new ReleasePreparer($root))->run(false);
    if ($result['excluded_untracked_files'] !== ['storage/runtime.log']) {
        throw new RuntimeException('Runtime exclusion was not reported.');
    }
});

test('unknown untracked source files fail closed', function (): void {
    [$root] = fixture();
    file_put_contents($root.'/src/app.php', "<?php\nreturn 2;\n");
    file_put_contents($root.'/src/unowned.php', "<?php\n");
    writeJson($root.'/.deployment/release-task.json', [
        'task_id' => 'TASK-1', 'summary' => 'Fixture',
        'base_sha' => trim(command($root, ['git', 'rev-parse', 'HEAD'])),
        'owned_paths' => ['src/app.php'],
    ]);
    expectFailure(fn () => (new ReleasePreparer($root))->run(false), 'MIXED WORKTREE');
});

test('safe migrations are candidates and unsafe migrations are blocked', function (): void {
    [$root] = fixture(['task' => ['owned_paths' => ['database/migrations/**']]]);
    file_put_contents($root.'/database/migrations/001_safe.php', "<?php Schema::table('users', function (\$table) { \$table->string('name')->nullable(); });\n");
    $result = (new ReleasePreparer($root))->run(false);
    if ($result['migration_files'] !== ['database/migrations/001_safe.php']) {
        throw new RuntimeException('Safe migration missing.');
    }
    file_put_contents($root.'/database/migrations/002_unsafe.php', "<?php Schema::table('users', fn (\$table) => \$table->dropColumn('name'));\n");
    expectFailure(fn () => (new ReleasePreparer($root))->run(false), 'MIGRATION REVIEW REQUIRED');
});

test('failing tests prevent a commit', function (): void {
    [$root, , $base] = fixture(['policy' => ['checks' => [['name' => 'forced-failure', 'type' => 'test', 'command' => 'php -r "exit(7);"']]]]);
    file_put_contents($root.'/src/app.php', "<?php\nreturn 2;\n");
    expectFailure(fn () => (new ReleasePreparer($root))->run(true), 'TEST FAILURE');
    if (trim(command($root, ['git', 'rev-parse', 'HEAD'])) !== $base) {
        throw new RuntimeException('A commit was created after test failure.');
    }
});

test('prepare stages only intended paths, pushes, and writes metadata', function (): void {
    [$root, $remote, $base] = fixture();
    mkdir($root.'/storage', 0777, true);
    file_put_contents($root.'/storage/runtime.log', 'leave me');
    file_put_contents($root.'/src/app.php', "<?php\nreturn 3;\n");
    $result = (new ReleasePreparer($root))->run(true);
    $sha = $result['commit_sha'];
    $remoteHead = trim(command($root, ['git', '--git-dir='.$remote, 'rev-parse', 'refs/heads/main']));
    $files = trim(command($root, ['git', 'diff', '--name-only', $base.'..'.$sha]));
    if ($remoteHead !== $sha || $files !== 'src/app.php') {
        throw new RuntimeException('Exact commit was not pushed or included extra files.');
    }
    if (! is_file($root.'/.git/deployment/releases/'.$sha.'.json')) {
        throw new RuntimeException('Commit-keyed metadata was not written.');
    }
    if (! str_contains(command($root, ['git', 'status', '--porcelain']), 'storage/')) {
        throw new RuntimeException('Excluded runtime path was staged (git add -A behavior).');
    }
});

test('prepare fast-forwards a stale persistent workspace and creates one exact release commit', function (): void {
    [$root, $remote] = fixture();
    $upstream = $root.'-upstream';
    command(dirname($upstream), ['git', 'clone', '--branch', 'main', $remote, $upstream]);
    command($upstream, ['git', 'config', 'user.name', 'Upstream Test']);
    command($upstream, ['git', 'config', 'user.email', 'upstream@example.test']);
    file_put_contents($upstream.'/upstream.txt', "new upstream state\n");
    command($upstream, ['git', 'add', '--', 'upstream.txt']);
    command($upstream, ['git', 'commit', '-m', 'advance production']);
    command($upstream, ['git', 'push', 'origin', 'main']);
    $upstreamHead = trim(command($upstream, ['git', 'rev-parse', 'HEAD']));

    file_put_contents($root.'/src/app.php', "<?php\nreturn 4;\n");
    $result = (new ReleasePreparer($root))->run(true);
    $releaseHead = $result['commit_sha'];
    $parent = trim(command($root, ['git', 'rev-parse', $releaseHead.'^']));
    $files = trim(command($root, ['git', 'diff', '--name-only', $parent.'..'.$releaseHead]));
    $commitCount = trim(command($root, ['git', 'rev-list', '--count', $upstreamHead.'..'.$releaseHead]));

    if ($result['base_sha'] !== $upstreamHead || $parent !== $upstreamHead || $files !== 'src/app.php' || $commitCount !== '1') {
        throw new RuntimeException('Stale workspace did not produce one exact commit on the authoritative remote HEAD.');
    }
    if (! is_file($root.'/upstream.txt') || trim(command($root, ['git', '--git-dir='.$remote, 'rev-parse', 'refs/heads/main'])) !== $releaseHead) {
        throw new RuntimeException('Workspace or remote was not advanced automatically.');
    }
});

test('pre-existing unpushed commits fail closed instead of producing ambiguous factual metadata', function (): void {
    [$root] = fixture();
    file_put_contents($root.'/src/app.php', "<?php\nreturn 5;\n");
    command($root, ['git', 'add', '--', 'src/app.php']);
    command($root, ['git', 'commit', '-m', 'premature local commit']);
    expectFailure(fn () => (new ReleasePreparer($root))->run(false), 'UNPUSHED COMMITS');
});

test('provider identity is absent from the contract', function (): void {
    [$root] = fixture();
    $contract = file_get_contents($root.'/.deployment/release-policy.json').file_get_contents($root.'/.deployment/release-task.json');
    foreach (['openai', 'anthropic', 'codex', 'claude'] as $provider) {
        if (stripos($contract, $provider) !== false) {
            throw new RuntimeException("Provider identity leaked into contract: {$provider}");
        }
    }
});

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
