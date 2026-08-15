<?php

/**
 * A minimal fake Hub for `php -S`, used only by tests/run_recipe.php.
 * Accepts any request without verifying its HMAC signature — Client's
 * signing is already covered by the real Hub's AgentJobFlowTest/
 * AgentJobHeartbeatTest suites; the point of this fixture is to let
 * RecipeStepRunner's own status/log/heartbeat calls actually succeed
 * (rather than throw a connection error) so its step-execution and
 * rollback logic can be exercised end-to-end for real. Every request is
 * appended as one NDJSON line to FAKE_HUB_LOG so the test can assert on
 * the exact sequence of calls RecipeStepRunner made.
 */
$logPath = getenv('FAKE_HUB_LOG');
$body = file_get_contents('php://input');
$decoded = json_decode($body, true);

$entry = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH),
    'body' => $decoded,
];

if ($logPath !== false && $logPath !== '') {
    file_put_contents($logPath, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
