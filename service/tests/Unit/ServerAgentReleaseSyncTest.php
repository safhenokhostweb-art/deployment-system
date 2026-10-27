<?php

namespace Tests\Unit;

use App\Support\Deployment\ServerAgentVersionResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The test ServerAgentVersionResolver's own docblock already promises
 * ("see ServerAgentReleaseSyncTest, which fails the moment the two drift
 * apart") but that had never actually been written.
 *
 * Guards three incidents:
 *
 *  1. The 1.4.1 bootstrap hash bug: agent/install/upgrade-1.4.1.sh and
 *     agent/release/1.4.1-lineage.json both embedded a candidate
 *     agent.php SHA-256 computed against a CRLF-converted copy of the
 *     file instead of the canonical LF git blob. Canonical package/
 *     source materialization (GitExactCommitSubtreeMaterializer) always
 *     uses core.autocrlf=false/core.eol=lf, so every legitimate Linux
 *     checkout or ServerAgentPackage carries the LF bytes — the stale
 *     CRLF hash made the bootstrap script reject every legitimate
 *     candidate with HASH_MISMATCH. Historical releases are pinned to
 *     their own exact release commit (757e1b5 for 1.4.1) — never HEAD,
 *     which moves forward with every later release and would silently
 *     stop meaning "1.4.1's candidate" the moment agent.php changed
 *     again for 1.4.2.
 *
 *  2. The 1.4.2 staging/activation bug (Control Job #1 — "Could not
 *     create the Server Agent upgrade staging directory"): the same
 *     CRLF-hash risk applies to upgrade-1.4.2.sh and its new
 *     deployment-server-agent-activate helper, checked here against
 *     1.4.2's own exact release commit (e598894).
 *
 *  3. Version-to-commit authority drift (classified "B) safe today but
 *     insufficiently enforced" during the Server Agent 1.4.1 bootstrap
 *     audit): the Hub always resolves the Server Agent upgrade target's
 *     commit from the deployment-system project's latest successful
 *     Deployment (see ServerAgentUpgradeTargetResolver), never a fixed
 *     release commit — safe only as long as nothing under
 *     agent/agent.php or agent/lib/* actually changes without a
 *     matching AGENT_VERSION bump. The pinned git object ids below are
 *     the blob id of agent/agent.php and the tree id of the entire
 *     agent/lib directory at the 1.4.2 release — both content-addressed
 *     by git itself, so they are immune to line endings and change if
 *     and only if a file actually changes. They deliberately exclude
 *     agent/install, agent/tests, agent/release, and the top-level
 *     docs/config files: those are bootstrap tooling, test fixtures,
 *     release manifests, and non-runtime metadata, not code that ever
 *     executes as part of the Server Agent runtime — see
 *     agent/release/1.4.2-lineage.json's own candidate_overlay/
 *     preserved_exactly_from_active_runtime split for the same
 *     distinction. A future commit that changes runtime behavior under
 *     agent/agent.php or agent/lib without bumping AGENT_VERSION (and
 *     updating this pin) fails this test.
 */
class ServerAgentReleaseSyncTest extends TestCase
{
    private const PINNED_AGENT_PHP_BLOB = '092252f0bec38f8854028280949843a126e5d6a7';
    private const PINNED_LIB_TREE = '57c5817d733dcdb0084a01594929a814f22085cf';

    public function test_marker_matches_the_shipped_agent_version(): void
    {
        $this->assertSame(
            $this->extractAgentVersion(),
            ServerAgentVersionResolver::canonical(),
            'service/.deployment/server-agent-version.json must be bumped by hand in the exact same release commit that bumps agent/agent.php\'s AGENT_VERSION.',
        );
    }

    public function test_1_4_1_bootstrap_script_candidate_hash_matches_its_exact_release_commit(): void
    {
        $canonical = $this->canonicalBlobSha256('757e1b5', 'agent/agent.php');
        $literal = $this->extractHashLiteral(base_path('../agent/install/upgrade-1.4.1.sh'), 'C_AGENT');

        $this->assertSame(
            $canonical,
            $literal,
            'upgrade-1.4.1.sh\'s C_AGENT must be the canonical LF git blob hash of agent/agent.php AT COMMIT 757e1b5 (1.4.1\'s own release), or the bootstrap script rejects every legitimate Linux checkout / ServerAgentPackage with HASH_MISMATCH.',
        );
    }

    public function test_1_4_1_lineage_manifest_candidate_hash_matches_its_exact_release_commit(): void
    {
        $canonical = $this->canonicalBlobSha256('757e1b5', 'agent/agent.php');
        $lineage = json_decode((string) file_get_contents(base_path('../agent/release/1.4.1-lineage.json')), true);

        $this->assertIsArray($lineage);
        $this->assertSame(
            $canonical,
            $lineage['candidate_snapshot_sha256']['agent.php'] ?? null,
            '1.4.1-lineage.json\'s candidate_snapshot_sha256.agent.php must be the canonical LF git blob hash of agent/agent.php at commit 757e1b5.',
        );
    }

    public function test_1_4_1_bootstrap_script_and_lineage_manifest_cannot_silently_diverge_from_each_other(): void
    {
        $literal = $this->extractHashLiteral(base_path('../agent/install/upgrade-1.4.1.sh'), 'C_AGENT');
        $lineage = json_decode((string) file_get_contents(base_path('../agent/release/1.4.1-lineage.json')), true);

        $this->assertSame(
            $literal,
            $lineage['candidate_snapshot_sha256']['agent.php'] ?? null,
            'The 1.4.1 bootstrap script and its lineage manifest must always agree on the candidate agent.php hash.',
        );
    }

    /**
     * 1.4.2's candidate literals are pinned to its own exact release
     * commit (e598894), exactly like 1.4.1 is pinned to 757e1b5 above.
     * They were originally checked against the git INDEX while 1.4.2
     * was being introduced; once agent.php moved on toward 1.4.3, an
     * index read would no longer mean "1.4.2's candidate".
     */
    public function test_1_4_2_bootstrap_script_candidate_hashes_match_its_exact_release_commit(): void
    {
        $script = base_path('../agent/install/upgrade-1.4.2.sh');

        $this->assertSame($this->canonicalBlobSha256('e598894', 'agent/agent.php'), $this->extractHashLiteral($script, 'C_AGENT'));
        $this->assertSame($this->canonicalBlobSha256('e598894', 'agent/lib/SelfUpgradeManager.php'), $this->extractHashLiteral($script, 'C_SELFUPGRADE'));
        $this->assertSame($this->canonicalBlobSha256('e598894', 'agent/install/deployment-server-agent-activate'), $this->extractHashLiteral($script, 'C_ACTIVATE_HELPER'));
        $this->assertSame($this->canonicalBlobSha256('e598894', 'agent/install/deployment-server-agent-activate.sudoers'), $this->extractHashLiteral($script, 'C_ACTIVATE_SUDOERS'));
    }

    public function test_1_4_2_lineage_manifest_candidate_hashes_match_its_exact_release_commit(): void
    {
        $lineage = json_decode((string) file_get_contents(base_path('../agent/release/1.4.2-lineage.json')), true);
        $this->assertIsArray($lineage);
        $candidates = $lineage['candidate_snapshot_sha256'] ?? [];

        $this->assertSame($this->canonicalBlobSha256('e598894', 'agent/agent.php'), $candidates['agent.php'] ?? null);
        $this->assertSame($this->canonicalBlobSha256('e598894', 'agent/lib/SelfUpgradeManager.php'), $candidates['lib/SelfUpgradeManager.php'] ?? null);
        $this->assertSame($this->canonicalBlobSha256('e598894', 'agent/install/deployment-server-agent-activate'), $candidates['install/deployment-server-agent-activate'] ?? null);
        $this->assertSame($this->canonicalBlobSha256('e598894', 'agent/install/deployment-server-agent-activate.sudoers'), $candidates['install/deployment-server-agent-activate.sudoers'] ?? null);
    }

    public function test_1_4_2_bootstrap_script_and_lineage_manifest_cannot_silently_diverge_from_each_other(): void
    {
        $script = base_path('../agent/install/upgrade-1.4.2.sh');
        $lineage = json_decode((string) file_get_contents(base_path('../agent/release/1.4.2-lineage.json')), true);
        $candidates = $lineage['candidate_snapshot_sha256'] ?? [];

        $this->assertSame($this->extractHashLiteral($script, 'C_AGENT'), $candidates['agent.php'] ?? null);
        $this->assertSame($this->extractHashLiteral($script, 'C_SELFUPGRADE'), $candidates['lib/SelfUpgradeManager.php'] ?? null);
        $this->assertSame($this->extractHashLiteral($script, 'C_ACTIVATE_HELPER'), $candidates['install/deployment-server-agent-activate'] ?? null);
        $this->assertSame($this->extractHashLiteral($script, 'C_ACTIVATE_SUDOERS'), $candidates['install/deployment-server-agent-activate.sudoers'] ?? null);
    }

    /**
     * Computed from a fresh `git write-tree` of the current index
     * rather than a literal HEAD/index ref, so this pin is correct
     * both immediately before this release is committed (checked as
     * part of this same change's own pre-commit test run) and forever
     * after (once committed, the index matches HEAD exactly) — exactly
     * like 757e1b5 permanently means "the 1.4.1 release" above, just
     * without needing to already know this release's own future commit
     * SHA.
     */
    public function test_agent_runtime_identity_has_not_drifted_from_the_pinned_1_4_3_release(): void
    {
        [$agentPhpBlob, $libTree] = $this->resolveFromIsolatedIndex(base_path('..'), ['agent/agent.php', 'agent/lib']);

        $this->assertSame(
            self::PINNED_AGENT_PHP_BLOB,
            $agentPhpBlob,
            'agent/agent.php has changed since the 1.4.3 release. If this is an intentional runtime change, bump AGENT_VERSION (and the service/.deployment/server-agent-version.json marker) and update the pinned blob id here; if unintentional, revert it.',
        );
        $this->assertSame(
            self::PINNED_LIB_TREE,
            $libTree,
            'agent/lib has changed since the 1.4.3 release. If this is an intentional runtime change, bump AGENT_VERSION (and the service/.deployment/server-agent-version.json marker) and update the pinned tree id here; if unintentional, revert it.',
        );
        $this->assertSame('1.4.3', $this->extractAgentVersion(), 'AGENT_VERSION must still read 1.4.3 while the runtime identity pin above is unchanged.');
    }

    private function extractHashLiteral(string $scriptPath, string $name): ?string
    {
        $script = $this->readTextNormalized($scriptPath);
        $matched = preg_match('/^' . preg_quote($name, '/') . '=([0-9a-f]{64})$/m', $script, $matches);

        return $matched === 1 ? $matches[1] : null;
    }

    private function canonicalBlobSha256(string $commitish, string $relativePath): string
    {
        $content = $this->runGitRaw(base_path('..'), ['git', 'cat-file', '-p', "{$commitish}:{$relativePath}"]);

        return hash('sha256', $content);
    }

    /**
     * Normalizes CRLF to LF before regex matching, so this test's own
     * result never depends on this checkout's core.autocrlf setting —
     * only the file's actual textual content (the hash literal itself)
     * is under test here, never the working tree's line endings. The
     * canonical hash each literal is compared against still comes from
     * a real git blob (canonicalBlobSha256()),
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

    /**
     * Build the current index tree without writing inside the repository
     * and resolve each path's object id in it. The lookups must run against
     * the same isolated object directory: whenever the index differs from
     * HEAD (exactly the pre-commit run described above) the new trees exist
     * only there, and it is deleted on return.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function resolveFromIsolatedIndex(string $repoRoot, array $paths): array
    {
        $gitDir = $this->runGit($repoRoot, ['git', 'rev-parse', '--absolute-git-dir']);
        $originalIndex = $gitDir . DIRECTORY_SEPARATOR . 'index';
        $originalObjects = $gitDir . DIRECTORY_SEPARATOR . 'objects';
        $tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'server-agent-write-tree-' . bin2hex(random_bytes(8));
        $tempObjects = $tempRoot . DIRECTORY_SEPARATOR . 'objects';
        $tempIndex = $tempRoot . DIRECTORY_SEPARATOR . 'index';

        if (!mkdir($tempObjects . DIRECTORY_SEPARATOR . 'info', 0777, true) && !is_dir($tempObjects)) {
            $this->fail('Could not create an isolated Git object directory.');
        }
        if (!copy($originalIndex, $tempIndex)) {
            File::deleteDirectory($tempRoot);
            $this->fail('Could not copy the current Git index into the isolated temporary directory.');
        }

        try {
            $git = fn (array $command) => Process::path($repoRoot)->env([
                'GIT_INDEX_FILE' => $tempIndex,
                'GIT_OBJECT_DIRECTORY' => $tempObjects,
                'GIT_ALTERNATE_OBJECT_DIRECTORIES' => $originalObjects,
                'GIT_OPTIONAL_LOCKS' => '0',
            ])->run($command);

            $result = $git(['git', 'write-tree']);
            $this->assertTrue($result->successful(), 'Isolated git write-tree failed: ' . $result->errorOutput());
            $tree = trim($result->output());

            return array_map(function (string $path) use ($git, $tree): string {
                $resolved = $git(['git', 'rev-parse', "{$tree}:{$path}"]);
                $this->assertTrue($resolved->successful(), "Isolated git rev-parse {$tree}:{$path} failed: " . $resolved->errorOutput());

                return trim($resolved->output());
            }, $paths);
        } finally {
            File::deleteDirectory($tempRoot);
        }
    }
}
