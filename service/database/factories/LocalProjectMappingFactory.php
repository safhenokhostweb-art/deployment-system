<?php

namespace Database\Factories;

use App\Models\LocalAgent;
use App\Models\LocalProjectMapping;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocalProjectMappingFactory extends Factory
{
    protected $model = LocalProjectMapping::class;

    public function definition(): array
    {
        return [
            'local_agent_id' => LocalAgent::factory(),
            'project_id' => Project::factory(),
            'local_path' => 'C:\\laragon\\www\\' . $this->faker->slug(2),
            'remote_name' => 'origin',
        ];
    }
}
