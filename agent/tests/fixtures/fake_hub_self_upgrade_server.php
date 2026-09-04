<?php

/**
 * A minimal fake Hub for `php -S`, used only by tests/run_self_upgrade.php.
 * Serves GET /api/agent/server-agent-packages/{id}/download (a real,
 * pre-built zip on disk) and logs every /api/agent/control-jobs/*
 * status/report POST as one NDJSON line, mirroring
 * fake_hub_pipeline_server.php's own conventions.
 */
$logPath = getenv('FAKE_HUB_LOG');
$packagePath = getenv('FAKE_HUB_PACKAGE_PATH');
$packageStatus = (int) (getenv('FAKE_HUB_PACKAGE_STATUS') ?: 200);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (preg_match('#^/api/agent/server-agent-packages/([^/]+)/download$#', (string) $path) === 1) {
    if ($packageStatus !== 200 || $packagePath === false || !is_file($packagePath)) {
        http_response_code($packageStatus === 200 ? 404 : $packageStatus);
        header('Content-Type: application/json');
        echo json_encode(['message' => 'not found']);

        return;
    }

    header('Content-Type: application/octet-stream');
    readfile($packagePath);

    return;
}

$body = file_get_contents('php://input');
$decoded = json_decode($body, true);

if ($logPath !== false && $logPath !== '') {
    file_put_contents($logPath, json_encode(['method' => $_SERVER['REQUEST_METHOD'] ?? '', 'path' => $path, 'body' => $decoded]) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');
echo json_encode(['status' => 'ok']);
