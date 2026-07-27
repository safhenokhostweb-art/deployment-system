<?php

namespace Database\Factories;

use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\PublishJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class PublishJobFactory extends Factory
{
    protected $model = PublishJob::class;

    public function definition(): array
    {
        return [
            'local_agent_id' => LocalAgent::factory(),
            'project_id' => Project::factory(),
            'action' => PublishJob::ACTION_STATUS_CHECK,
            'status' => PublishJob::STATUS_QUEUED,
        ];
    }
}
