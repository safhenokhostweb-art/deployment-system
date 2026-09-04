<?php

declare(strict_types=1);

use LocalAgent\DependencyRepositoryResolver;
use LocalAgent\GitManager;
use LocalAgent\SafeFilesystem;
use LocalAgent\ServerAgentPackageMaterializer;

require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/DependencyRepositoryResolver.php';
require __DIR__ . '/../lib/GitExactCommitSubtreeMaterializer.php';
require __DIR__ . '/../lib/ServerAgentPackageMaterializer.php';

/**
 * Mirrors run_linux_upgrade_package.php's structure exactly, for the
 * independent Server Agent (agent/) package instead of the Local
 * Agent's own deployment-local-agent/ package — see
 * ServerAgentPackageMaterializer's own docblock for why these are
 * deliberately separate classes sharing one reusable core
 * (GitExactCommitSubtreeMaterializer).
 */
$git = new GitManager(['fetch' => 60]);
$root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/server-agent-package-test-' . bin2hex(random_bytes(6));
$seed = $root . '/seed';
$remote = $root . '/remote.git';
$workspace = $root . '/persistent-workspace';
$passed = 0;
$failed = 0;
$check = static function (string $name, bool $result) use (&$passed, &$failed): void {
    $result ? $passed++ : $failed++;
    echo ($result ? 'PASS' : 'FAIL') . ": {$name}\n";
};
$run = static function (array $command, string $cwd) use ($git): string {
    $result = $git->run($command, $cwd, 120);
    if ($result['exitCode'] !== 0) {
        throw new RuntimeException(trim($result['output']));
    }

    return trim($result['output']);
};
$failure = static function (callable $operation): string {
    try {
        $operation();
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return '';
};

try {
    mkdir($seed, 0700, true);
    $packageSource = realpath(__DIR__ . '/../../agent');
    if ($packageSource === false) {
        throw new RuntimeException('Package source is missing.');
    }
    SafeFilesystem::copyRecursiveSafely($packageSource, $packageSource, $seed . '/agent');
    // The hotfix-1.3.4-p1-* files are pre-existing, unrelated WIP in
    // agent/install/ and agent/tests/ — never part of the shipped
    // Server Agent runtime tree, and excluded here the same way a real
    // package build would only ever archive agent.php + lib/.
    SafeFilesystem::recursiveDelete($seed . '/agent/install');
    SafeFilesystem::recursiveDelete($seed . '/agent/tests');
    SafeFilesystem::recursiveDelete($seed . '/agent/release');
    $agentPath = $seed . '/agent/agent.php';
    $currentAgent = (string) file_get_contents($agentPath);
    file_put_contents($agentPath, str_replace("AGENT_VERSION = '1.4.1'", "AGENT_VERSION = '1.4.0'", $currentAgent));

    $run(['git', 'init', '--initial-branch=master'], $seed);
    $run(['git', 'config', 'user.email', 'server-agent-package@example.test'], $seed);
    $run(['git', 'config', 'user.name', 'Server Agent Package Test'], $seed);
    $run(['git', 'add', 'agent'], $seed);
    $run(['git', 'commit', '-m', 'installed 1.4.0 runtime'], $seed);
    $oldCommit = strtolower($run(['git', 'rev-parse', 'HEAD'], $seed));
    $run(['git', 'clone', '--bare', $seed, $remote], $root);
    $run(['git', 'clone', $remote, $workspace], $root);
    $run(['git', 'remote', 'add', 'package-origin', $remote], $seed);

    $mapping = [
        'deployment-system' => [
            'repository_url' => $remote,
            'source_workspace_path' => $workspace,
            'remote_name' => 'origin',
            'branch' => 'master',
        ],
        'unrelated-project' => ['repository_url' => 'never-used'],
    ];
    $materializer = new ServerAgentPackageMaterializer($git, new DependencyRepositoryResolver($git));
    $message = $failure(fn () => $materializer->withPackage($mapping, 'deployment-system', 'master', $oldCommit, '1.4.1', static fn () => null));
    $check('correct repository and commit with runtime 1.4.0 is rejected for expected 1.4.1', str_contains($message, 'version 1.4.0 does not match expected version 1.4.1'));

    file_put_contents($agentPath, $currentAgent);
    $run(['git', 'add', 'agent'], $seed);
    $run(['git', 'commit', '-m', 'official 1.4.1 runtime'], $seed);
    $newCommit = strtolower($run(['git', 'rev-parse', 'HEAD'], $seed));
    $run(['git', 'push', 'package-origin', 'master'], $seed);

    $observed = [];
    $invoke = static function (string $packageRoot, string $treeHash, string $sourceCommit) use (&$observed): string {
        $agent = (string) file_get_contents($packageRoot . '/agent.php');
        $pipelineExecutor = (string) file_get_contents($packageRoot . '/lib/PipelineExecutor.php');
        $observed = [
            'version' => str_contains($agent, "AGENT_VERSION = '1.4.1'"),
            'pipeline_executor_present' => is_file($packageRoot . '/lib/PipelineExecutor.php'),
            'sequence_fix_present' => str_contains($pipelineExecutor, 'withoutUnconditionalSuccessSuffix'),
            'tree' => $treeHash,
            'commit' => $sourceCommit,
        ];

        return $packageRoot;
    };
    $cleanedRoot = $materializer->withPackage($mapping, 'deployment-system', 'master', $newCommit, '1.4.1', $invoke);
    $check('official package reports canonical Server Agent version 1.4.1', $observed['version'] ?? false);
    $check('official package contains lib/PipelineExecutor.php', $observed['pipeline_executor_present'] ?? false);
    $check('official package carries the Deployment #183/#184 sequence/masking fix', $observed['sequence_fix_present'] ?? false);
    $check('payload is materialized from the requested exact commit', ($observed['commit'] ?? null) === $newCommit && preg_match('/^[0-9a-f]{40}$/', (string) ($observed['tree'] ?? '')) === 1);
    $check('mutable persistent workspace remains on installed 1.4.0 contents', str_contains((string) file_get_contents($workspace . '/agent/agent.php'), "AGENT_VERSION = '1.4.0'"));
    $check('isolated package workspace is removed after use', !is_dir($cleanedRoot));

    $second = [];
    $materializer->withPackage($mapping, 'deployment-system', 'master', $newCommit, '1.4.1', static function (string $_root, string $tree, string $commit) use (&$second): void {
        $second = [$tree, $commit];
    });
    $check('repeated materialization is deterministic and idempotent', $second === [$observed['tree'], $newCommit]);

    $wrongIdentity = $mapping;
    $wrongIdentity['deployment-system']['repository_url'] = $seed;
    $message = $failure(fn () => $materializer->withPackage($wrongIdentity, 'deployment-system', 'master', $newCommit, '1.4.1', static fn () => null));
    $check('repository identity mismatch fails closed', str_contains($message, 'repository_identity_mismatch'));

    $message = $failure(fn () => $materializer->withPackage($mapping, 'deployment-system', 'master', str_repeat('f', 40), '1.4.1', static fn () => null));
    $check('unavailable exact commit fails closed with no mutable fallback', str_contains($message, 'validating the requested package commit'));

    $message = $failure(fn () => $materializer->withPackage($mapping, 'unrelated-project', 'master', $newCommit, '1.4.1', static fn () => null));
    $check('an unknown repository mapping fails closed', str_contains($message, 'invalid Server Agent package repository mapping'));
} finally {
    if (is_dir($root)) {
        SafeFilesystem::recursiveDelete($root);
    }
}

echo "\nServer Agent package: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
