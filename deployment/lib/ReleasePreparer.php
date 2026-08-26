<?php

declare(strict_types=1);

namespace DeploymentContract;

use RuntimeException;

final class ReleasePreparer
{
    private array $policy;
    private array $task;

    public function __construct(private readonly string $root, private readonly string $taskPath = '.deployment/release-task.json')
    {
        $this->policy = $this->jsonFile('.deployment/release-policy.json', 'release policy');
        $this->task = $this->jsonFile($taskPath, 'active release task');
    }

    public function run(bool $prepare = false): array
    {
        $this->assertContract();
        $branch = trim($this->git(['branch', '--show-current']));
        $expected = (string) $this->policy['production_branch'];
        $this->block($branch !== $expected, "PRODUCTION BRANCH MISMATCH: expected {$expected}, found {$branch}");
        $this->block($this->git(['rev-parse', '-q', '--verify', 'MERGE_HEAD'], true) !== '', 'UNRESOLVED MERGE STATE');

        $head = strtolower(trim($this->git(['rev-parse', 'HEAD'])));
        $base = strtolower((string) $this->task['base_sha']);
        $this->block(preg_match('/^[0-9a-f]{40}$/', $base) !== 1, 'Active release task has an invalid base_sha.');
        $this->git(['merge-base', '--is-ancestor', $base, $head]);

        $entries = $this->statusEntries();
        foreach ($entries as $entry) {
            $xy = $entry['xy'];
            $this->block(str_contains($xy, 'U') || in_array($xy, ['AA', 'DD'], true), 'UNRESOLVED MERGE STATE');
            if (($this->policy['required_clean_state']['no_pre_staged_changes'] ?? true) && $xy[0] !== ' ' && $xy[0] !== '?') {
                throw new RuntimeException('PRE-STAGED CHANGES — RELEASE NOT SAFE: '.$entry['path']);
            }
        }

        $committed = $this->nullLines($this->git(['diff', '--name-only', '-z', $base.'..'.$head]));
        $dirty = [];
        $excludedUntracked = [];
        foreach ($entries as $entry) {
            $path = $entry['path'];
            // The active ownership declaration is control-plane state, not
            // release content. It is the one known untracked file that the
            // contract itself may exclude without guessing.
            if ($entry['xy'] === '??' && $path === str_replace('\\', '/', $this->taskPath)) {
                continue;
            }
            if ($this->matches($path, $this->policy['excluded_runtime_paths'] ?? [])) {
                if ($entry['xy'] === '??') {
                    $excludedUntracked[] = $path;
                    continue;
                }
                throw new RuntimeException('DIRTY GENERATED/RUNTIME DATA: '.$path);
            }
            $dirty[] = $path;
        }

        $candidate = array_values(array_unique(array_merge($committed, $dirty)));
        sort($candidate, SORT_STRING);
        $unowned = array_values(array_filter($candidate, fn (string $path): bool => ! $this->matches($path, $this->task['owned_paths'])));
        $disallowed = array_values(array_filter($candidate, fn (string $path): bool => ! $this->matches($path, $this->policy['allowed_release_paths'])));
        if ($unowned !== [] || $disallowed !== []) {
            throw new RuntimeException("MIXED WORKTREE — RELEASE NOT SAFE\n".json_encode([
                'unowned_files' => $unowned,
                'disallowed_files' => $disallowed,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $this->block($candidate === [], 'NO RELEASE CANDIDATE: no owned changes exist since the baseline.');

        $this->checkDependencies($candidate);
        $dependencyCommits = $this->checkGitDependencies();
        $migrations = $this->checkMigrations($candidate);
        $tests = array_merge($this->checkSyntax($candidate), $this->runChecks());
        $summary = [
            'status' => 'READY',
            'project_key' => $this->policy['project_key'],
            'task_id' => $this->task['task_id'],
            'summary' => $this->task['summary'],
            'base_sha' => $base,
            'branch' => $branch,
            'changed_files' => $candidate,
            'migration_files' => $migrations,
            'dependency_commits' => $dependencyCommits,
            'test_results' => $tests,
            'build_result' => [
                'required' => (bool) ($this->policy['build']['required'] ?? false),
                'status' => 'pending',
                'contract' => $this->policy['build']['artifact'] ?? null,
            ],
            'health_contract' => $this->policy['health'] ?? null,
            'risk_flags' => array_values($this->task['risk_flags'] ?? []),
            'excluded_untracked_files' => $excludedUntracked,
            'release_ready' => true,
        ];
        if (! $prepare) {
            return $summary;
        }

        foreach ($dirty as $path) {
            $this->git(['add', '--', $path]);
        }
        $message = sprintf('release(%s): %s', $this->policy['project_key'], $this->task['task_id']);
        $body = (string) $this->task['summary']."\n\nRelease-Base: {$base}\nRelease-Task: ".$this->task['task_id'];
        $this->git(['commit', '--allow-empty', '-m', $message, '-m', $body]);
        $commit = strtolower(trim($this->git(['rev-parse', 'HEAD'])));
        $summary['commit_sha'] = $commit;
        $summary['created_timestamp'] = gmdate('c');
        $summary['metadata_version'] = 1;
        $directory = $this->absolute((string) ($this->policy['metadata']['local_directory'] ?? '.git/deployment/releases'));
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create release metadata directory.');
        }
        $metadataPath = $directory.DIRECTORY_SEPARATOR.$commit.'.json';
        $this->writeMetadata($metadataPath, $summary);
        try {
            $this->git(['push', (string) ($this->policy['remote'] ?? 'origin'), 'HEAD:'.$branch]);
        } catch (RuntimeException $exception) {
            $summary['status'] = 'PUSH FAILURE';
            $summary['release_ready'] = false;
            $summary['risk_flags'][] = 'PUSH_FAILED';
            $this->writeMetadata($metadataPath, $summary);
            throw $exception;
        }
        $this->deliverMetadata($summary, $metadataPath);
        return $summary;
    }

    private function assertContract(): void
    {
        foreach (['project_key', 'production_branch', 'allowed_release_paths', 'excluded_runtime_paths', 'checks', 'migration_policy', 'build', 'health'] as $field) {
            $this->block(! array_key_exists($field, $this->policy), "Release policy is missing {$field}.");
        }
        foreach (['task_id', 'summary', 'base_sha', 'owned_paths'] as $field) {
            $this->block(! array_key_exists($field, $this->task), "Active release task is missing {$field}.");
        }
        $this->block(! is_array($this->task['owned_paths']) || $this->task['owned_paths'] === [], 'Active release task must own at least one explicit path.');
    }

    private function checkDependencies(array $candidate): void
    {
        foreach ($this->policy['dependency_rules'] ?? [] as $rule) {
            if ($this->anyMatches($candidate, (string) $rule['when']) && ! $this->anyMatches($candidate, (string) $rule['requires'])) {
                throw new RuntimeException('PACKAGE BOUNDARY FAILURE: '.$rule['when'].' requires '.$rule['requires'].' in the same release.');
            }
        }
    }

    /** @return array<string, string> */
    private function checkGitDependencies(): array
    {
        $commits = [];
        foreach ($this->policy['git_dependencies'] ?? [] as $dependency) {
            $path = str_replace('{project_root}', rtrim($this->root, '/\\'), (string) $dependency['path']);
            $branch = trim($this->gitAt($path, ['branch', '--show-current']));
            $expected = (string) $dependency['branch'];
            $this->block($branch !== $expected, "DEPENDENCY BRANCH MISMATCH: {$path} expected {$expected}, found {$branch}");
            $this->block(trim($this->gitAt($path, ['status', '--porcelain'])) !== '', 'DIRTY DEPENDENCY WORKTREE: '.$path);
            $this->gitAt($path, ['rev-parse', '--verify', '@{upstream}']);
            $unpushed = (int) trim($this->gitAt($path, ['rev-list', '--count', '@{upstream}..HEAD']));
            $this->block($unpushed !== 0, 'UNPUSHED DEPENDENCY COMMIT: '.$path);
            $commits[(string) ($dependency['name'] ?? $path)] = strtolower(trim($this->gitAt($path, ['rev-parse', 'HEAD'])));
        }
        return $commits;
    }

    private function checkSyntax(array $candidate): array
    {
        $results = [];
        foreach ($candidate as $path) {
            if (! str_ends_with(strtolower($path), '.php') || ! is_file($this->absolute($path))) {
                continue;
            }
            $started = microtime(true);
            $process = proc_open(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($this->absolute($path)), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
            $this->block(! is_resource($process), 'SYNTAX FAILURE: could not inspect '.$path);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $results[] = ['name' => 'syntax:'.$path, 'type' => 'syntax', 'command' => 'php -l '.$path, 'exit_code' => $exit, 'duration_ms' => (int) round((microtime(true) - $started) * 1000)];
            $this->block($exit !== 0, 'SYNTAX FAILURE: '.$path."\n".trim($output));
        }
        return $results;
    }

    private function checkMigrations(array $candidate): array
    {
        $paths = $this->policy['migration_policy']['paths'] ?? [];
        $migrations = array_values(array_filter($candidate, fn (string $path): bool => $this->matches($path, $paths)));
        foreach ($migrations as $path) {
            $content = @file_get_contents($this->absolute($path));
            $this->block($content === false, 'MIGRATION REVIEW REQUIRED: cannot inspect '.$path);
            foreach ($this->policy['migration_policy']['review_required_patterns'] ?? [] as $pattern) {
                if (@preg_match('/'.$pattern.'/i', $content) === 1) {
                    throw new RuntimeException('MIGRATION REVIEW REQUIRED: '.$path.' matched '.$pattern);
                }
            }
        }
        return $migrations;
    }

    private function runChecks(): array
    {
        $results = [];
        foreach ($this->policy['checks'] as $check) {
            $started = microtime(true);
            $command = (string) $check['command'];
            $workingDirectory = isset($check['working_directory'])
                ? $this->absolute((string) $check['working_directory'])
                : $this->root;
            $this->block(! is_dir($workingDirectory), 'TEST FAILURE: invalid working directory for '.$check['name']);
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory);
            $this->block(! is_resource($process), 'TEST FAILURE: could not start '.$check['name']);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
            $results[] = [
                'name' => $check['name'],
                'type' => $check['type'] ?? 'test',
                'command' => $command,
                'exit_code' => $exitCode,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            if ($exitCode !== 0) {
                throw new RuntimeException("TEST FAILURE: {$check['name']}\n".trim($stdout."\n".$stderr));
            }
        }
        return $results;
    }

    private function statusEntries(): array
    {
        $records = $this->nullLines($this->git(['status', '--porcelain=v1', '-z', '--untracked-files=all']));
        $entries = [];
        for ($index = 0; $index < count($records); $index++) {
            $record = $records[$index];
            if (strlen($record) < 4) {
                continue;
            }
            $xy = substr($record, 0, 2);
            $path = substr($record, 3);
            if ($xy[0] === 'R' || $xy[0] === 'C' || $xy[1] === 'R' || $xy[1] === 'C') {
                $index++;
            }
            $entries[] = ['xy' => $xy, 'path' => str_replace('\\', '/', $path)];
        }
        return $entries;
    }

    private function anyMatches(array $paths, string $pattern): bool
    {
        foreach ($paths as $path) {
            if ($this->matches($path, [$pattern])) {
                return true;
            }
        }
        return false;
    }

    private function matches(string $path, array|string $patterns): bool
    {
        foreach ((array) $patterns as $pattern) {
            $quoted = preg_quote(str_replace('\\', '/', (string) $pattern), '#');
            $regex = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '[^/]'], $quoted);
            if (preg_match('#^'.$regex.'$#', str_replace('\\', '/', $path)) === 1) {
                return true;
            }
        }
        return false;
    }

    private function jsonFile(string $path, string $label): array
    {
        $absolute = $this->absolute($path);
        $this->block(! is_file($absolute), "Missing {$label}: {$path}");
        $decoded = json_decode((string) file_get_contents($absolute), true);
        $this->block(! is_array($decoded), "Invalid JSON in {$label}: {$path}");
        return $decoded;
    }

    private function git(array $arguments, bool $allowFailure = false): string
    {
        $command = 'git '.implode(' ', array_map(fn (string $argument): string => escapeshellarg($argument), $arguments));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        $this->block(! is_resource($process), 'Unable to execute Git.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 && ! $allowFailure) {
            throw new RuntimeException('Git command failed: '.trim($stderr ?: $stdout));
        }
        return $exitCode === 0 ? $stdout : '';
    }

    private function gitAt(string $path, array $arguments): string
    {
        $command = 'git '.implode(' ', array_map(fn (string $argument): string => escapeshellarg($argument), $arguments));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $path);
        $this->block(! is_resource($process), 'Unable to execute dependency Git.');
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('Dependency Git command failed: '.trim($stderr ?: $stdout));
        }
        return $stdout;
    }

