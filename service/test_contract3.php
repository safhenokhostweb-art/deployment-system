<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

require 'C:\laragon\www\deployment-system\service\app\Services\Sentinel\SentinelRuntimeEvidenceContract.php';

use App\Services\Sentinel\SentinelRuntimeEvidenceContract;

// Test with relative path
$snapshot = [
    'evidence_version' => 'sentinel.runtime.v1',
    'agent_id' => 'b61c7ec5-928e-4f75-a34e-1b2a0e5dc8ea',
    'agent_version' => '1.5.0',
    'project_key' => 'deployment-system',
    'environment' => 'production',
    'release_id' => 'rel-0001',
    'deployment_id' => 'dep-0001',
    'current_release_path' => 'releases/dep-0001',  // Relative path
    'runtime_marker' => 'release:dep-0001|commit:a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
    'release_manifest_commit_sha' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
    'artifact_checksum_sha256' => null,
    'timestamp' => '2026-10-07T07:34:58+00:00',
    'sequence' => 1,
    'nonce' => '29cf65d5923710127f5304551018a485',
    'snapshot_status' => 'complete',
];

echo "Testing relative path...\n";
try {
    $contract = SentinelRuntimeEvidenceContract::fromArray($snapshot);
    echo "Contract validation PASSED\n";
    echo "Snapshot status: " . $contract->get('snapshot_status') . "\n";
    echo "Is complete: " . ($contract->isComplete() ? 'true' : 'false') . "\n";
} catch (\InvalidArgumentException $e) {
    echo "Contract validation FAILED: " . $e->getMessage() . "\n";
}