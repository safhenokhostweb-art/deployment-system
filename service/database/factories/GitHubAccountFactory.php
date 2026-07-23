<?php

namespace Database\Factories;

use App\Models\GitHubAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class GitHubAccountFactory extends Factory
{
    protected $model = GitHubAccount::class;

    public function definition(): array
    {
        return [
            'installation_id' => $this->faker->unique()->randomNumber(8),
            'account_login' => $this->faker->unique()->userName(),
            'account_type' => 'User',
            'status' => GitHubAccount::STATUS_CONNECTED,
            'connected_by' => $this->faker->safeEmail(),
            'connected_at' => now(),
        ];
    }
}
