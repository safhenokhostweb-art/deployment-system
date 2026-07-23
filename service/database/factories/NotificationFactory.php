<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => Notification::TYPE_DEPLOYMENT_SUCCESS,
            'title' => $this->faker->sentence(4),
            'message' => $this->faker->sentence(10),
            'severity' => Notification::SEVERITY_INFO,
            'actor_type' => 'system',
        ];
    }

    public function unread(): self
    {
        return $this->state(['read_at' => null]);
    }

    public function read(): self
    {
        return $this->state(['read_at' => now()]);
    }
}
