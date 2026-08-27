<?php

namespace App\Services\Releases;

use App\Models\Project;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubAppClient;

/**
 * The one place FACTUAL release data (base SHA, changed files, migration
 * files) is ever derived — always from GitHub's own API, never from an
 * operator or from anything a push payload itself claims. Shared by the
 * webhook path (automatic, best-effort, every push) and the admin backfill
 * path (on-demand, blocking) so the two can never silently disagree about
 * what "authoritative" means.
 *
 * Deliberately returns a soft-failure struct rather than throwing: the
 * webhook path must never fail an entire push-processing job just because
 * GitHub is briefly unreachable or the project isn't connected yet.
 */
final class ReleaseGitFactsResolver
{
    public function __construct(private readonly GitHubAppClient $github)
    {
    }

    /**
     * @return array{base_sha: ?string, changed_files: array<int, string>, migration_files: array<int, string>, error: ?string}
     */
    public function resolve(Project $project, string $commit): array
    {
        $empty = ['base_sha' => null, 'changed_files' => [], 'migration_files' => [], 'error' => null];

        if (!$project->githubAccount?->isConnected() || !$this->github->isConfigured()) {
            return [...$empty, 'error' => 'This project has no connected, configured GitHub App installation — base SHA and changed files cannot be verified authoritatively.'];
        }

        $parts = explode('/', trim((string) $project->repository, '/'));
        if (count($parts) !== 2) {
            return [...$empty, 'error' => 'Project repository must be owner/repository for release fact derivation.'];
        }

        try {
            $token = $this->github->createInstallationToken($project->githubAccount->installation_id)['token'];
            $baseSha = $this->github->getCommitParent($token, $parts[0], $parts[1], $commit);
            if ($baseSha === null) {
                return [...$empty, 'error' => 'The commit has no parent (initial commit) — release facts require a base commit to diff against.'];
            }
            $changedFiles = $this->github->compareCommits($token, $parts[0], $parts[1], $baseSha, $commit);

            return [
                'base_sha' => $baseSha,
                'changed_files' => $changedFiles,
                'migration_files' => $this->migrationFiles($changedFiles),
                'error' => null,
            ];
        } catch (GitHubApiException $exception) {
            return [...$empty, 'error' => 'Unable to reach GitHub to verify base SHA and changed files: ' . $exception->getMessage()];
        }
    }

    /** @return array<int, string> */
    private function migrationFiles(array $changedFiles): array
    {
        return array_values(array_filter(
            $changedFiles,
            static fn (string $path): bool => str_starts_with($path, 'service/database/migrations/'),
        ));
    }
}
