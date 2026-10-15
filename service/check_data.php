<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$agents = \App\Models\LocalAgent::all();
foreach($agents as $a) {
    echo "Agent: {$a->id} {$a->public_id} {$a->status}" . PHP_EOL;
}

$mappings = \App\Models\LocalProjectMapping::with(['project', 'localAgent'])->get();
foreach($mappings as $m) {
    echo "Mapping: {$m->id} Agent: {$m->localAgent->public_id} Project: {$m->project->project_key} Env: {$m->project->environment}" . PHP_EOL;
}

$projects = \App\Models\Project::all();
foreach($projects as $p) {
    echo "Project: {$p->id} {$p->project_key} {$p->environment}" . PHP_EOL;
}