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
$evidence = (string) file_get_contents(__DIR__ . '/../lib/ExactCommitEvidence.php');
$artifactUploader = (string) file_get_contents(__DIR__ . '/../lib/ArtifactUploader.php');
$materializer = (string) file_get_contents(__DIR__ . '/../lib/PinnedDependencyMaterializer.php');
$dependencyResolver = (string) file_get_contents(__DIR__ . '/../lib/DependencyRepositoryResolver.php');
$gitManager = (string) file_get_contents(__DIR__ . '/../lib/GitManager.php');
$upgradePackage = (string) file_get_contents(__DIR__ . '/../lib/LinuxUpgradePackageMaterializer.php');
$upgradeRunner = (string) file_get_contents(__DIR__ . '/../lib/LinuxUpgradeRunner.php');

// --- Root cause: the wired-up build_artifact path must be the safe
// fetch-and-verify-then-isolated-checkout method, never the strict
// "workspace HEAD must already equal the requested commit" method.
check('build_artifact jobs are dispatched through the safe remote-commit path', str_contains($agent, "\$artifactUploader->buildAndUploadRemoteCommit(\$projectKey, \$repositoryPath, \$repositoryUrl, \$remoteName, \$commitHash, \$artifactBranch"), $failures, $passCount);
check('the unsafe pre-equality buildAndUpload() is defined but never dispatched from agent.php', str_contains($uploader, 'public function buildAndUpload(') && !str_contains($agent, '$artifactUploader->buildAndUpload('), $failures, $passCount);
check('publish also builds through the safe remote-commit path, not the strict-equality one', str_contains($agent, "\$artifactUploader->buildAndUploadRemoteCommit(\$projectKey, \$repositoryPath, \$repositoryUrl, \$remoteName, \$commitHash, \$branch"), $failures, $passCount);

// --- Ordering: the persistent workspace supplies validated transport
// evidence only. Network fetch and exact-SHA proofs run in the isolated repo.
$posDirtyCheck = strpos($preparer, 'private function assertTrustedPersistentWorkspace');
$posPrepareRemoteCommitBody = strpos($preparer, 'public function prepareRemoteCommit');
$callOrderSlice = substr($preparer, $posPrepareRemoteCommitBody, ($posDirtyCheck ?: strlen($preparer)) - $posPrepareRemoteCommitBody + 4000);
$posTransport = strpos($callOrderSlice, '$this->repositories->resolveTransport(');
$posTrustCall = strpos($callOrderSlice, '$this->assertTrustedPersistentWorkspace(');
$posIsolatedFetch = strpos($callOrderSlice, "['git', 'fetch', '--no-tags', '--prune', 'origin', \$branch]");
$posCommitCheck = strpos($callOrderSlice, "['git', 'cat-file', '-e'");
$posReachability = strpos($callOrderSlice, "['git', 'merge-base', '--is-ancestor', \$commitHash, 'FETCH_HEAD']");
$posCheckout = strpos($callOrderSlice, "['git', 'checkout', '--detach', '--force', \$commitHash]");

check('primary transport is identity-validated before the isolated repository is configured', $posTransport !== false && $posTransport < strpos($callOrderSlice, '$this->initializeWorkspaceRepository('), $failures, $passCount);
check('the persistent workspace dirty-check runs before the isolated fetch', $posTrustCall !== false && $posIsolatedFetch !== false && $posTrustCall < $posIsolatedFetch, $failures, $passCount);
check('changedFiles() dirtiness is asserted before fetch, refusing to fetch or build a dirty workspace', str_contains($preparer, 'Source workspace is dirty; refusing to fetch or build'), $failures, $passCount);
check('requested commit existence is verified only after the isolated fetch', $posIsolatedFetch !== false && $posCommitCheck !== false && $posIsolatedFetch < $posCommitCheck, $failures, $passCount);
check('FETCH_HEAD reachability is verified before exact detached checkout', $posCommitCheck !== false && $posReachability !== false && $posCheckout !== false && $posCommitCheck < $posReachability && $posReachability < $posCheckout, $failures, $passCount);

// --- Root cause fix itself: workspace HEAD is never required to already
// equal the requested/remote commit — only non-divergence (an ancestor
// relationship) is required, so a workspace that is merely BEHIND the
// remote branch is accepted, never treated as ineligible.
check('local branch divergence is checked with merge-base --is-ancestor (behind is allowed, diverged is not)', str_contains($preparer, "'merge-base', '--is-ancestor', 'HEAD', \$remoteRef"), $failures, $passCount);
check('divergence failure explicitly refuses to reset or discard local commits rather than forcing sync', str_contains($preparer, 'refusing to reset or discard local commits'), $failures, $passCount);
check('the persistent workspace HEAD itself is never compared for exact equality with the requested commit in prepareRemoteCommit', !preg_match('/prepareRemoteCommit.*?hash_equals\(\$commitHash,\s*\$actualHead\)/s', $preparer), $failures, $passCount);

