<?php

namespace App\Services\Bootstrap;

use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper around three read-only `git` invocations, kept as its own
 * injectable class specifically so DeploymentBootstrapServiceTest can
 * fake it (via Laravel's Process::fake(), no real git repository needed
 * on this machine) rather than skip testing "stop on a dirty working
 * tree" entirely — see test #12 in docs/DEPLOYMENT_BOOTSTRAP_REPORT.md.
 * Never mutates anything — status/remote/branch only.
 */
class GitRepositoryInspector
{
    public function inspect(string $repositoryPath): GitRepositoryStatus
    {
        if (!is_dir($repositoryPath . '/.git')) {
            return new GitRepositoryStatus(exists: false, isClean: true, remoteUrl: null, currentBranch: null, changedFiles: []);
        }

        $status = Process::path($repositoryPath)->run(['git', 'status', '--porcelain']);
        $changedFiles = $status->successful()
            ? array_values(array_filter(array_map('rtrim', explode("\n", $status->output()))))
            : [];

        $remote = Process::path($repositoryPath)->run(['git', 'remote', 'get-url', 'origin']);
        $branch = Process::path($repositoryPath)->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);

        return new GitRepositoryStatus(
            exists: true,
            isClean: $changedFiles === [],
            remoteUrl: $remote->successful() ? trim($remote->output()) : null,
            currentBranch: $branch->successful() ? trim($branch->output()) : null,
            changedFiles: $changedFiles,
        );
    }
}
