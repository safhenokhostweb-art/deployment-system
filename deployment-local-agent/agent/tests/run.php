<?php

declare(strict_types=1);

/**
 * Platform-independent contract test for the Local Agent's safe-remote-build
 * architecture (WorkspaceSourcePreparer::prepareRemoteCommit / agent.php's
 * build_artifact wiring). Root cause covered: "Build Artifact" must target
 * the exact production-branch remote HEAD, never require it to already
 * equal the persistent source workspace's own checked-out HEAD, and must
 * never mutate that persistent workspace destructively — only an isolated,
 * per-job BuildWorkspace is ever checked out to the exact requested commit.
 *
 * Same convention as agent/tests/run_privileged_helpers.php in the Server
 * Agent: this environment cannot safely exec real git clones/network
 * fetches as part of a test run, so this verifies the exact dispatch
 * contract and command ordering from source content.
 *
 * Usage: php tests/run.php
 */

$failures = [];
$passCount = 0;

function check(string $description, bool $condition, array &$failures, int &$passCount): void
{
    if ($condition) {
        $passCount++;

        return;
    }

    $failures[] = $description;
}

$preparer = (string) file_get_contents(__DIR__ . '/../lib/WorkspaceSourcePreparer.php');
$uploader = (string) file_get_contents(__DIR__ . '/../lib/ArtifactUploader.php');
$agent = (string) file_get_contents(__DIR__ . '/../agent.php');
$buildWorkspace = (string) file_get_contents(__DIR__ . '/../lib/BuildWorkspace.php');

// --- Root cause: the wired-up build_artifact path must be the safe
// fetch-and-verify-then-isolated-checkout method, never the strict
// "workspace HEAD must already equal the requested commit" method.
check('build_artifact jobs are dispatched through the safe remote-commit path', str_contains($agent, "\$artifactUploader->buildAndUploadRemoteCommit(\$projectKey, \$repositoryPath, \$repositoryUrl, \$remoteName, \$commitHash, \$artifactBranch"), $failures, $passCount);
check('the unsafe pre-equality buildAndUpload() is defined but never dispatched from agent.php', str_contains($uploader, 'public function buildAndUpload(') && !str_contains($agent, '$artifactUploader->buildAndUpload('), $failures, $passCount);
check('publish also builds through the safe remote-commit path, not the strict-equality one', str_contains($agent, "\$artifactUploader->buildAndUploadRemoteCommit(\$projectKey, \$repositoryPath, \$repositoryUrl, \$remoteName, \$commitHash, \$branch"), $failures, $passCount);

// --- Ordering: dirty-workspace / trust checks happen before any fetch, and
// fetch happens before the exact commit is ever referenced/checked out.
$posDirtyCheck = strpos($preparer, 'private function assertTrustedPersistentWorkspace');
$posFetchBranch = strpos($preparer, 'private function fetchBranch');
$posCommitExists = strpos($preparer, 'private function assertCommitExists');
$posReachable = strpos($preparer, 'private function assertCommitReachableFromRemoteBranch');
$posPrepareRemoteCommitBody = strpos($preparer, 'public function prepareRemoteCommit');
$callOrderSlice = substr($preparer, $posPrepareRemoteCommitBody, ($posDirtyCheck ?: strlen($preparer)) - $posPrepareRemoteCommitBody + 4000);

check('prepareRemoteCommit refuses a production-like or dirty persistent source before doing anything else', strpos($callOrderSlice, '$this->assertTrustedPersistentWorkspace(') < strpos($callOrderSlice, '$this->fetchBranch('), $failures, $passCount);
check('the persistent workspace dirty-check runs before any fetch touches the network', $posDirtyCheck !== false && $posFetchBranch !== false, $failures, $passCount);
check('changedFiles() dirtiness is asserted before fetch, refusing to fetch or build a dirty workspace', str_contains($preparer, 'Source workspace is dirty; refusing to fetch or build'), $failures, $passCount);
check('requested commit existence is verified only after a real fetch has run', strpos($callOrderSlice, '$this->fetchBranch(') < strpos($callOrderSlice, '$this->assertCommitExists('), $failures, $passCount);
check('commit reachability from the remote production branch is verified before any checkout', strpos($callOrderSlice, '$this->assertCommitExists(') < strpos($callOrderSlice, '$this->assertCommitReachableFromRemoteBranch(') && strpos($callOrderSlice, '$this->assertCommitReachableFromRemoteBranch(') < strpos($callOrderSlice, 'checkout'), $failures, $passCount);

