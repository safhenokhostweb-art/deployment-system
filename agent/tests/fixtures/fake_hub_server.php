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

// Optional artifact endpoint for the artifact-native Recipe scenario in
// run_recipe.php. The fixture remains a permissive fake Hub; production
// authorization and signing are covered by the Laravel feature tests.
if (preg_match('#^/api/agent/artifacts/([^/]+)/download$#', $entry['path'], $matches) === 1) {
    $manifestPath = getenv('ARTIFACT_MANIFEST_PATH');
    $manifest = $manifestPath ? json_decode((string) @file_get_contents($manifestPath), true) : [];
    $item = is_array($manifest) ? ($manifest[$matches[1]] ?? null) : null;

    if (!is_array($item) || !isset($item['file']) || !is_file($item['file'])) {
        http_response_code(404);
        echo 'not found';

        return;
    }

    header('Content-Type: application/octet-stream');
    readfile($item['file']);

    return;
}

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
