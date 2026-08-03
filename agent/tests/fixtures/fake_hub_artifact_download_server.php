<?php

/**
 * A minimal fake Hub for `php -S`, used only by tests/run_artifact.php.
 * Serves whatever MANIFEST_PATH (a JSON file of
 * {public_id: {file: absolute_path, status: int}}) says for each
 * requested artifact public_id — lets the test simulate a healthy
 * download, a 404, a 409 (not yet available), and corrupt bytes, all
 * against a real HTTP round trip and real Client/ArtifactManager code.
 */
$manifestPath = getenv('ARTIFACT_MANIFEST_PATH');
$manifest = json_decode(file_get_contents($manifestPath), true) ?: [];

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (preg_match('#^/api/agent/artifacts/([^/]+)/download$#', $path, $m)) {
    $publicId = $m[1];
    $entry = $manifest[$publicId] ?? null;

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

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['message' => 'unknown route']);
