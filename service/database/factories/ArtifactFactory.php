<?php

namespace Database\Factories;

use App\Models\Artifact;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ArtifactFactory extends Factory
{
    protected $model = Artifact::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'project_id' => Project::factory(),
            'commit_hash' => bin2hex(random_bytes(20)),
            'branch' => 'main',
            'checksum_sha256' => hash('sha256', Str::random(20)),
            'size_bytes' => $this->faker->numberBetween(1000, 5_000_000),
            'disk_path' => 'test/' . Str::random(10) . '.zip',
            'status' => Artifact::STATUS_AVAILABLE,
            'uploaded_by' => 'dev-machine',
            'expires_at' => now()->addDays(14),
        ];
    }
}
