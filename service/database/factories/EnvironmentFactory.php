<?php

namespace Database\Factories;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvironmentFactory extends Factory
{
    protected $model = Environment::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'production',
            'key' => $this->faker->unique()->slug(2),
            'status' => Environment::STATUS_ACTIVE,
        ];
    }
}
