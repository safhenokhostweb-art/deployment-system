<?php

namespace Tests\Unit;

use App\Support\Deployment\LocalAgentVersionResolver;
use Tests\TestCase;

/**
 * Regression guard for two compounding version-drift incidents:
 *
 *  1. Production Readiness verification: LOCAL_AGENT_VERSION was bumped
 *     across two phases of real functional changes (project-config sync,
 *     then the publish-lease/watchdog root-cause fixes) without ever being
 *     incremented, and config('deployment.latest_local_agent_version')'s
 *     hand-maintained literal default had silently fallen BELOW the
 *     shipped version.
 *  2. Deployment #177 (commit 5d8041e, official Agent 1.5.3): the shipped
 *     source default was correctly bumped to 1.5.3, but production's
 *     persistent shared .env (see ops/atomic-release/MIGRATION_PLAN.md —
 *     .env is shared/activation state, never part of an immutable release)
 *     still carried an explicit DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION=1.5.2
 *     from an earlier release, which env() always prefers over the code
 *     default — permanently pinning the Hub's reported "latest version" to
 *     1.5.2 no matter how many later releases bumped the shipped Agent.
 *
 * Both were the same root cause wearing two costumes: a second,
 * independently mutable copy of the version number that a normal release
 * has no obligation to touch. LocalAgentVersionResolver eliminates it by
 * deriving latest_local_agent_version from the LOCAL_AGENT_VERSION actually
 * shipped in this release's packaged agent.php, and by bounding the
 * remaining env override so it can only pin at or above that canonical
 * version — never silently below it.
 */
class LocalAgentVersionSyncTest extends TestCase
{
    public function test_both_agent_trees_report_the_same_local_agent_version(): void
    {
        $deploymentLocalAgentVersion = $this->extractLocalAgentVersion(base_path('../deployment-local-agent/agent/agent.php'));
        $localAgentVersion = $this->extractLocalAgentVersion(base_path('../local-agent/agent.php'));

        $this->assertSame(
            $deploymentLocalAgentVersion,
            $localAgentVersion,
            'deployment-local-agent/agent/agent.php and local-agent/agent.php must ship the exact same LOCAL_AGENT_VERSION — they are kept as mirrored copies.',
        );
        $this->assertSame('1.5.4', $localAgentVersion, 'The transient artifact-fetch retry fix must ship as official Local Agent 1.5.4.');
    }

    public function test_hub_default_latest_version_matches_the_shipped_agent_version(): void
    {
        $shippedVersion = $this->extractLocalAgentVersion(base_path('../deployment-local-agent/agent/agent.php'));

        $this->assertSame(
            $shippedVersion,
            config('deployment.latest_local_agent_version'),
            "config('deployment.latest_local_agent_version') must match the LOCAL_AGENT_VERSION actually shipped in agent.php, or the outdated-agent warning can never fire for a real out-of-date install.",
        );
    }

    public function test_assigned_artifact_protocol_minimum_matches_the_shipped_agent_and_capability(): void
    {
        $shippedVersion = $this->extractLocalAgentVersion(base_path('../deployment-local-agent/agent/agent.php'));
        $source = file_get_contents(base_path('../deployment-local-agent/agent/agent.php'));

        $this->assertSame($shippedVersion, config('deployment.min_local_agent_version_for_assigned_artifact'));
        $this->assertSame('1.5.2', config('deployment.min_local_agent_version_for_exact_commit_upgrade'));
        $this->assertTrue(version_compare('1.5.2', $shippedVersion, '<'), 'Installed 1.5.2 must remain eligible for the normal exact-commit upgrade to 1.5.4.');
        $this->assertStringContainsString("'assigned_artifact_publish_job_id' => true", $source);
    }

    public function test_both_packaged_uploaders_send_the_claimed_publish_job_public_id(): void
    {
        $local = file_get_contents(base_path('../local-agent/lib/ArtifactUploader.php'));
        $packaged = file_get_contents(base_path('../deployment-local-agent/agent/lib/ArtifactUploader.php'));

        $this->assertSame($local, $packaged, 'ArtifactUploader mirrors must remain byte-identical.');
        $this->assertStringContainsString("'publish_job_id' => \$jobId", $packaged);
    }

