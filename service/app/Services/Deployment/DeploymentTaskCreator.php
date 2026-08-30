<?php

namespace App\Services\Deployment;

use App\Models\Deployment;
use App\Models\DeploymentTask;
use InvalidArgumentException;

class DeploymentTaskCreator
{
    /**
     * @param array<int, TargetView> $targets
     */
    public function createForTargets(Deployment $deployment, array $targets): void
    {
        $targetIds = [];

        foreach ($targets as $target) {
            if ($target->isSynthesized || $target->deploymentTargetId === null) {
                throw new InvalidArgumentException('DeploymentTasks require persisted deployment targets.');
            }

            $targetIds[] = $target->deploymentTargetId;
        }

        $this->createForTargetIds($deployment, $targetIds);
    }

    /** @param array<int, int> $targetIds */
    public function createForTargetIds(Deployment $deployment, array $targetIds): void
    {
        foreach ($targetIds as $targetId) {
            DeploymentTask::firstOrCreate(
                [
                    'deployment_id' => $deployment->id,
                    'deployment_target_id' => $targetId,
                ],
                ['status' => DeploymentTask::STATUS_QUEUED],
            );
        }
    }
}
