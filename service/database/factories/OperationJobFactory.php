<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\OperationJob;
use App\Models\Server;
use App\Support\Operations\OperationRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;

class OperationJobFactory extends Factory
{
    protected $model = OperationJob::class;

    public function definition(): array
    {
        return [
            'server_id' => Server::factory(),
            'agent_id' => Agent::factory(),
            'operation_type' => OperationRegistry::DISK_CHECK,
            'status' => OperationJob::STATUS_QUEUED,
        ];
    }
}
