<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$agent = \App\Models\LocalAgent::where('public_id', 'b61c7ec5-928e-4f75-a34e-1b2a0e5dc8ea')->first();
if ($agent) {
    echo "Agent found:\n";
    echo "  ID: {$agent->id}\n";
    echo "  Public ID: {$agent->public_id}\n";
    echo "  Runtime Snapshot: " . json_encode($agent->runtime_snapshot, JSON_PRETTY_PRINT) . "\n";
    echo "  Runtime Snapshot Sequence: " . ($agent->runtime_snapshot_sequence ?? 'null') . "\n";
} else {
    echo "Agent not found\n";
}