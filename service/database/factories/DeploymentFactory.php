<?php

namespace Database\Factories;

use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DeploymentFactory extends Factory
{
    protected $model = Deployment::class;

    public function definition(): array
    {
        $project = Project::factory()->create();

        return [
            'project_id' => $project->id,
            'server_id' => $project->server_id,
            'action' => Deployment::ACTION_DEPLOY,
            'status' => Deployment::STATUS_QUEUED,
            'commit_hash' => Str::random(40),
            'branch' => 'main',
            'trigger' => Deployment::TRIGGER_MANUAL,
            'http_health_config' => null,
            'execution_status' => Deployment::EXECUTION_PENDING,
            'http_health_status' => Deployment::HTTP_HEALTH_NOT_CONFIGURED,
            'queued_at' => now(),
        ];
    }
}
