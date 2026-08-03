<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (customer-site production deploys failing because the
 * production server cannot reach github.com directly — direct connect
 * and SSH on 22/443 both time out after ~135s): builds a deployable,
 * git-clean artifact of the exact commit just pushed, on THIS machine
 * (which does have working GitHub access), so the production Server
 * Agent never needs to fetch from GitHub at all — see ArtifactUploader
 * and the Hub's ArtifactService.
 *
 * Uses `git archive`, not a manual copy-and-exclude-list: it packages
 * exactly the tracked files at one commit — no .git, and (since
 * anything gitignored was, by definition, never tracked) no
 * node_modules/vendor/storage/.env/logs/caches either, with zero
 * bespoke exclusion logic to keep in sync with each project's own
 * .gitignore. A project needing to exclude a file that IS tracked (e.g.
 * a tests/ directory) uses git's own native `export-ignore` attribute
 * in .gitattributes — the standard, purpose-built mechanism for
 * exactly this, not a deployment-system-specific config format.
 */
final class ArtifactBuilder
{
    public function __construct(
        private readonly GitManager $git,
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    /**
     * @return array{path: string, checksum: string, size: int}
     * @throws \RuntimeException if `git archive` fails or produces an empty file
     */
    public function build(string $repositoryPath, string $commitHash, ?callable $onTick = null): array
    {
        $archivePath = tempnam(sys_get_temp_dir(), 'la-artifact-') . '.zip';
        @unlink($archivePath); // tempnam() creates it; git archive -o refuses to write over some filesystems' quirks otherwise

        $result = $this->git->run(
            ['git', 'archive', '--format=zip', '-o', $archivePath, $commitHash],
            $repositoryPath,
            $this->timeoutSeconds,
            $onTick,
        );

        if ($result['exitCode'] !== 0) {
            @unlink($archivePath);

            throw new \RuntimeException("git archive failed for commit {$commitHash} in {$repositoryPath}: " . trim($result['output']));
        }

        $size = @filesize($archivePath);
        if ($size === false || $size === 0) {
            @unlink($archivePath);

            throw new \RuntimeException("git archive produced an empty or unreadable file for commit {$commitHash} in {$repositoryPath}.");
        }

        $checksum = hash_file('sha256', $archivePath);
        if ($checksum === false) {
            @unlink($archivePath);

            throw new \RuntimeException("Could not compute a checksum for the artifact built from commit {$commitHash}.");
        }

        return ['path' => $archivePath, 'checksum' => $checksum, 'size' => $size];
    }
}
