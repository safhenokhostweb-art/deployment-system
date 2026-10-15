<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Test the RuntimeSnapshotCollector directly
require 'C:\laragon\www\deployment-system\local-agent\lib\Security.php';
require 'C:\laragon\www\deployment-system\local-agent\lib\RuntimeSnapshotCollector.php';
require 'C:\laragon\www\deployment-system\local-agent\lib\Client.php';
require 'C:\laragon\www\deployment-system\local-agent\lib\Logger.php';
require 'C:\laragon\www\deployment-system\service\app\Services\Sentinel\SentinelRuntimeEvidenceContract.php';

use LocalAgent\RuntimeSnapshotCollector;
use LocalAgent\Security;
use LocalAgent\Client;
use LocalAgent\Logger;
use App\Services\Sentinel\SentinelRuntimeEvidenceContract;

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

// Validate through contract
echo "Validating through SentinelRuntimeEvidenceContract...\n";
try {
    $contract = SentinelRuntimeEvidenceContract::fromArray($snapshot);
    echo "Contract validation PASSED\n";
    echo "Snapshot status: " . $contract->get('snapshot_status') . "\n";
    echo "Is complete: " . ($contract->isComplete() ? 'true' : 'false') . "\n";
    echo "Missing required fields: " . json_encode($contract->missingRequiredFields()) . "\n";
} catch (\InvalidArgumentException $e) {
    echo "Contract validation FAILED: " . $e->getMessage() . "\n";
}