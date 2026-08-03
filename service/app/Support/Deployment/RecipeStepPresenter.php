<?php

namespace App\Support\Deployment;

use Illuminate\Support\Collection;

/**
 * Phase 4 of the sandogh deployment overhaul: PipelineStepPresenter's
 * counterpart for a `deployment_recipe`-driven deployment. Same
 * read-only view-model contract and the same sequence-matching
 * technique, but reads the EXPLICIT `status`/`step_type`/`failure_code`
 * columns RecipeStepRunner reports (see the 2026_08_12_090200 migration)
 * instead of inferring success/failure from a bare exit code — a recipe
 * step can legitimately have no exit code at all (a `verify_*` check
 * that failed before ever spawning a process, or a `restart_service`
 * rejected by the agent-local ServiceAllowlist before it tried).
 */
class RecipeStepPresenter
{
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RUNNING = 'running';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param array<int, array{name: string, type: string}> $recipeSteps normalized `deployment_recipe['steps']`
     * @param Collection<int, mixed> $logEntries
     * @return array<int, array{sequence:int, name:string, type:string, status:string, failure_code:?string, started_at:?\Illuminate\Support\Carbon, finished_at:?\Illuminate\Support\Carbon, duration_ms:?int}>
     */
    public function steps(array $recipeSteps, Collection $logEntries, string $overallStatus): array
    {
        $bySequence = $logEntries->keyBy('sequence');
        $isRunningNow = in_array($overallStatus, ['running', 'building', 'migrating', 'activating', 'verifying'], true);
        // A step with no log entry that will never get one now that the
        // run has stopped short of completing — shown as "skipped" rather
        // than "pending" so it's clear it's not still coming.
        $isStoppedShort = in_array($overallStatus, ['failed', 'cancelled', 'partial_success', 'rolled_back'], true);
        $lastLoggedSequence = $bySequence->keys()->max() ?? 0;

        $steps = [];
        foreach (array_values($recipeSteps) as $index => $step) {
            $sequence = $index + 1;
            $entry = $bySequence->get($sequence);

            if ($entry !== null) {
                // The explicit column is authoritative when present; a
                // legacy-shaped log row (step_type/status both null —
                // impossible for a genuinely recipe-driven deployment,
                // but defensive) falls back to the same exit-code
                // inference PipelineStepPresenter uses.
                $status = $entry->status ?? (($entry->timed_out || ($entry->exit_code !== null && $entry->exit_code !== 0)) ? self::STATUS_FAILED : self::STATUS_SUCCEEDED);
                $finishedAt = $entry->created_at;
                $startedAt = $entry->duration_ms !== null ? $finishedAt->copy()->subMilliseconds($entry->duration_ms) : $finishedAt;

                $steps[] = [
                    'sequence' => $sequence,
                    'name' => $step['name'] ?? "Step {$sequence}",
                    'type' => $entry->step_type ?? $step['type'] ?? null,
                    'status' => $status,
                    'failure_code' => $entry->failure_code,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'duration_ms' => $entry->duration_ms,
                ];

                continue;
            }

            // Only the very next un-logged step counts as "currently
            // running" — the agent executes steps strictly in order and
            // only reports a step once it finishes, so there is never
            // more than one in-flight step at a time.
            $status = match (true) {
                $isRunningNow && $sequence === $lastLoggedSequence + 1 => self::STATUS_RUNNING,
                $isStoppedShort => self::STATUS_SKIPPED,
                default => self::STATUS_PENDING,
            };

            $steps[] = [
                'sequence' => $sequence,
                'name' => $step['name'] ?? "Step {$sequence}",
                'type' => $step['type'] ?? null,
                'status' => $status,
                'failure_code' => null,
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
