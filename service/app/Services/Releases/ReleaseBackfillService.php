<?php

namespace App\Services\Releases;

use App\Models\Artifact;
use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\Release;
use App\Services\Deployment\Artifact\ArtifactService;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only bootstrap/backfill path for the provider-neutral release
 * contract: records release metadata for a commit that was already pushed
 * and built before the contract existed for it (or before a project ever
 * ran the project-side release-preparation tool), without creating a Git
 * commit, without an HMAC round trip, and without ever letting the operator
 * fabricate the facts that matter — commit, base SHA, and changed files are
 * always re-derived here from GitHub's own API, never accepted from the
 * request. The only genuinely operator-supplied input is which named
 * checks they attest were run, which is recorded and clearly labeled as
 * operator-confirmed evidence, never disguised as automated verification.
 *
 * This is a bootstrap/admin operation, not a replacement for
 * deployment/release-prepare.php — that tool remains the normal path for a
 * project's own new releases.
 */
final class ReleaseBackfillService
{
    public const CHECK_TESTS_PASSED = 'tests_passed';
    public const CHECK_LINT_PASSED = 'lint_passed';
    public const CHECK_MIGRATIONS_REVIEWED = 'migrations_reviewed';

    private const CHECK_LABELS = [
        self::CHECK_TESTS_PASSED => 'The relevant automated test suite for these exact changed files was run and passed.',
        self::CHECK_LINT_PASSED => 'PHP lint / syntax check passed for these exact changed files.',
        self::CHECK_MIGRATIONS_REVIEWED => 'Every migration file in this commit has been manually reviewed and is safe.',
    ];

    public function __construct(
        private readonly GitHubAppClient $github,
        private readonly ReleaseGitFactsResolver $gitFacts,
        private readonly ArtifactService $artifacts,
        private readonly ReleaseMetadataValidator $validator,
        private readonly ReleaseOrchestrator $releases,
    ) {
    }

    /**
     * Side-effect-free. Re-derives every authoritative fact fresh on every
     * call — nothing here is cached from a prior request, so the review
     * page always reflects the current state of the remote branch.
     *
     * @return array{
     *   github_connected: bool,
     *   branch: string,
     *   commit: ?string,
     *   base_sha: ?string,
     *   changed_files: array<int, string>,
     *   migration_files: array<int, string>,
     *   artifact: ?Artifact,
     *   release: ?Release,
     *   required_checks: array<string, string>,
     *   missing: array<int, string>,
     *   can_submit: bool,
     * }
     */
    public function preview(Project $project): array
    {
        $branch = $project->branch;
        $missing = [];

        [$commit, $baseSha, $changedFiles, $githubConnected] = $this->deriveFromGitHub($project, $missing);
        $migrationFiles = $this->migrationFiles($changedFiles);
        $artifact = $commit === null ? null : $this->artifacts->findMatching($project, $commit, $branch);
        $release = $commit === null ? null : Release::query()
            ->where('project_id', $project->id)
            ->where('commit_hash', $commit)
            ->first();

        if ($commit !== null && $artifact === null) {
            $missing[] = 'No physically valid Artifact exists yet for the exact remote commit.';
        }
        if ($release !== null && ($release->metadata['release_ready'] ?? false) === true) {
            $missing[] = 'This commit already has valid, release_ready metadata recorded.';
        }
        if ($release !== null && ! $release->isLegacyFallbackEligible()) {
            $missing[] = 'An ordinary exact-commit Release cannot be made ready through admin backfill.';
        }

        $requiredChecks = $this->requiredChecks($migrationFiles);

        return [
            'github_connected' => $githubConnected,
            'branch' => $branch,
            'commit' => $commit,
            'base_sha' => $baseSha,
            'changed_files' => $changedFiles,
            'migration_files' => $migrationFiles,
            'artifact' => $artifact,
            'release' => $release,
            'required_checks' => $requiredChecks,
            'missing' => $missing,
            'can_submit' => $missing === [] && $commit !== null && $baseSha !== null && $artifact !== null,
        ];
    }

