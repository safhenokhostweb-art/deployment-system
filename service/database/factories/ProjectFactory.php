<?php

namespace Database\Factories;

use App\Models\Server;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'name' => $this->faker->company() . ' app',
            'project_key' => Str::slug($this->faker->unique()->domainWord() . '-' . $this->faker->randomNumber(4)),
            'repository' => 'acme/' . $this->faker->slug(2),
            'branch' => 'main',
            'environment' => 'production',
            'server_id' => Server::factory(),
            'pipeline' => [
                ['name' => 'git pull', 'command' => 'git fetch origin {branch} && git reset --hard {commit}'],
                ['name' => 'cache clear', 'command' => '{php_binary} artisan optimize:clear'],
            ],
            'auto_deploy' => false,
            'github_webhook_secret' => Str::random(40),
            'is_active' => true,
        ];
    }

    public function autoDeploy(): self
    {
        return $this->state(fn () => ['auto_deploy' => true]);
    }
}