    private function nullLines(string $value): array
    {
        return array_values(array_filter(explode("\0", $value), fn (string $part): bool => $part !== ''));
    }

    private function absolute(string $path): string
    {
        return rtrim($this->root, '/\\').DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function writeMetadata(string $path, array $metadata): void
    {
        if (file_put_contents($path, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write release metadata.');
        }
    }

    private function deliverMetadata(array &$metadata, string $metadataPath): void
    {
        $url = trim((string) getenv('DEPLOYMENT_RELEASE_METADATA_URL'));
        if ($url === '') {
            return;
        }
        $secret = (string) getenv('DEPLOYMENT_RELEASE_SECRET');
        if ($secret === '') {
            throw new RuntimeException('RELEASE METADATA NOT RECORDED: DEPLOYMENT_RELEASE_SECRET is missing.');
        }
        $body = json_encode($metadata, JSON_UNESCAPED_SLASHES);
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Deployment-Signature-256: {$signature}\r\n",
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($response === false || preg_match('/\s2\d\d\s/', $statusLine) !== 1) {
            $metadata['status'] = 'METADATA FAILURE';
            $metadata['release_ready'] = false;
            $metadata['risk_flags'][] = 'METADATA_NOT_RECORDED';
            $this->writeMetadata($metadataPath, $metadata);
            throw new RuntimeException('RELEASE METADATA NOT RECORDED: '.$statusLine);
        }
    }

    private function block(bool $condition, string $message): void
    {
        if ($condition) {
            throw new RuntimeException($message);
        }
    }
}
