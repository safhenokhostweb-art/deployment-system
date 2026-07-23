<?php

namespace App\Services\Deployment;

use App\Models\Environment;
use App\Models\Project;

/**
 * The adapter between the legacy Project.server_id path and the new
 * Deployment Domain (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md §4). Every
 * future piece of execution-path code should resolve targets through this
 * class instead of reading Project.server_id or Environment rows directly.
 *
 * A legacy Project (no Environment rows at all — true for every Project
 * that exists today) resolves to exactly one synthesized, non-persisted
 * TargetView built from its own server_id/project_key. Nothing is ever
 * written to `environments`/`deployment_targets` on this branch — that is
 * what keeps this additive and migration-free. Only a Project an operator
 * has explicitly given real Environment rows resolves to the real,
 * DB-backed targets of its (first, currently only supported) Environment.
 */
class DeploymentTargetResolver
{
    /**
     * @return array<int, TargetView>
     */
    public function resolveTargetsFor(Project $project): array
    {
        // Presence of a real Environment row is what decides the branch —
        // never target/status count — so a half-configured or fully
        // disabled project never silently falls back to legacy behavior,
        // which would hide the misconfiguration behind an unrelated server.
        if ($project->environments()->doesntExist()) {
            return [$this->synthesizeLegacyTarget($project)];
        }

        // Phase 4-C.5: once a project can have more than one Environment
        // (Production/Staging/Development), picking literally the first
        // row by id — regardless of status — would risk silently
        // dispatching to a disabled environment. Filtering to active here
        // is safe because environments/deployment_targets held zero real
        // rows before this phase (docs/PHASE_3C_DEPLOYMENT_DOMAIN_AUDIT.md),
        // so no existing deployment behavior depended on the old
        // unfiltered lookup — see docs/PHASE_4C5_ENVIRONMENT_TARGET_UI_REPORT.md §1.
        $environment = $project->environments()->where('status', Environment::STATUS_ACTIVE)->first();

        if ($environment === null) {
            return [];
        }

        return $environment->deploymentTargets()
            ->where('is_active', true)
            ->get()
            ->map(fn ($target) => new TargetView(
                deploymentTargetId: $target->id,
                serverId: $target->server_id,
                agentId: $target->agent_id,
                targetKey: $target->name,
                isSynthesized: false,
            ))
            ->all();
    }

    private function synthesizeLegacyTarget(Project $project): TargetView
    {
        return new TargetView(
            deploymentTargetId: null,
            serverId: $project->server_id,
            agentId: null,
            // Reuses the project's existing project_key value so an
            // already-installed agent's config.php mapping never has to
            // change, even if this project is later promoted to a real
            // Environment/DeploymentTarget (Phase 3-A §5).
            targetKey: $project->project_key,
            isSynthesized: true,
        );
    }
}
