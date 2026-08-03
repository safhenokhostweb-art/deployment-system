<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (production has no access to repo.packagist.org,
 * api.github.com, or the npm registry): the full local pipeline a
 * publish now runs — composer/npm build (LocalBuildRunner), archive +
 * vendor/public-build assembly (ArtifactBuilder), then the two-phase
 * upload — with a typed failure_code identifying exactly which stage
 * failed. Unlike the previous "best-effort, never blocks the publish"
 * design, a publish is now only ever reported `succeeded` once ALL of
 * build, archive, and upload have succeeded — see agent.php's publish
 * handling. A project with neither composer.json nor package.json
 * never reaches the composer/npm stages at all (LocalBuildRunner skips
 * them silently), so this adds no new failure surface for a project
 * that doesn't need either.
 */
final class ArtifactUploader
{
    public function __construct(
        private readonly LocalBuildRunner $buildRunner,
        private readonly ArtifactBuilder $builder,
        private readonly Client $client,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{succeeded: bool, message: string, failureCode: ?string}
     */
    public function buildAndUpload(string $projectKey, string $repositoryPath, string $commitHash, string $branch, ?callable $onTick = null): array
    {
        $archivePath = null;

        try {
            $buildResult = $this->buildRunner->run($repositoryPath, $onTick);
            $this->logger->info("Local build for commit {$commitHash}: " . (empty($buildResult['log']) ? 'nothing to build' : implode(' && ', $buildResult['log'])));

            $archive = $this->builder->build($repositoryPath, $commitHash, $buildResult, $onTick);
            $archivePath = $archive['path'];

            $createResponse = $this->client->request('POST', '/api/local-agent/artifacts', [
                'project_key' => $projectKey,
                'commit_hash' => $commitHash,
                'branch' => $branch,
                'checksum_sha256' => $archive['checksum'],
                'size_bytes' => $archive['size'],
            ]);

            if ($createResponse['status'] !== 201) {
                $message = $createResponse['body']['message'] ?? "HTTP {$createResponse['status']}";

                return ['succeeded' => false, 'message' => "Hub rejected artifact metadata: {$message}", 'failureCode' => 'artifact_upload_failed'];
            }

            $artifactId = $createResponse['body']['artifact_id'] ?? null;
            if (!is_string($artifactId) || $artifactId === '') {
                return ['succeeded' => false, 'message' => 'Hub accepted artifact metadata but returned no artifact_id.', 'failureCode' => 'artifact_upload_failed'];
            }

            $contents = file_get_contents($archivePath);
            if ($contents === false) {
                return ['succeeded' => false, 'message' => "Could not read the assembled artifact back from {$archivePath}.", 'failureCode' => 'artifact_build_failed'];
            }

            $uploadResponse = $this->client->uploadRawBody('POST', "/api/local-agent/artifacts/{$artifactId}/content", $contents);

            if ($uploadResponse['status'] !== 200) {
                $message = $uploadResponse['body']['message'] ?? "HTTP {$uploadResponse['status']}";
                // The Hub's own defense-in-depth content validation
                // (ArtifactService::validateArchiveContents()) reports a
                // specific `reason` for a missing-vendor/missing-build
                // rejection — surfaced as the matching, more useful
                // failure_code instead of the generic upload-failed one.
                $failureCode = match ($uploadResponse['body']['reason'] ?? null) {
                    'missing_vendor' => 'artifact_missing_vendor',
                    'missing_build' => 'artifact_missing_build',
                    default => 'artifact_upload_failed',
                };

                return ['succeeded' => false, 'message' => "Hub rejected artifact content: {$message}", 'failureCode' => $failureCode];
            }

            $this->logger->info("Artifact uploaded for commit {$commitHash} ({$archive['size']} bytes, checksum {$archive['checksum']}).");

            return ['succeeded' => true, 'message' => "Uploaded ({$archive['size']} bytes).", 'failureCode' => null];
        } catch (LocalBuildFailedException $e) {
            $this->logger->error("Local build/artifact failed for commit {$commitHash} [{$e->failureCode}]: " . $e->getMessage());

            return ['succeeded' => false, 'message' => $e->getMessage(), 'failureCode' => $e->failureCode];
        } catch (\Throwable $e) {
            $this->logger->error("Artifact build/upload failed for commit {$commitHash}: " . $e->getMessage());

            return ['succeeded' => false, 'message' => $e->getMessage(), 'failureCode' => 'artifact_build_failed'];
        } finally {
            if ($archivePath !== null) {
                @unlink($archivePath);
            }
        }
    }
}