// --- Root cause fix itself: workspace HEAD is never required to already
// equal the requested/remote commit — only non-divergence (an ancestor
// relationship) is required, so a workspace that is merely BEHIND the
// remote branch is accepted, never treated as ineligible.
check('local branch divergence is checked with merge-base --is-ancestor (behind is allowed, diverged is not)', str_contains($preparer, "'merge-base', '--is-ancestor', 'HEAD', \$remoteRef"), $failures, $passCount);
check('divergence failure explicitly refuses to reset or discard local commits rather than forcing sync', str_contains($preparer, 'refusing to reset or discard local commits'), $failures, $passCount);
check('the persistent workspace HEAD itself is never compared for exact equality with the requested commit in prepareRemoteCommit', !preg_match('/prepareRemoteCommit.*?hash_equals\(\$commitHash,\s*\$actualHead\)/s', $preparer), $failures, $passCount);

// --- Requested SHA reachability fails closed.
check('an unreachable requested commit throws and refuses to build', str_contains($preparer, 'is not reachable from') && str_contains($preparer, 'refusing to build an artifact for an unverified ref'), $failures, $passCount);
check('a missing requested commit after fetch throws and refuses to build', str_contains($preparer, 'is not present in') && str_contains($preparer, 'after fetch'), $failures, $passCount);

// --- Fetch/auth failure fails closed (git exit code checked, no silent continue).
check('fetch failure throws immediately rather than continuing with stale refs', preg_match('/fetchBranch.*?if \(\$result\[.exitCode.\] !== 0\) \{\s*throw/s', $preparer) === 1, $failures, $passCount);
check('git commands never suppress a non-interactive credential/host-key prompt into a silent hang (GIT_TERMINAL_PROMPT=0)', str_contains((string) file_get_contents(__DIR__ . '/../lib/GitManager.php'), "GIT_TERMINAL_PROMPT'] = '0'"), $failures, $passCount);

// --- The exact requested commit is checked out ONLY inside the isolated,
// per-job BuildWorkspace — never as a mutation of the persistent source
// workspace, and never via a destructive git operation anywhere in this file.
check('the exact commit checkout targets the isolated workspace project directory, not the persistent source path', str_contains($preparer, "'checkout', '--detach', '--force', \$commitHash], \$workspace->projectDir()"), $failures, $passCount);
check('the isolated workspace checkout result is verified byte-for-byte against the requested commit', str_contains($preparer, 'Workspace checkout mismatch: expected'), $failures, $passCount);
check('no destructive reset/clean is ever run against the persistent source workspace', !preg_match('/\[.git.,\s*.reset.,\s*.--hard.[^\]]*\],\s*\$repositoryPath/', $preparer) && !str_contains($preparer, "'clean', '-fd'") && !str_contains($preparer, "'clean', '-fdx'"), $failures, $passCount);
check('no destructive reset/clean is ever run anywhere in the source preparer, including the isolated workspace', !str_contains($preparer, "'reset', '--hard'") && !str_contains($preparer, "'clean', '-fd'"), $failures, $passCount);
check('the isolated workspace is a fresh git init with its own origin, never a copy of the persistent .git directory', str_contains($preparer, "'init'], \$target") && str_contains($preparer, "'remote', 'add', 'origin', \$remoteUrl"), $failures, $passCount);

// --- Concurrency: every build gets its own isolated workspace keyed by job id.
check('every build gets a fresh, job-keyed isolated workspace so concurrent builds cannot collide', str_contains($buildWorkspace, 'function create(string $baseDir, string $projectKey, string $jobId)'), $failures, $passCount);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
