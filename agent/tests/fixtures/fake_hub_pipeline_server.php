<?php

/**
 * A minimal fake Hub for `php -S`, used only by tests/run_pipeline.php.
 * Merges the two existing fixtures' behavior: serves a real artifact zip
 * for GET /api/agent/artifacts/{id}/download (manifest-driven, like
 * fake_hub_artifact_download_server.php) and logs every other call
 * (status/log/heartbeat) as one NDJSON line to FAKE_HUB_LOG (like
 * fake_hub_server.php), so a single test can exercise PipelineExecutor's
 * full artifact-download-then-pipeline flow against one process.
 */
$logPath = getenv('FAKE_HUB_LOG');
$manifestPath = getenv('ARTIFACT_MANIFEST_PATH');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if ($path === '/health-ok') {
    http_response_code(200);
    echo 'customer-homepage-marker';
    return;
}

if ($path === '/health-error') {
    http_response_code(500);
    echo 'customer application error';
    return;
}

if (preg_match('#^/api/agent/artifacts/([^/]+)/download$#', $path, $m)) {
    $manifest = json_decode((string) @file_get_contents($manifestPath), true) ?: [];
    $entry = $manifest[$m[1]] ?? null;

    if ($entry === null) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'not found']);

        return;
    }

    if ($entry['status'] !== 200) {
        http_response_code($entry['status']);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'error']);

        return;
    }

    header('Content-Type: application/octet-stream');
    readfile($entry['file']);

    return;
}

$body = file_get_contents('php://input');
$decoded = json_decode($body, true);

$entry = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $path,
    'body' => $decoded,
];

// Root-cause fix (Deployment #183 — sequence collision): the real Hub
// enforces unique(agent_job_id, sequence) on agent_job_log_entries and a
// second write to an already-used sequence throws an uncaught
// QueryException, surfacing as HTTP 500 (see
// AgentTaskService::recordLog()). `php -S` starts a fresh process per
// request, so this reconstructs "already seen" from FAKE_HUB_LOG itself
// (the same durable, cross-request store every other request already
// appends to) rather than in-memory state that wouldn't survive between
// requests. Without this, the fixture could never have caught the real
// production defect — a duplicate /log POST here used to just succeed
// silently, exactly like it silently DIDN'T on the real Hub.
if (preg_match('#^/api/agent/jobs/([^/]+)/log$#', (string) $path, $logMatch) === 1 && is_array($decoded) && isset($decoded['sequence'])) {
    $priorLines = $logPath !== false && $logPath !== '' ? array_filter(explode("\n", (string) @file_get_contents($logPath))) : [];
    foreach ($priorLines as $priorLine) {
        $prior = json_decode($priorLine, true);
        if (
            is_array($prior)
            && ($prior['path'] ?? null) === $path
            && (($prior['body']['sequence'] ?? null) === $decoded['sequence'])
        ) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['message' => 'duplicate sequence for this agent job (unique(agent_job_id, sequence) violation)']);

            return;
        }
    }
}

if ($logPath !== false && $logPath !== '') {
    file_put_contents($logPath, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

// Test-only failure injection for tests/run_pipeline.php's CASE 3 (Hub
// log/status POST failure during the post-artifact permission-normalization
// window), mirroring the confirmed production symptom exactly:
//   FAKE_HUB_FAIL_JOB_IDS — every /log and /status POST for those job ids
//     is answered with HTTP 500 (the Hub is alive but unhealthy, so the
//     diagnostic is never persisted centrally).
//   FAKE_HUB_TRANSPORT_FAIL_JOB_IDS — the post-artifact runtime-permissions
//     /log POST for those job ids is answered with a Content-Length far
//     larger than the body actually sent, so curl reports a transport error
//     and Client::request() throws (the "Hub connection died" variant).
$failJobIds = array_values(array_filter(array_map('trim', explode(',', (string) getenv('FAKE_HUB_FAIL_JOB_IDS')))));
$transportFailJobIds = array_values(array_filter(array_map('trim', explode(',', (string) getenv('FAKE_HUB_TRANSPORT_FAIL_JOB_IDS')))));

if (preg_match('#^/api/agent/jobs/([^/]+)/(log|status)$#', $path, $m)) {
    if (in_array($m[1], $failJobIds, true)) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'server error']);

        return;
    }

    if ($m[2] === 'log'
        && in_array($m[1], $transportFailJobIds, true)
        && ($decoded['step_name'] ?? null) === 'runtime permissions (post-artifact)'
    ) {
        header('Content-Type: application/json');
        header('Content-Length: 1000000');
        echo json_encode(['status' => 'ok']);

        return;
    }
}

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
