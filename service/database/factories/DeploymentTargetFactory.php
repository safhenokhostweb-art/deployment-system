<?php

namespace Database\Factories;

use App\Models\DeploymentTarget;
use App\Models\Environment;
use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeploymentTargetFactory extends Factory
{
    protected $model = DeploymentTarget::class;

    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'server_id' => Server::factory(),
            'agent_id' => null,
            'name' => $this->faker->unique()->domainWord() . '-target',
            'is_active' => true,
        ];
    }
}
