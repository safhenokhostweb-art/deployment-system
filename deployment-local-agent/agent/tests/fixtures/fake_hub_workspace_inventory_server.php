<?php

/**
 * Deterministic in-process Hub double for WorkspaceInventoryReporter
 * tests. Records every POST body it receives (one JSON line per request,
 * append-only) to FAKE_HUB_LOG_PATH so the test can assert on exactly
 * what the reporter sent, without needing a real Hub. Forced-status
 * scenarios (simulating a Hub error response) are opt-in via
 * FAKE_HUB_SCENARIO_PATH, same convention as fake_hub_artifact_server.php.
 */
$logPath = (string) getenv('FAKE_HUB_LOG_PATH');
$scenarioPath = getenv('FAKE_HUB_SCENARIO_PATH') ?: null;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

header('Content-Type: application/json');

function fakeHubInventoryScenario(?string $scenarioPath): array
{
    if ($scenarioPath === null || !is_file($scenarioPath)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($scenarioPath), true);

    return is_array($decoded) ? $decoded : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/local-agent/workspace-inventory') {
    $rawBody = (string) file_get_contents('php://input');
    file_put_contents($logPath, $rawBody . "\n", FILE_APPEND | LOCK_EX);

    $scenario = fakeHubInventoryScenario($scenarioPath);
    $forcedStatus = $scenario['status'] ?? null;
    if (is_int($forcedStatus)) {
        http_response_code($forcedStatus);
        echo json_encode(['status' => 'forced_failure']);

        return;
    }

    http_response_code(200);
    echo json_encode(['status' => 'accepted', 'mapping_id' => json_decode($rawBody, true)['mapping_id'] ?? null]);

    return;
}

http_response_code(404);
echo json_encode(['status' => 'not_found']);