    public function test_linux_self_upgrade_uses_the_versioned_packaged_runtime_and_expected_version(): void
    {
        $runner = file_get_contents(base_path('../deployment-local-agent/agent/lib/LinuxUpgradeRunner.php'));
        $materializer = file_get_contents(base_path('../deployment-local-agent/agent/lib/LinuxUpgradePackageMaterializer.php'));
        $upgrader = file_get_contents(base_path('../deployment-local-agent/upgrade-linux.sh'));

        $this->assertStringContainsString('LinuxUpgradePackageMaterializer', $runner);
        $this->assertStringContainsString("['git', 'fetch', '--no-tags', '--prune', 'origin', 'refs/heads/' . \$branch]", $materializer);
        $this->assertStringContainsString("['git', 'archive', '--format=zip'", $materializer);
        $this->assertStringContainsString("\$expectedCommit . ':deployment-local-agent'", $materializer);
        $this->assertStringContainsString("'--expected-version'", $runner);
        $this->assertStringContainsString('SOURCE_DIR="${SCRIPT_DIR}/agent"', $upgrader);
        $this->assertStringContainsString('does not match expected version', $upgrader);
    }

    public function test_local_and_packaged_exact_commit_upgrade_sources_have_no_semantic_drift(): void
    {
        $critical = [
            'agent.php',
            'lib/DependencyRepositoryResolver.php',
            'lib/GitManager.php',
            'lib/LinuxUpgradePackageMaterializer.php',
            'lib/LinuxUpgradeRunner.php',
            'lib/PinnedDependencyMaterializer.php',
            'lib/SafeFilesystem.php',
            'lib/SafetyChecker.php',
        ];
        $local = $this->runtimeFingerprints(base_path('../local-agent'), $critical);
        $packaged = $this->runtimeFingerprints(base_path('../deployment-local-agent/agent'), $critical);

        $this->assertSame($local, $packaged, 'Local and packaged exact-commit upgrade semantics must match; comments and formatting may differ.');
    }

    public function test_config_source_derives_the_default_from_the_resolver_not_a_hand_maintained_literal(): void
    {
        $configSource = file_get_contents(config_path('deployment.php'));

        $this->assertStringContainsString(
            "'latest_local_agent_version' => \\App\\Support\\Deployment\\LocalAgentVersionResolver::effective(),",
            $configSource,
            'latest_local_agent_version must be derived from LocalAgentVersionResolver, never a hand-maintained literal that a normal release can forget to bump.',
        );
    }

    public function test_resolver_canonical_matches_the_shipped_agent_version(): void
    {
        $this->assertSame(
            $this->extractLocalAgentVersion(base_path('../deployment-local-agent/agent/agent.php')),
            LocalAgentVersionResolver::canonical(),
        );
    }

    /**
     * Reproduces Deployment #177's exact drift directly against the
     * resolver: a persistent env override left over from the 1.5.2 release
     * must not be able to shadow the 1.5.3 the Hub actually shipped.
     */
    public function test_stale_env_override_below_canonical_cannot_shadow_a_newer_release(): void
    {
        $canonical = LocalAgentVersionResolver::canonical();
        $this->assertSame('1.5.4', $canonical);

        putenv('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION=1.5.2');
        try {
            $this->assertSame(
                $canonical,
                LocalAgentVersionResolver::effective(),
                'A stale env override below the canonical shipped version must never win — this is the exact production drift (persisted DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION=1.5.2 surviving after the Agent shipped 1.5.3) this guards against.',
            );
        } finally {
            putenv('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION');
        }
    }

    public function test_env_override_at_or_above_canonical_remains_honored_for_a_genuine_emergency(): void
    {
        putenv('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION=9.9.9');
        try {
            $this->assertSame('9.9.9', LocalAgentVersionResolver::effective());
        } finally {
            putenv('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION');
        }

        putenv('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION=1.5.4');
        try {
            $this->assertSame('1.5.4', LocalAgentVersionResolver::effective());
        } finally {
            putenv('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION');
        }
    }

    private function extractLocalAgentVersion(string $path): string
    {
        $this->assertFileExists($path, "Expected an agent.php at {$path}");
        $source = file_get_contents($path);

        $matched = preg_match("/const LOCAL_AGENT_VERSION = '([\\d.]+)';/", $source, $matches);
        $this->assertSame(1, $matched, "Could not find a LOCAL_AGENT_VERSION constant declaration in {$path}");

        return $matches[1];
    }

    /** @return array<string, string> */
    private function runtimeFingerprints(string $root, array $paths): array
    {
        sort($paths);

        $fingerprints = [];
        foreach ($paths as $relative) {
            $tokens = token_get_all((string) file_get_contents($root.'/'.$relative));
            $semantic = '';
            foreach ($tokens as $token) {
                if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($token) && $token[0] === T_OPEN_TAG) {
                    $semantic .= '<?php';
                    continue;
                }
                if (is_array($token) && $token[0] === T_CLOSE_TAG) {
                    $semantic .= '?>';
                    continue;
                }
                $semantic .= is_array($token) ? $token[1] : $token;
            }
            $fingerprints[$relative] = hash('sha256', $semantic);
        }

        return $fingerprints;
    }
}
