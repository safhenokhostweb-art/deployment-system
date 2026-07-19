<?php

namespace Database\Factories;

use App\Models\Deployment;
use App\Models\DeploymentTarget;
use App\Models\DeploymentTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeploymentTaskFactory extends Factory
{
    protected $model = DeploymentTask::class;

    public function definition(): array
    {
        return [
            'deployment_id' => Deployment::factory(),
            'deployment_target_id' => DeploymentTarget::factory(),
            'status' => DeploymentTask::STATUS_QUEUED,
        ];
    }
}
