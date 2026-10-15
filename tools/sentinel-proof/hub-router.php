<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/service/vendor/autoload.php';

use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelLocalHubAdapter;

header('Content-Type: application/json');
try {
    if (PHP_SAPI !== 'cli-server') { throw new RuntimeException('proof_server_only'); }
    $path = getenv('SENTINEL_PROOF_CONFIG');
    $temp = str_replace('\\', '/', (string) realpath(sys_get_temp_dir()));
    if (!is_string($path) || !preg_match('~^' . preg_quote($temp, '~') . '/SentinelProof/[0-9a-f-]{36}/run\.json$~D', $path)) {
        throw new RuntimeException('proof_config_rejected');
    }
    $config = SentinelProofRunConfig::restore(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    $filesystem = new SentinelLocalProofFilesystem($config);
    $filesystem->assertContained($path);
    if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
        throw new RuntimeException('proof_loopback_required');
    }
    $hub = new SentinelLocalHubAdapter($filesystem);
    $status = $hub->ingest($_SERVER['REQUEST_METHOD'] ?? '', $_SERVER['REQUEST_URI'] ?? '',
        getallheaders(), (string) file_get_contents('php://input'));
    http_response_code($status);
    echo json_encode(['status' => $status === 200 ? 'ok' : 'rejected',
        'hmac_verified' => $status === 200], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    http_response_code(500);
    echo '{"status":"rejected"}';
}

