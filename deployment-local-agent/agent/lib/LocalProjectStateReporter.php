<?php

declare(strict_types=1);

namespace LocalAgent;

/** Produces secret-free Git state for the already-authenticated heartbeat. */
final class LocalProjectStateReporter
{
    /** @return array<int, array<string, mixed>> */
    public function collect(GitManager $git, array $projects, Logger $logger): array
    {
        $states = [];
        foreach ($projects as $projectKey => $project) {
            $path = $project['local_path'] ?? null;
            if (!is_string($path) || !is_dir($path)) continue;
            try {
                $head = $git->lastCommitHash($path);
                $branch = $this->gitValue($git, ['git', 'rev-parse', '--abbrev-ref', 'HEAD'], $path);
                $remote = $this->gitValue($git, ['git', 'rev-parse', '@{upstream}'], $path, true);
                $remoteSha = $remote === null ? null : $this->gitValue($git, ['git', 'rev-parse', $remote], $path, true);
                $states[] = ['project_key' => (string) $projectKey, 'local_head_sha' => $head, 'branch' => $branch, 'working_tree_dirty' => $git->changedFiles($path) !== [], 'remote_tracking_sha' => $remoteSha];
            } catch (\Throwable $e) {
                $logger->warn("State report: could not inspect {$projectKey}: " . $e->getMessage());
            }
        }
        return $states;
    }

    private function gitValue(GitManager $git, array $command, string $path, bool $nullable = false): ?string
    {
        $result = $git->run($command, $path, $git->timeoutFor('status'));
        $value = trim($result['output']);
        if ($result['exitCode'] !== 0 || $value === '') {
            if ($nullable) return null;
            throw new \RuntimeException('git inspection failed: ' . $value);
        }
        return $value;
    }
}
