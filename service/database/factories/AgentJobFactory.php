<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\DeploymentTask;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentJobFactory extends Factory
{
    protected $model = AgentJob::class;

    public function definition(): array
    {
        return [
            'deployment_task_id' => DeploymentTask::factory(),
            'agent_id' => Agent::factory()->approved(),
            'attempt' => 1,
            'status' => AgentJob::STATUS_RUNNING,
            'started_at' => now(),
        ];
    }
}
