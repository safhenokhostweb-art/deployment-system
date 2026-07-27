<?php

namespace Database\Factories;

use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocalAgentControlJobFactory extends Factory
{
    protected $model = LocalAgentControlJob::class;

    public function definition(): array
    {
        return [
            'local_agent_id' => LocalAgent::factory(),
            'action' => LocalAgentControlJob::ACTION_RESTART,
            'status' => LocalAgentControlJob::STATUS_QUEUED,
        ];
    }
}
