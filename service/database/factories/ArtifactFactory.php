<?php

namespace Database\Factories;

use App\Models\Artifact;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
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

    /** Build a test Artifact whose available metadata has real matching bytes. */
    public function physicallyAvailable(string $contents = 'verified-artifact-contents'): static
    {
        return $this->state(fn () => [
            'status' => Artifact::STATUS_AVAILABLE,
            'checksum_sha256' => hash('sha256', $contents),
            'size_bytes' => strlen($contents),
        ])->afterCreating(function (Artifact $artifact) use ($contents): void {
            Storage::disk('artifacts')->put($artifact->disk_path, $contents);
        });
    }
}
