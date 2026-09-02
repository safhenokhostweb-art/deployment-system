<?php

namespace App\Support\Deployment;

/**
 * Single authoritative source for the Hub's "latest Local Agent version":
 * the LOCAL_AGENT_VERSION constant actually shipped in this exact release's
 * packaged Agent (deployment-local-agent/agent/agent.php), never a
 * hand-maintained literal kept in sync by hand at release time. Both agent
 * trees travel in the same release as service/ (see
 * .deployment/release-policy.json's allowed_release_paths), so this always
 * reflects the version that shipped with the running Hub build — no manual
 * bump step, and no separate literal for a stale persisted env override to
 * permanently shadow (see effective()).
 */
final class LocalAgentVersionResolver
{
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

    public static function canonical(): string
    {
        $path = base_path('../deployment-local-agent/agent/agent.php');
        $source = @file_get_contents($path);

        if ($source === false || preg_match("/const LOCAL_AGENT_VERSION = '([\d.]+)';/", $source, $matches) !== 1) {
            throw new \RuntimeException("Could not determine the canonical Local Agent version from {$path}; it must ship in the same release as service/.");
        }

        return $matches[1];
    }
}
