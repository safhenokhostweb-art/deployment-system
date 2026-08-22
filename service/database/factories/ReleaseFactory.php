<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'project_id' => Project::factory(),
            'commit_hash' => bin2hex(random_bytes(20)),
            'branch' => 'main',
            'status' => Release::STATUS_SOURCE_RECEIVED,
            'source' => 'manual',
            'source_received_at' => now(),
        ];
    }

    public function ready(): self
    {
        return $this->state(fn () => [
            'status' => Release::STATUS_READY,
            'artifact_checked_at' => now(),
            'ready_at' => now(),
        ]);
    }
}
