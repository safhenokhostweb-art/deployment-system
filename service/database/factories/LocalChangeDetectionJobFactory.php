<?php

namespace Database\Factories;

use App\Models\LocalAgent;
use App\Models\LocalChangeDetectionJob;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocalChangeDetectionJobFactory extends Factory
{
    protected $model = LocalChangeDetectionJob::class;

    public function definition(): array
    {
        return [
            'local_agent_id' => LocalAgent::factory(),
            'project_id' => Project::factory(),
            'changed_files' => ['app/Foo.php'],
            'files_changed_count' => 1,
            'detected_at' => now(),
            'status' => LocalChangeDetectionJob::STATUS_DETECTED,
            'suggested_commit_message' => 'Auto update: Laravel service changes',
        ];
    }
}
