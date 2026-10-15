<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Test the RuntimeSnapshotCollector directly
require 'C:\laragon\www\deployment-system\local-agent\lib\Security.php';
require 'C:\laragon\www\deployment-system\local-agent\lib\RuntimeSnapshotCollector.php';

use LocalAgent\RuntimeSnapshotCollector;
use LocalAgent\Security;

$collector = new RuntimeSnapshotCollector(
    releaseRoot: 'C:\laragon\www\deployment-system\test-release',
    deploymentId: 'dep-0001',
    projectKey: 'deployment-system',
    environment: 'production',
    releaseId: 'rel-0001',
    agentId: 'b61c7ec5-928e-4f75-a34e-1b2a0e5dc8ea',
    agentVersion: '1.5.0'
);

$snapshot = $collector->collect();
echo json_encode($snapshot, JSON_PRETTY_PRINT) . PHP_EOL;