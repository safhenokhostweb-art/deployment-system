<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Test the RuntimeSnapshotCollector directly
require 'C:\laragon\www\deployment-system\local-agent\lib\Security.php';
require 'C:\laragon\www\deployment-system\local-agent\lib\RuntimeSnapshotCollector.php';
require 'C:\laragon\www\deployment-system\local-agent\lib\Client.php';
require 'C:\laragon\www\deployment-system\local-agent\lib\Logger.php';

use LocalAgent\RuntimeSnapshotCollector;
use LocalAgent\Security;
use LocalAgent\Client;
use LocalAgent\Logger;

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

// Now send heartbeat with the snapshot
$logger = new Logger('C:\laragon\www\deployment-system\deployment-local-agent\storage\logs');
$client = new Client(
    baseUrl: 'http://localhost:8000',
    localAgentId: 'b61c7ec5-928e-4f75-a34e-1b2a0e5dc8ea',
    agentToken: 'agt_s6h3rB4fTAgGeTcE5SEIxc4vH4TWsrECvwJUu6537YWZwCAx',
    signingSecret: 'sec_yQNUzMf2sWosY6fyLVzJzEc7QgBmstzLPHLZPEc8EeCFI5KE',
    timeoutSeconds: 10,
    logger: $logger
);

$body = [
    'hostname' => gethostname(),
    'operating_system' => PHP_OS_FAMILY,
    'agent_version' => '1.5.0',
    'project_states' => [
        [
            'project_key' => 'deployment-system',
            'local_head_sha' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
            'branch' => 'main',
            'working_tree_dirty' => false,
            'remote_tracking_sha' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
        ],
    ],
    'runtime_snapshot' => $snapshot,
];

$jsonBody = json_encode($body, JSON_THROW_ON_ERROR);

echo "Sending heartbeat with runtime_snapshot...\n";
$response = $client->request('POST', '/api/local-agent/heartbeat', $body);
echo "Response: " . json_encode($response['body'], JSON_PRETTY_PRINT) . "\n";
echo "Status: " . $response['status'] . "\n";