// --- Requested SHA reachability fails closed in the isolated repository.
check('an unreachable requested commit fails the FETCH_HEAD ancestor proof', str_contains($preparer, "['git', 'merge-base', '--is-ancestor', \$commitHash, 'FETCH_HEAD']"), $failures, $passCount);
check('a missing requested commit fails an explicit commit-object proof', str_contains($preparer, "['git', 'cat-file', '-e', \"{\$commitHash}^{commit}\"]"), $failures, $passCount);

// --- Fetch/auth failure fails closed with categorized, secret-safe evidence.
check('fetch failure is checked by the fail-closed isolated command runner', str_contains($preparer, "if (\$result['exitCode'] !== 0)") && str_contains($preparer, 'Primary repository materialization failed while'), $failures, $passCount);
check('git commands never suppress a non-interactive credential/host-key prompt into a silent hang (GIT_TERMINAL_PROMPT=0)', str_contains((string) file_get_contents(__DIR__ . '/../lib/GitManager.php'), "GIT_TERMINAL_PROMPT'] = '0'"), $failures, $passCount);
check('dependency mappings carry source workspace transport metadata into artifact builds', str_contains($agent, "'source_workspace_path' => \$mappedProject['source_workspace_path']") && str_contains($agent, "'remote_name' => \$mappedProject['remote_name']"), $failures, $passCount);
check('dependency fetch transport is resolved from the trusted mapped workspace remote', str_contains($materializer, '$this->repositories->resolve(') && str_contains($dependencyResolver, 'repository_identity_mismatch'), $failures, $passCount);
check('primary and dependency materialization share the repository transport resolver', str_contains($preparer, '$this->repositories->resolveTransport(') && str_contains($materializer, '$this->repositories->resolve('), $failures, $passCount);
check('validated GitHub mappings resolve to the canonical SSH-over-443 transport', str_contains($dependencyResolver, 'ssh://git@ssh.github.com:443/'), $failures, $passCount);
check('primary fetch diagnostics carry safe mapping identity transport and failure categories', str_contains($preparer, 'repository_identity=') && str_contains($preparer, 'transport=') && str_contains($preparer, 'stderr_category='), $failures, $passCount);
check('same repository branch and SHA pins share one checkout and fetch', str_contains($materializer, '$checkoutKey') && str_contains($materializer, '$checkouts[$checkoutKey]'), $failures, $passCount);
check('dependency fetch failures expose only categorized diagnostics', str_contains($materializer, 'stderr_category=') && !str_contains($materializer, 'while {$operation}: '), $failures, $passCount);
check('dependency fetches use a dedicated 180-second timeout', str_contains($gitManager, "'fetch' => 180") && str_contains($agent, "'fetch' => (int) (\$config['git_fetch_timeout_seconds'] ?? 180)"), $failures, $passCount);
check('Linux upgrades fetch the validated branch into an isolated repository', str_contains($upgradePackage, "['git', 'fetch', '--no-tags', '--prune', 'origin', 'refs/heads/' . \$branch]") && str_contains($upgradePackage, '$this->repositories->resolve('), $failures, $passCount);
check('Linux upgrade packages archive and verify the exact job commit', str_contains($upgradePackage, "['git', 'archive', '--format=zip'") && str_contains($upgradePackage, '$expectedCommit') && str_contains($upgradePackage, 'verifyExtractedTree'), $failures, $passCount);
check('Linux activation starts only after exact package validation is reported', str_contains($upgradeRunner, '$onValidated?->__invoke') && str_contains($agent, "'source_commit' => \$package['source_commit']"), $failures, $passCount);

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

// Exact-commit release evidence is Hub-issued, identity-bound, and collected
// only from that isolated workspace before dependencies can affect the scan.
check('Hub evidence contract is passed into the remote exact-commit build path', str_contains($agent, "\$job['evidence_contract']") && str_contains($agent, '$evidenceContract'), $failures, $passCount);
check('evidence contract binds project, release job, SHA, branch, and Agent identity', str_contains($evidence, "\$contract['project_key']") && str_contains($evidence, "\$contract['release_id']") && str_contains($evidence, "\$contract['publish_job_id']") && str_contains($evidence, "\$contract['commit_sha']") && str_contains($evidence, "\$contract['branch']") && str_contains($evidence, "\$contract['agent_id']"), $failures, $passCount);
check('unknown or missing required evidence checks fail closed', str_contains($evidence, 'missing or unsupported required checks'), $failures, $passCount);
check('PHP syntax evidence scans the isolated project directory and excludes installed dependencies', str_contains($evidence, '$workspace->projectDir()') && str_contains($evidence, "['.git', 'vendor', 'node_modules']") && str_contains($evidence, 'TOKEN_PARSE'), $failures, $passCount);
check('artifact metadata upload is bound to the claimed PublishJob', str_contains($artifactUploader, "'publish_job_id' => \$jobId"), $failures, $passCount);
check('successful build report returns deterministic structured evidence', str_contains($artifactUploader, "'evidence' => \$evidence?->finish()") && str_contains($evidence, "'evidence_digest'"), $failures, $passCount);

echo "\n";
foreach ($failures as $failure) {
    echo "FAIL: {$failure}\n";
}
echo "\n" . $passCount . ' passed, ' . count($failures) . " failed.\n";

exit(count($failures) === 0 ? 0 : 1);