    /**
     * @param array<int, string> $confirmedChecks Only ever an ability to
     *     assert which of the fixed, named checks above were performed —
     *     never a free-form claim about commit/base/files, which are
     *     always re-derived here exactly as preview() does, ignoring
     *     anything the request might otherwise have said about them.
     */
    public function record(Project $project, array $confirmedChecks, ?string $notes, string $actorEmail): Release
    {
        $state = $this->preview($project);
        abort_unless($state['can_submit'], 409, $state['missing'][0] ?? 'This commit is not yet eligible for release metadata backfill.');

        /** @var Artifact $artifact */
        $artifact = $state['artifact'];
        $requiredChecks = array_keys($state['required_checks']);
        $confirmedChecks = array_values(array_intersect($confirmedChecks, $requiredChecks));
        $allChecksConfirmed = array_diff($requiredChecks, $confirmedChecks) === [];

        $riskFlags = [];
        if ($state['migration_files'] !== [] && !in_array(self::CHECK_MIGRATIONS_REVIEWED, $confirmedChecks, true)) {
            $riskFlags[] = 'MIGRATION_REVIEW_REQUIRED';
        }

        $now = now();
        $testResults = array_map(
            fn (string $check): array => [
                'name' => $check,
                'type' => 'operator_confirmed',
                'exit_code' => 0,
                'confirmed_by' => $actorEmail,
                'confirmed_at' => $now->toIso8601String(),
            ],
            $confirmedChecks,
        );

        $metadata = [
            'schema_version' => 1,
            'project_key' => $project->project_key,
            'commit_sha' => $state['commit'],
            'base_sha' => $state['base_sha'],
            'branch' => $state['branch'],
            'summary' => 'Admin-recorded release metadata backfill for an already-pushed, already-built commit.',
            'changed_files' => $state['changed_files'],
            'migration_files' => $state['migration_files'],
            'test_results' => $testResults,
            'build_result' => [
                'required' => true,
                'status' => 'succeeded',
                'contract' => 'server-managed-exact-commit',
                'artifact_id' => $artifact->id,
            ],
            'health_contract' => ['required' => true, 'endpoint_source' => 'admin_backfill'],
            'risk_flags' => $riskFlags,
            'created_timestamp' => $now->toIso8601String(),
            'release_ready' => $allChecksConfirmed && $riskFlags === [],
            'source' => 'admin_backfill',
            'operator_notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
        ];

        $metadata = $this->validator->validate($project, $metadata);

        return DB::transaction(function () use ($project, $metadata, $actorEmail, $confirmedChecks): Release {
            $release = $this->releases->recordLegacyMetadata(
                $project,
                $metadata,
                'admin_backfill',
                'admin_confirmed_historical_release',
                $actorEmail,
            );

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $actorEmail,
                action: 'release.metadata_backfilled',
                subjectType: Release::class,
                subjectId: $release->id,
                metadata: [
                    'project_id' => $project->id,
                    'commit_hash' => $metadata['commit_sha'],
                    'base_sha' => $metadata['base_sha'],
                    'confirmed_checks' => $confirmedChecks,
                    'release_ready' => $metadata['release_ready'],
                ],
            );

            return $release;
        });
    }

    /** @return array{0: ?string, 1: ?string, 2: array<int, string>, 3: bool} */
    private function deriveFromGitHub(Project $project, array &$missing): array
    {
        if (!$project->githubAccount?->isConnected() || !$this->github->isConfigured()) {
            $missing[] = 'This project has no connected, configured GitHub App installation — base SHA and changed files cannot be verified authoritatively.';

            return [null, null, [], false];
        }

        $parts = explode('/', trim((string) $project->repository, '/'));
        if (count($parts) !== 2) {
            $missing[] = 'Project repository must be owner/repository for release metadata backfill.';

            return [null, null, [], true];
        }

        try {
            $token = $this->github->createInstallationToken($project->githubAccount->installation_id)['token'];
            $commit = $this->github->getBranchHead($token, $parts[0], $parts[1], $project->branch);
        } catch (GitHubApiException $exception) {
            $missing[] = 'Unable to reach GitHub to resolve the remote branch HEAD: ' . $exception->getMessage();

            return [null, null, [], true];
        }

        // Base SHA / changed files / migration files are derived through the
        // same resolver the automatic webhook-driven factual population
        // uses, so the two can never disagree about what "authoritative"
        // means for the exact same commit.
        $facts = $this->gitFacts->resolve($project, $commit);
        if ($facts['error'] !== null) {
            $missing[] = $facts['error'];

            return [$commit, null, [], true];
        }

        return [$commit, $facts['base_sha'], $facts['changed_files'], true];
    }

    /** @return array<int, string> */
    private function migrationFiles(array $changedFiles): array
    {
        return array_values(array_filter(
            $changedFiles,
            static fn (string $path): bool => str_starts_with($path, 'service/database/migrations/'),
        ));
    }

    /** @return array<string, string> */
    private function requiredChecks(array $migrationFiles): array
    {
        $checks = [
            self::CHECK_TESTS_PASSED => self::CHECK_LABELS[self::CHECK_TESTS_PASSED],
            self::CHECK_LINT_PASSED => self::CHECK_LABELS[self::CHECK_LINT_PASSED],
        ];
        if ($migrationFiles !== []) {
            $checks[self::CHECK_MIGRATIONS_REVIEWED] = self::CHECK_LABELS[self::CHECK_MIGRATIONS_REVIEWED];
        }

        return $checks;
    }
}
