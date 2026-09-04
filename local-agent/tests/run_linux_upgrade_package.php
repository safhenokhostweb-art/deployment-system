<?php

declare(strict_types=1);

use LocalAgent\DependencyRepositoryResolver;
use LocalAgent\GitManager;
use LocalAgent\LinuxUpgradePackageMaterializer;
use LocalAgent\SafeFilesystem;

require __DIR__ . '/../lib/GitManager.php';
require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/DependencyRepositoryResolver.php';
require __DIR__ . '/../lib/GitExactCommitSubtreeMaterializer.php';
require __DIR__ . '/../lib/LinuxUpgradePackageMaterializer.php';

$git = new GitManager(['fetch' => 60]);
$root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/linux-upgrade-package-test-' . bin2hex(random_bytes(6));
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
    $packageSource = realpath(__DIR__ . '/../../deployment-local-agent');
    if ($packageSource === false) {
        throw new RuntimeException('Package source is missing.');
    }
    SafeFilesystem::copyRecursiveSafely($packageSource, $packageSource, $seed . '/deployment-local-agent');
    $agentPath = $seed . '/deployment-local-agent/agent/agent.php';
    $currentAgent = (string) file_get_contents($agentPath);
    file_put_contents($agentPath, str_replace("LOCAL_AGENT_VERSION = '1.5.5'", "LOCAL_AGENT_VERSION = '1.5.4'", $currentAgent));

    $run(['git', 'init', '--initial-branch=master'], $seed);
    $run(['git', 'config', 'user.email', 'upgrade-package@example.test'], $seed);
    $run(['git', 'config', 'user.name', 'Upgrade Package Test'], $seed);
    $run(['git', 'add', 'deployment-local-agent'], $seed);
    $run(['git', 'commit', '-m', 'installed 1.5.4 package'], $seed);
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
    $materializer = new LinuxUpgradePackageMaterializer($git, new DependencyRepositoryResolver($git));
    $message = $failure(fn () => $materializer->withPackage($mapping, 'deployment-system', 'master', $oldCommit, '1.5.5', static fn () => null));
    $check('correct repository and commit with package 1.5.4 is rejected for expected 1.5.5', str_contains($message, 'version 1.5.4 does not match expected version 1.5.5'));

    file_put_contents($agentPath, $currentAgent);
    $run(['git', 'add', 'deployment-local-agent'], $seed);
    $run(['git', 'commit', '-m', 'official 1.5.5 package'], $seed);
    $newCommit = strtolower($run(['git', 'rev-parse', 'HEAD'], $seed));
    $run(['git', 'push', 'package-origin', 'master'], $seed);

    $observed = [];
    $invoke = static function (string $packageRoot, string $treeHash, string $sourceCommit) use (&$observed): string {
        $agent = (string) file_get_contents($packageRoot . '/agent/agent.php');
        $materializerSource = (string) file_get_contents($packageRoot . '/agent/lib/PinnedDependencyMaterializer.php');
        $observed = [
            'version' => str_contains($agent, "LOCAL_AGENT_VERSION = '1.5.5'"),
            'transport' => is_file($packageRoot . '/agent/lib/DependencyRepositoryResolver.php'),
            'deduplication' => str_contains($materializerSource, '$checkoutKey') && str_contains($materializerSource, '$this->repositories->resolve('),
            'tree' => $treeHash,
            'commit' => $sourceCommit,
        ];

        return $packageRoot;
    };
    $cleanedRoot = $materializer->withPackage($mapping, 'deployment-system', 'master', $newCommit, '1.5.5', $invoke);
    $check('official package reports canonical Agent version 1.5.5', $observed['version'] ?? false);
    $check('official package contains the validated dependency transport implementation', $observed['transport'] ?? false);
    $check('official package contains exact-SHA dependency materialization and checkout deduplication', $observed['deduplication'] ?? false);
    $check('payload is materialized from the requested exact commit', ($observed['commit'] ?? null) === $newCommit && preg_match('/^[0-9a-f]{40}$/', (string) ($observed['tree'] ?? '')) === 1);
    $check('mutable persistent workspace remains on installed 1.5.4 contents', str_contains((string) file_get_contents($workspace . '/deployment-local-agent/agent/agent.php'), "LOCAL_AGENT_VERSION = '1.5.4'"));
    $check('isolated package workspace is removed after use', !is_dir($cleanedRoot));

    $second = [];
    $materializer->withPackage($mapping, 'deployment-system', 'master', $newCommit, '1.5.5', static function (string $_root, string $tree, string $commit) use (&$second): void {
        $second = [$tree, $commit];
    });
    $check('repeated materialization is deterministic and idempotent', $second === [$observed['tree'], $newCommit]);

    $wrongIdentity = $mapping;
    $wrongIdentity['deployment-system']['repository_url'] = $seed;
    $message = $failure(fn () => $materializer->withPackage($wrongIdentity, 'deployment-system', 'master', $newCommit, '1.5.5', static fn () => null));
    $check('repository identity mismatch fails closed', str_contains($message, 'repository_identity_mismatch'));

    $message = $failure(fn () => $materializer->withPackage($mapping, 'deployment-system', 'master', str_repeat('f', 40), '1.5.5', static fn () => null));
    $check('unavailable exact commit fails closed with no mutable fallback', str_contains($message, 'validating the requested package commit'));
} finally {
    if (is_dir($root)) {
        SafeFilesystem::recursiveDelete($root);
    }
}

echo "\nLinux upgrade package: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
