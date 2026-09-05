<?php

namespace Tests\Unit;

use App\Support\Deployment\ServerAgentVersionResolver;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The test ServerAgentVersionResolver's own docblock already promises
 * ("see ServerAgentReleaseSyncTest, which fails the moment the two drift
 * apart") but that had never actually been written.
 *
 * Guards two incidents:
 *
 *  1. The 1.4.1 bootstrap hash bug: agent/install/upgrade-1.4.1.sh and
 *     agent/release/1.4.1-lineage.json both embedded a candidate
 *     agent.php SHA-256 computed against a CRLF-converted copy of the
 *     file instead of the canonical LF git blob. Canonical package/
 *     source materialization (GitExactCommitSubtreeMaterializer) always
 *     uses core.autocrlf=false/core.eol=lf, so every legitimate Linux
 *     checkout or ServerAgentPackage carries the LF bytes — the stale
 *     CRLF hash made the bootstrap script reject every legitimate
 *     candidate with HASH_MISMATCH. The expected hash here is derived
 *     from `git cat-file -p HEAD:agent/agent.php` (the canonical blob,
 *     immune to this working tree's own line-ending settings) — never
 *     from a plain file_get_contents() of the working-tree copy, which
 *     would silently reproduce the exact bug this guards against on a
 *     Windows checkout.
 *
 *  2. Version-to-commit authority drift (classified "B) safe today but
 *     insufficiently enforced" during the Server Agent 1.4.1 bootstrap
 *     audit): the Hub always resolves the Server Agent upgrade target's
 *     commit from the deployment-system project's latest successful
 *     Deployment (see ServerAgentUpgradeTargetResolver), not from the
 *     original 757e1b5 release commit — safe only as long as nothing
 *     under agent/agent.php or agent/lib/* actually changes without a
 *     matching AGENT_VERSION bump. Nothing previously caught that. The
 *     pinned git object ids below are the blob id of agent/agent.php and
 *     the tree id of the entire agent/lib directory at the 1.4.1
 *     release — both content-addressed by git itself, so they are
 *     immune to line endings and change if and only if a file actually
 *     changes. They deliberately exclude agent/install, agent/tests,
 *     agent/release, and the top-level docs/config files: those are
 *     bootstrap tooling, test fixtures, release manifests, and non-
 *     runtime metadata, not code that ever executes as part of the
 *     Server Agent runtime — see agent/release/1.4.1-lineage.json's own
 *     candidate_overlay/preserved_exactly_from_active_runtime split for
 *     the same distinction. A future commit that changes runtime
 *     behavior under agent/agent.php or agent/lib without bumping
 *     AGENT_VERSION (and updating this pin) fails this test; a commit
 *     that only touches agent/install, agent/tests, agent/release, or
 *     this task's own bootstrap-hash correction does not.
 */
class ServerAgentReleaseSyncTest extends TestCase
{
    private const PINNED_AGENT_PHP_BLOB = '31837bbc681c9f2ebf209a332de4f6774f73100d';
    private const PINNED_LIB_TREE = '7db80d0ec40484a567f698e243faf3fbbabba03a';

    public function test_marker_matches_the_shipped_agent_version(): void
    {
        $this->assertSame(
            $this->extractAgentVersion(),
            ServerAgentVersionResolver::canonical(),
            'service/.deployment/server-agent-version.json must be bumped by hand in the exact same release commit that bumps agent/agent.php\'s AGENT_VERSION.',
        );
    }

    public function test_bootstrap_script_candidate_hash_matches_the_canonical_git_blob(): void
    {
        $canonical = $this->canonicalAgentPhpBlobSha256();
        $script = $this->readTextNormalized(base_path('../agent/install/upgrade-1.4.1.sh'));

        $matched = preg_match('/^C_AGENT=([0-9a-f]{64})$/m', $script, $matches);
        $this->assertSame(1, $matched, 'Could not find a C_AGENT=<sha256> declaration in upgrade-1.4.1.sh.');

        $this->assertSame(
            $canonical,
            $matches[1],
            'upgrade-1.4.1.sh\'s C_AGENT must be the canonical LF git blob hash of agent/agent.php, or the bootstrap script rejects every legitimate Linux checkout / ServerAgentPackage with HASH_MISMATCH.',
        );
    }

    public function test_lineage_manifest_candidate_hash_matches_the_canonical_git_blob(): void
    {
        $canonical = $this->canonicalAgentPhpBlobSha256();
        $lineage = json_decode((string) file_get_contents(base_path('../agent/release/1.4.1-lineage.json')), true);

        $this->assertIsArray($lineage);
        $this->assertSame(
            $canonical,
            $lineage['candidate_snapshot_sha256']['agent.php'] ?? null,
            '1.4.1-lineage.json\'s candidate_snapshot_sha256.agent.php must be the canonical LF git blob hash of agent/agent.php.',
        );
    }

    public function test_bootstrap_script_and_lineage_manifest_cannot_silently_diverge_from_each_other(): void
    {
        $script = $this->readTextNormalized(base_path('../agent/install/upgrade-1.4.1.sh'));
        preg_match('/^C_AGENT=([0-9a-f]{64})$/m', $script, $matches);
        $lineage = json_decode((string) file_get_contents(base_path('../agent/release/1.4.1-lineage.json')), true);

        $this->assertSame(
            $matches[1] ?? null,
            $lineage['candidate_snapshot_sha256']['agent.php'] ?? null,
            'The bootstrap script and the lineage manifest must always agree on the candidate agent.php hash.',
        );
    }

    public function test_agent_runtime_identity_has_not_drifted_from_the_pinned_1_4_1_release(): void
    {
        $repoRoot = base_path('..');

        $agentPhpBlob = $this->runGit($repoRoot, ['git', 'rev-parse', 'HEAD:agent/agent.php']);
        $libTree = $this->runGit($repoRoot, ['git', 'rev-parse', 'HEAD:agent/lib']);

        $this->assertSame(
            self::PINNED_AGENT_PHP_BLOB,
            $agentPhpBlob,
            'agent/agent.php has changed since the 1.4.1 release. If this is an intentional runtime change, bump AGENT_VERSION (and the service/.deployment/server-agent-version.json marker) and update the pinned blob id here; if unintentional, revert it.',
        );
        $this->assertSame(
            self::PINNED_LIB_TREE,
            $libTree,
            'agent/lib has changed since the 1.4.1 release. If this is an intentional runtime change, bump AGENT_VERSION (and the service/.deployment/server-agent-version.json marker) and update the pinned tree id here; if unintentional, revert it.',
        );
        $this->assertSame('1.4.1', $this->extractAgentVersion(), 'AGENT_VERSION must still read 1.4.1 while the runtime identity pin above is unchanged.');
    }

    private function canonicalAgentPhpBlobSha256(): string
    {
        $content = $this->runGitRaw(base_path('..'), ['git', 'cat-file', '-p', 'HEAD:agent/agent.php']);

        return hash('sha256', $content);
    }

    /**
     * Normalizes CRLF to LF before regex matching, so this test's own
     * result never depends on this checkout's core.autocrlf setting —
     * only the file's actual textual content (the C_AGENT literal
     * itself) is under test here, never the working tree's line
     * endings. The canonical hash this literal is compared against
     * still comes from the real git blob (canonicalAgentPhpBlobSha256()),
     * never from this working-tree read.
     */
    private function readTextNormalized(string $path): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    private function extractAgentVersion(): string
    {
        $path = base_path('../agent/agent.php');
        $this->assertFileExists($path, "Expected an agent.php at {$path}");
        $source = (string) file_get_contents($path);

        $matched = preg_match("/const AGENT_VERSION = '([\\d.]+)';/", $source, $matches);
        $this->assertSame(1, $matched, "Could not find an AGENT_VERSION constant declaration in {$path}");

        return $matches[1];
    }

    private function runGit(string $cwd, array $command): string
    {
        return trim($this->runGitRaw($cwd, $command));
    }

    private function runGitRaw(string $cwd, array $command): string
    {
        $result = Process::path($cwd)->run($command);
        $this->assertTrue(
            $result->successful(),
            'git command failed: ' . implode(' ', $command) . "\n" . $result->errorOutput(),
        );

        return $result->output();
    }
}
