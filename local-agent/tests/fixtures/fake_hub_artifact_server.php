<?php

/**
 * Deterministic in-process Hub double for local ArtifactUploader tests.
 * It deliberately models the Hub's canonical artifact identity so a retry
 * can be exercised after the server has committed the first request but its
 * response is unavailable to the client. Scenario/state files are opt-in;
 * the existing healthy-upload fixture continues to work unchanged.
 */
$storageDir = (string) getenv('FAKE_HUB_STORAGE_DIR');
$scenarioPath = getenv('FAKE_HUB_SCENARIO_PATH') ?: "{$storageDir}/scenario.json";
$statePath = "{$storageDir}/state.json";
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

header('Content-Type: application/json');

/** @return array<string, mixed> */
function fakeHubRead(string $path, array $default): array
{
    if (!is_file($path)) return $default;
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : $default;
}

/** @param array<string, mixed> $state */
function fakeHubWrite(string $path, array $state): void
{
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
}

/** @param array<string, mixed> $scenario */
function fakeHubForcedStatus(array $scenario, string $operation, int $attempt): ?int
{
    $statuses = $scenario["{$operation}_statuses"] ?? [];
    if (!is_array($statuses) || !array_key_exists($attempt - 1, $statuses)) return null;
    $status = $statuses[$attempt - 1];
    return is_int($status) ? $status : null;
}

/** @param array<string, mixed> $scenario */
function fakeHubLoseResponse(array $scenario, string $operation, int $attempt): bool
{
    $attempts = $scenario["{$operation}_lost_response_attempts"] ?? [];
    return is_array($attempts) && in_array($attempt, $attempts, true);
}

$scenario = fakeHubRead($scenarioPath, []);
$state = fakeHubRead($statePath, ['next_id' => 1, 'create_attempts' => 0, 'upload_attempts' => 0, 'artifacts' => []]);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/api/local-agent/artifacts') {
    $state['create_attempts']++;
    $attempt = $state['create_attempts'];
    if (($status = fakeHubForcedStatus($scenario, 'create', $attempt)) !== null) {
        fakeHubWrite($statePath, $state);
        http_response_code($status);
        echo json_encode(['status' => 'forced_failure', 'message' => "forced create HTTP {$status}"]);
        return;
    }

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body) || !is_string($body['project_key'] ?? null) || !is_string($body['commit_hash'] ?? null) || !is_string($body['checksum_sha256'] ?? null)) {
        fakeHubWrite($statePath, $state);
        http_response_code(422);
        echo json_encode(['status' => 'validation_error']);
        return;
    }
    $identity = $body['project_key'] . "\n" . $body['commit_hash'];
    $artifact = $state['artifacts'][$identity] ?? null;
    if (is_array($artifact) && $artifact['checksum'] !== $body['checksum_sha256']) {
        fakeHubWrite($statePath, $state);
        http_response_code(409);
        echo json_encode(['status' => 'error', 'code' => 'artifact_checksum_conflict']);
        return;
    }
    if (!is_array($artifact)) {
        $artifact = ['id' => 'artifact-' . $state['next_id']++, 'checksum' => $body['checksum_sha256'], 'status' => 'uploading', 'size_bytes' => $body['size_bytes'] ?? 0];
        $state['artifacts'][$identity] = $artifact;
        file_put_contents("{$storageDir}/{$artifact['id']}.meta.json", json_encode($body, JSON_THROW_ON_ERROR));
    }
    fakeHubWrite($statePath, $state);
    if (fakeHubLoseResponse($scenario, 'create', $attempt)) {
        // Send an intentionally truncated HTTP body after persisting the
        // mutation. cURL reports CURLE_PARTIAL_FILE, i.e. a real transport
        // failure rather than an HTTP status that merely happens to be 5xx.
        http_response_code(201);
        header('Content-Length: 64');
        echo '{"status":"ok"';
        flush();
        exit;
    }
    http_response_code(201);
    echo json_encode(['status' => 'ok', 'artifact_id' => $artifact['id']]);
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^/api/local-agent/artifacts/(artifact-[A-Za-z0-9-]+)/content$#', (string) $path, $m)) {
    $state['upload_attempts']++;
    $attempt = $state['upload_attempts'];
    if (($status = fakeHubForcedStatus($scenario, 'upload', $attempt)) !== null) {
        fakeHubWrite($statePath, $state);
        http_response_code($status);
        echo json_encode(['status' => 'forced_failure', 'message' => "forced upload HTTP {$status}"]);
        return;
    }
    $artifactId = $m[1];
    $identity = null;
    foreach ($state['artifacts'] as $candidateIdentity => $artifact) if (($artifact['id'] ?? null) === $artifactId) { $identity = $candidateIdentity; break; }
    if ($identity === null) { fakeHubWrite($statePath, $state); http_response_code(404); echo json_encode(['status' => 'not_found']); return; }
    $artifact = $state['artifacts'][$identity];
    $rawBody = (string) file_get_contents('php://input');
    if (!hash_equals((string) $artifact['checksum'], hash('sha256', $rawBody))) {
        fakeHubWrite($statePath, $state);
        http_response_code(409);
        echo json_encode(['status' => 'error', 'code' => 'artifact_checksum_conflict']);
        return;
    }
    if ($artifact['status'] === 'uploading') {
        file_put_contents("{$storageDir}/{$artifactId}.bin", $rawBody, LOCK_EX);
        $artifact['status'] = 'available';
        $state['artifacts'][$identity] = $artifact;
    }
    fakeHubWrite($statePath, $state);
    if (fakeHubLoseResponse($scenario, 'upload', $attempt)) {
        // The bytes and available state are already durable, but the
        // response is deliberately cut short to force Client transport retry.
        http_response_code(200);
        header('Content-Length: 64');
        echo '{"status":"ok"';
        flush();
        exit;
    }
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'artifact_id' => $artifactId, 'size_bytes' => strlen($rawBody), 'artifact_status' => $artifact['status']]);
    return;
}

http_response_code(404);
echo json_encode(['status' => 'not_found']);
