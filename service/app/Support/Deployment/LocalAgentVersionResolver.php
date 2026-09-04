<?php

namespace App\Support\Deployment;

/**
 * Single authoritative source for the Hub's "latest Local Agent version":
 * a small version marker committed inside service/
 * (service/.deployment/local-agent-version.json), never a hand-maintained
 * literal kept in sync by hand at release time.
 *
 * service/ is the ONLY subtree an artifact-based deploy of the
 * deployment-system project ever activates — the Local Agent's own build
 * narrows every Artifact to application_root ('service') before the zip
 * is even created (see ApplicationRoot::resolve() /
 * local-agent/lib/ArtifactUploader.php), and the Server Agent's
 * ArtifactManager::activate() atomically swaps exactly one working
 * directory and nothing else. local-agent/ and deployment-local-agent/
 * are siblings of service/ in the repository, but neither is packaged
 * into this project's Artifact nor placed by activation — they are not
 * release-managed by this deploy pipeline at all. Any copy of either
 * found next to a deployed service/ in Production is unmanaged legacy
 * filesystem state this resolver must never read or trust.
 *
 * The marker is committed and bumped by hand in the exact same release
 * commit that bumps LOCAL_AGENT_VERSION in local-agent/agent.php and
 * deployment-local-agent/agent/agent.php — see LocalAgentVersionSyncTest,
 * which fails the moment any of the three drift apart.
 */
final class LocalAgentVersionResolver
{
    private const MARKER_RELATIVE_PATH = '.deployment/local-agent-version.json';

    public static function effective(): string
    {
        $canonical = self::canonical();
        $override = trim((string) env('DEPLOYMENT_LATEST_LOCAL_AGENT_VERSION', ''));

        // Only ever honored at/above canonical: an emergency operator pin
        // stays possible, but a stale override left over from an old
        // release can never again shadow a newer one — it simply falls
        // below canonical the moment the next normal release ships.
        if ($override !== '' && version_compare($override, $canonical, '>=')) {
            return $override;
        }

        return $canonical;
    }

    /**
     * Reads and validates the in-service version marker. Accepts an
     * explicit $markerPath only for testability (simulating a clean,
     * artifact-only production layout without sibling agent trees) —
     * every real caller resolves the one true path, inside base_path(),
     * that every deployment-system Artifact actually ships.
     */
    public static function canonical(?string $markerPath = null): string
    {
        $path = $markerPath ?? base_path(self::MARKER_RELATIVE_PATH);
        $source = @file_get_contents($path);

        if ($source === false) {
            throw new \RuntimeException("Could not read the Local Agent version marker at {$path}; it must be committed inside service/ and shipped with every release.");
        }

        $decoded = json_decode($source, true);

        if (!is_array($decoded) || !isset($decoded['version']) || !is_string($decoded['version'])) {
            throw new \RuntimeException("The Local Agent version marker at {$path} is malformed; expected {\"version\": \"X.Y.Z\"}.");
        }

        $version = $decoded['version'];

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new \RuntimeException("The Local Agent version marker at {$path} does not contain a valid semantic version: '{$version}'.");
        }

        return $version;
    }
}
