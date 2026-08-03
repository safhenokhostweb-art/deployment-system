<?php

/**
 * A minimal fake Hub for `php -S`, used only by
 * tests/run_artifact.php — accepts any request without verifying its
 * HMAC signature (that's already covered by the real Hub's
 * LocalAgentArtifactFlowTest suite). Emulates the two-phase artifact
 * endpoints closely enough to prove ArtifactUploader's own orchestration
 * logic end-to-end: POST /api/local-agent/artifacts records metadata and
 * returns an artifact_id; POST /api/local-agent/artifacts/{id}/content
 * writes the raw request body to FAKE_HUB_STORAGE_DIR/{id}.bin so the
 * test can assert on the exact bytes received.
 */
$storageDir = getenv('FAKE_HUB_STORAGE_DIR');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/local-agent/artifacts') {
    $body = json_decode(file_get_contents('php://input'), true);
    $artifactId = bin2hex(random_bytes(16));
    file_put_contents("{$storageDir}/{$artifactId}.meta.json", json_encode($body));
    http_response_code(201);
    echo json_encode(['status' => 'ok', 'artifact_id' => $artifactId]);

    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/local-agent/artifacts/([a-f0-9]+)/content$#', $path, $m)) {
    $artifactId = $m[1];
    $rawBody = file_get_contents('php://input');
    file_put_contents("{$storageDir}/{$artifactId}.bin", $rawBody);
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'artifact_id' => $artifactId, 'size_bytes' => strlen($rawBody)]);

    return;
}

http_response_code(404);
echo json_encode(['status' => 'not_found']);
