<?php

namespace App\Support\Deployment;

use Illuminate\Support\Collection;

/**
 * Phase 4-I: pure, read-only view-model builder for the pipeline step /
 * timeline UI (Tasks 3-5). Writes nothing and introduces no new
 * deployment state — every step's "succeeded / running / hasn't started
 * yet" status is derived entirely from data that already existed before
 * this phase: the project's stored pipeline definition (ordered
 * {name, command} steps) plus whatever log entries have been recorded so
 * far for this run, matched by `sequence` (the agent numbers steps 1..N
 * in pipeline order — see agent/agent.php's `$sequence++` loop). Works
 * identically for a legacy Deployment's DeploymentLogEntry collection and
 * a new-domain AgentJob's AgentJobLogEntry collection — both expose the
 * same sequence/step_name/exit_code/timed_out/duration_ms/created_at
 * shape, so this class depends on neither concrete model.
 */
class PipelineStepPresenter
{
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array<int, array{name: string, command: string}> $pipeline
     * @param Collection<int, mixed> $logEntries
     * @return array<int, array{sequence:int, name:string, status:string, started_at:?\Illuminate\Support\Carbon, finished_at:?\Illuminate\Support\Carbon, duration_ms:?int}>
     */
    public function steps(array $pipeline, Collection $logEntries, string $overallStatus): array
    {
        $bySequence = $logEntries->keyBy('sequence');
        $isRunningNow = $overallStatus === 'running';
        // A step with no log entry that will never get one now that the
        // run has stopped short of success — shown as "skipped" rather
        // than "pending" so it's clear it's not still coming.
        $isStoppedShort = in_array($overallStatus, ['failed', 'cancelled', 'partial_success'], true);
        $lastLoggedSequence = $bySequence->keys()->max() ?? 0;

        $steps = [];
        foreach (array_values($pipeline) as $index => $step) {
            $sequence = $index + 1;
            $entry = $bySequence->get($sequence);

            if ($entry !== null) {
                $failed = $entry->timed_out || ($entry->exit_code !== null && $entry->exit_code !== 0);
                $finishedAt = $entry->created_at;
                $startedAt = $entry->duration_ms !== null ? $finishedAt->copy()->subMilliseconds($entry->duration_ms) : $finishedAt;

                $steps[] = [
                    'sequence' => $sequence,
                    'name' => $step['name'] ?? "Step {$sequence}",
                    'status' => $failed ? self::STATUS_FAILED : self::STATUS_SUCCEEDED,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_ms' => $entry->duration_ms,
                ];

                continue;
            }

            // Only the very next un-logged step counts as "currently
            // running" — the agent executes steps strictly in order and
            // only reports a step once it finishes, so there is never more
            // than one in-flight step at a time.
            $status = match (true) {
                $isRunningNow && $sequence === $lastLoggedSequence + 1 => self::STATUS_RUNNING,
                $isStoppedShort => self::STATUS_SKIPPED,
                default => self::STATUS_PENDING,
            };

            $steps[] = [
                'sequence' => $sequence,
                'name' => $step['name'] ?? "Step {$sequence}",
                'status' => $status,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
            ];
        }

        return $steps;
    }

    /**
     * @param array<int, array{status:string}> $steps
     * @return array{status:string}|null
     */
    public function currentStep(array $steps): ?array
    {
        foreach ($steps as $step) {
            if ($step['status'] === self::STATUS_RUNNING) {
                return $step;
            }
        }

        return null;
    }
}
