<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Creates commits — never pushes (see PushManager). Two ways a commit
 * gets created here: `addAndCommit()` for a normal Publish (the
 * operator's own commit message, entered in the Hub and never invented
 * by this agent), and `revert()` for a Rollback — always `git revert`,
 * never `git reset`, so a rollback is itself a new, auditable commit
 * instead of rewriting history a teammate may already have pulled.
 */
final class CommitManager
{
    public function __construct(
        private readonly GitManager $git,
    ) {
    }

    /**
     * @param array<int, string> $files exact paths to stage (as reported
     *     by GitManager::changedFiles(), already filtered by
     *     IgnoreRules) — never a blanket `git add .`, so a
     *     `.local-agent-ignore`d path can never be committed even if it
     *     was already tracked before the ignore rule existed.
     */
    public function addAndCommit(string $repositoryPath, array $files, string $message, ?callable $onTick = null): void
    {
        if ($files === []) {
            throw new \RuntimeException("Nothing to commit in {$repositoryPath} — the file list was empty.");
        }

        $paths = [];
        foreach ($files as $file) {
            // A rename is reported as "old/path -> new/path" — both
            // sides need to be in the pathspec so `-A` stages the old
            // path's removal alongside the new path's content.
            if (str_contains($file, ' -> ')) {
                [$old, $new] = array_map('trim', explode(' -> ', $file, 2));
                $paths[] = $old;
                $paths[] = $new;
            } else {
                $paths[] = $file;
            }
        }

        // -A (not plain `add`) so a deleted path in $files is staged as
        // a removal instead of being silently skipped.
        $add = $this->git->run(array_merge(['git', 'add', '-A', '--'], $paths), $repositoryPath, $this->git->timeoutFor('add'), $onTick);
        if ($add['exitCode'] !== 0) {
            $reason = $add['timedOut'] ? 'timed out' : 'failed';

            throw new GitCommandException("git add {$reason} in {$repositoryPath}: " . trim($add['output']), $add['timedOut']);
        }

        // $message is passed as its own array element, never concatenated
        // into a shell string — proc_open's array form hands it to the
        // process as a single argument, so it cannot break out into a
        // second shell command no matter what characters it contains.
        $commit = $this->git->run(['git', 'commit', '-m', $message], $repositoryPath, $this->git->timeoutFor('commit'), $onTick);
        if ($commit['exitCode'] !== 0) {
            $reason = $commit['timedOut'] ? 'timed out' : 'failed';

            throw new GitCommandException("git commit {$reason} in {$repositoryPath}: " . trim($commit['output']), $commit['timedOut']);
        }
    }

    /**
     * @return string the new commit hash created by the revert
     */
    public function revert(string $repositoryPath, string $commitHash, ?callable $onTick = null): string
    {
        $revert = $this->git->run(['git', 'revert', '--no-edit', $commitHash], $repositoryPath, $this->git->timeoutFor('revert'), $onTick);
        if ($revert['exitCode'] !== 0) {
            $reason = $revert['timedOut'] ? 'timed out' : 'failed';

            throw new GitCommandException("git revert {$reason} in {$repositoryPath}: " . trim($revert['output']), $revert['timedOut']);
        }

        return $this->git->lastCommitHash($repositoryPath);
    }
}
