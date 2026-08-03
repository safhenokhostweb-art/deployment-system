<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (customer-site direct-GitHub-dependency removal): the
 * orchestration ArtifactBuilder (git archive + checksum) and Client's
 * two upload calls don't do on their own — build, create the pending
 * record, upload the bytes, always clean up the local temp file.
 * Failure at any stage is caught and reported as a plain bool/string
 * pair rather than thrown, so a failed artifact upload never blocks or
 * fails the publish itself (see agent.php's docblock on why: the
 * artifact is a *deployment* concern, the publish already succeeded the
 * moment the push did).
 */
final class ArtifactUploader
{
    public function __construct(
        private readonly ArtifactBuilder $builder,
        private readonly Client $client,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array{succeeded: bool, message: string}
     */
    public function buildAndUpload(string $projectKey, string $repositoryPath, string $commitHash, string $branch, ?callable $onTick = null): array
    {
        $archivePath = null;

        try {
            $archive = $this->builder->build($repositoryPath, $commitHash, $onTick);
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

                return ['succeeded' => false, 'message' => "Hub rejected artifact metadata: {$message}"];
            }

            $artifactId = $createResponse['body']['artifact_id'] ?? null;
            if (!is_string($artifactId) || $artifactId === '') {
                return ['succeeded' => false, 'message' => 'Hub accepted artifact metadata but returned no artifact_id.'];
            }

            $contents = file_get_contents($archivePath);
            if ($contents === false) {
                return ['succeeded' => false, 'message' => "Could not read the built artifact back from {$archivePath}."];
            }

            $uploadResponse = $this->client->uploadRawBody('POST', "/api/local-agent/artifacts/{$artifactId}/content", $contents);

            if ($uploadResponse['status'] !== 200) {
                $message = $uploadResponse['body']['message'] ?? "HTTP {$uploadResponse['status']}";

                return ['succeeded' => false, 'message' => "Hub rejected artifact content: {$message}"];
            }

            $this->logger->info("Artifact uploaded for commit {$commitHash} ({$archive['size']} bytes, checksum {$archive['checksum']}).");

            return ['succeeded' => true, 'message' => "Uploaded ({$archive['size']} bytes)."];
        } catch (\Throwable $e) {
            $this->logger->error("Artifact build/upload failed for commit {$commitHash}: " . $e->getMessage());

            return ['succeeded' => false, 'message' => $e->getMessage()];
        } finally {
            if ($archivePath !== null) {
                @unlink($archivePath);
            }
        }
    }
}
