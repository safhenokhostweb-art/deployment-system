<?php

namespace App\Support\Deployment;

/**
 * The Hub's canonical "what Server Agent version does this exact,
 * currently-trusted deployment-system release ship" — mirrors
 * LocalAgentVersionResolver exactly, but deliberately its own, separate
 * class: the Hub has no git access to read agent/agent.php's
 * AGENT_VERSION constant at an arbitrary historical commit (confirmed
 * during the Deployment #183/#184 audits — service/ is the only subtree
 * an Artifact-based Hub deploy ever ships or activates), so a small
 * marker travels INSIDE service/ instead
 * (service/.deployment/server-agent-version.json), bumped by hand in the
 * exact same release commit that bumps agent/agent.php's AGENT_VERSION —
 * see ServerAgentReleaseSyncTest, which fails the moment the two drift
 * apart.
 *
 * Never unified with LocalAgentVersionResolver: Server Agent and
 * Local/Build Agent are independent pieces of software with independent
 * release cadences (see agent/release/*-lineage.json's own
 * "excluded_lineage" framing) — collapsing their version sources into
 * one class would make it trivial to accidentally gate one agent type's
 * eligibility on the other's version.
 */
final class ServerAgentVersionResolver
{
    private const MARKER_RELATIVE_PATH = '.deployment/server-agent-version.json';

    /**
     * Accepts an explicit $markerPath only for testability (simulating a
     * clean, artifact-only production layout) — every real caller
     * resolves the one true path, inside base_path(), that every
     * deployment-system Artifact actually ships.
     */
    public static function canonical(?string $markerPath = null): string
    {
        $path = $markerPath ?? base_path(self::MARKER_RELATIVE_PATH);
        $source = @file_get_contents($path);

        if ($source === false) {
            throw new \RuntimeException("Could not read the Server Agent version marker at {$path}; it must be committed inside service/ and shipped with every release.");
        }

        $decoded = json_decode($source, true);

        if (!is_array($decoded) || !isset($decoded['version']) || !is_string($decoded['version'])) {
            throw new \RuntimeException("The Server Agent version marker at {$path} is malformed; expected {\"version\": \"X.Y.Z\"}.");
        }

        $version = $decoded['version'];

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            throw new \RuntimeException("The Server Agent version marker at {$path} does not contain a valid semantic version: '{$version}'.");
        }

        return $version;
    }
}
