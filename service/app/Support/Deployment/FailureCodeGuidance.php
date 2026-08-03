<?php

namespace App\Support\Deployment;

/**
 * Phase 4 requirement: "failure_code plus human-understandable
 * guidance" — a bare code like `migration_requires_approval` tells an
 * operator nothing actionable on its own. Maps every
 * Deployment::FAILURE_CODE_* value (and its agent-side RecipeStepRunner
 * duplicates — see that class's own const block) to one short, concrete
 * next step. Deliberately data, not translated — these are operator/
 * on-call facing technical guidance, not end-user UI copy.
 */
class FailureCodeGuidance
{
    private const GUIDANCE = [
        'build_failed' => 'A build or preflight step failed. Check the step log below for the exact command and error output.',
        'migration_failed' => 'A Prisma migration failed to apply. Check the step log for the SQL error, then fix and re-deploy.',
        'migration_requires_approval' => 'A pending migration looks destructive (DROP/TRUNCATE). Review it, then re-run with allow_destructive:true in the recipe if it is intentional.',
        'activation_failed' => 'The release could not be activated (symlink swap or ownership change failed). Check disk space and permissions on the release root.',
        'service_restart_failed' => 'A systemd service failed to restart, or was not in this agent\'s allowed_services list. Check the service name and agent config.php.',
        'health_check_failed' => 'The new release did not pass its post-activation health check. If activation had already happened, the agent should have rolled back automatically — check whether this deployment\'s status is `rolled_back`.',
        'lease_expired' => 'The agent stopped reporting progress before its lease expired — likely crashed or lost connectivity mid-step. Safe to retry.',
        'agent_heartbeat_lost' => 'The server\'s agent went offline mid-deployment. Confirm the agent process is running, then retry.',
        'max_runtime_exceeded' => 'The deployment ran longer than the configured maximum runtime and was force-ended. If this is a legitimately slow build, raise deployment_job_max_runtime_seconds.',
        'late_callback_rejected' => 'The agent reported in after this deployment had already been finalized (e.g. by deployments:detect-stalled) — informational only, no action needed.',
    ];

    public static function forCode(?string $failureCode): ?string
    {
        if ($failureCode === null) {
            return null;
        }

        return self::GUIDANCE[$failureCode] ?? "No specific guidance is registered for failure_code '{$failureCode}' — check the step log below for details.";
    }
}
