<?php

namespace App\Services\Releases;

use App\Models\LocalAgent;
use App\Models\PublishJob;
use App\Models\Release;
use Carbon\CarbonImmutable;

final class ReleaseEvidenceService
{
    public const CONTRACT_VERSION = 1;

    /** @return array<string, mixed> */
    public function issue(PublishJob $job, Release $release, LocalAgent $agent): array
    {
        $contract = [
            'contract_version' => self::CONTRACT_VERSION,
            'project_key' => $job->project->project_key,
            'release_id' => $release->public_id,
            'publish_job_id' => $job->public_id,
            'commit_sha' => $job->reviewed_head_sha,
            'branch' => $job->reviewed_branch,
            'agent_id' => $agent->public_id,
            'required_checks' => [
                ['id' => 'source_provenance', 'type' => 'exact_commit', 'required' => true],
                ['id' => 'php_syntax', 'type' => 'php_syntax', 'required' => true],
                ['id' => 'build_pipeline', 'type' => 'trusted_build', 'required' => true],
                ['id' => 'artifact_manifest', 'type' => 'release_manifest', 'required' => true],
            ],
        ];
        $contract['contract_digest'] = $this->digest($contract);

        return $contract;
    }

    /** @param array<string, mixed> $evidence */
    public function validateAndRecord(PublishJob $job, LocalAgent $agent, array $evidence): PublishJob
    {
        abort_unless($job->action === PublishJob::ACTION_BUILD_ARTIFACT && $job->release_id !== null, 422, 'Release evidence is only accepted for a release-linked artifact build.');
        abort_unless($job->status === PublishJob::STATUS_RUNNING, 409, 'Release evidence is only accepted while its artifact build is running.');
        $contract = $job->evidence_contract;
        abort_unless(is_array($contract), 422, 'The build job has no trusted evidence contract.');
        abort_unless(($contract['contract_version'] ?? null) === self::CONTRACT_VERSION, 422, 'Unsupported release evidence contract version.');
        abort_unless(is_string($contract['contract_digest'] ?? null) && hash_equals($contract['contract_digest'], $this->digestWithout($contract, 'contract_digest')), 422, 'Release evidence contract digest mismatch.');

        foreach (['contract_version', 'project_key', 'release_id', 'publish_job_id', 'commit_sha', 'branch', 'agent_id', 'contract_digest'] as $field) {
            abort_unless(array_key_exists($field, $evidence) && $evidence[$field] === $contract[$field], 422, "Release evidence {$field} mismatch.");
        }
        abort_unless($job->local_agent_id === $agent->id && $agent->public_id === $contract['agent_id'], 403, 'Release evidence came from a non-authoritative Local Agent.');
        abort_if($job->project->authoritative_build_local_agent_id !== null && $job->project->authoritative_build_local_agent_id !== $agent->id, 403, 'The assigned Local Agent is no longer authoritative for this project.');
        abort_unless($job->project->project_key === $contract['project_key'], 422, 'Release evidence project mismatch.');
        abort_unless($job->release?->public_id === $contract['release_id'], 422, 'Release evidence Release mismatch.');

        $digest = $evidence['evidence_digest'] ?? null;
        abort_unless(is_string($digest) && preg_match('/^[0-9a-f]{64}$/', $digest) === 1, 422, 'Malformed release evidence digest.');
        abort_unless(hash_equals($digest, $this->digestWithout($evidence, 'evidence_digest')), 422, 'Release evidence digest mismatch.');
        abort_unless(is_string($evidence['agent_version'] ?? null) && $evidence['agent_version'] !== '', 422, 'Release evidence Agent version is missing.');
        abort_unless($evidence['agent_version'] === $agent->agent_version, 422, 'Release evidence Agent version does not match the authenticated Agent heartbeat.');
        abort_unless(is_string($evidence['started_at'] ?? null) && is_string($evidence['completed_at'] ?? null), 422, 'Release evidence timestamps are missing.');
        abort_unless(is_int($evidence['duration_ms'] ?? null) && $evidence['duration_ms'] >= 0, 422, 'Release evidence duration is malformed.');
        $this->assertTimestampRange($evidence['started_at'], $evidence['completed_at'], 'Release evidence');
        abort_unless(is_array($evidence['checks'] ?? null), 422, 'Release evidence checks are malformed.');

        $reported = [];
        foreach ($evidence['checks'] as $check) {
            abort_unless(is_array($check) && is_string($check['id'] ?? null), 422, 'Malformed release evidence check.');
            abort_if(isset($reported[$check['id']]), 422, 'Duplicate release evidence check.');
            abort_unless(($check['status'] ?? null) === 'passed', 422, "Required release check '{$check['id']}' did not pass.");
            abort_unless(is_int($check['duration_ms'] ?? null) && $check['duration_ms'] >= 0, 422, "Release check '{$check['id']}' has a malformed duration.");
            abort_unless(is_string($check['started_at'] ?? null) && is_string($check['completed_at'] ?? null), 422, "Release check '{$check['id']}' timestamps are missing.");
            $this->assertTimestampRange($check['started_at'], $check['completed_at'], "Release check '{$check['id']}'");
            $reported[$check['id']] = true;
        }

        $required = [];
        foreach ($contract['required_checks'] ?? [] as $check) {
            abort_unless(is_array($check) && ($check['required'] ?? null) === true && is_string($check['id'] ?? null), 422, 'Unknown or optional checks are not supported by this evidence contract.');
            $required[$check['id']] = true;
        }
        abort_unless(array_keys($reported) === array_keys($required), 422, 'Release evidence is missing or contains unknown required checks.');

        $job->forceFill([
            'release_evidence' => $evidence,
            'evidence_digest' => $digest,
            'evidence_verified_at' => now(),
        ])->save();

        return $job->refresh();
    }

    /** @param array<string, mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $value */
    private function digestWithout(array $value, string $key): string
    {
        unset($value[$key]);

        return $this->digest($value);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }

    private function assertTimestampRange(string $startedAt, string $completedAt, string $label): void
    {
        try {
            $started = CarbonImmutable::parse($startedAt);
            $completed = CarbonImmutable::parse($completedAt);
        } catch (\Throwable) {
            abort(422, "{$label} timestamps are malformed.");
        }
        abort_if($completed->lessThan($started), 422, "{$label} completed before it started.");
    }
}
