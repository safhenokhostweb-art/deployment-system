<?php

declare(strict_types=1);

require __DIR__ . '/../lib/Security.php';
require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/SecretRedactor.php';
require __DIR__ . '/../lib/Client.php';

// Disposable fixture server; this never contacts the real Hub.
if (PHP_SAPI === 'cli-server') {
    $body = (string) file_get_contents('php://input');
    $valid = ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer fixture-token'
        && ($_SERVER['HTTP_X_DEPLOYMENT_LOCAL_AGENT_ID'] ?? '') === 'fixture-agent'
        && \LocalAgent\Security::verify(
            $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_X_DEPLOYMENT_TIMESTAMP'] ?? '',
            $_SERVER['HTTP_X_DEPLOYMENT_NONCE'] ?? '', $body, 'fixture-secret',
            $_SERVER['HTTP_X_DEPLOYMENT_SIGNATURE'] ?? '',
        );
    header('Content-Type: application/json');
    http_response_code($valid ? 200 : 401);
    echo json_encode(['length' => strlen($body), 'checksum' => hash('sha256', $body)]);
    return;
}

$serverSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($serverSocket === false) { throw new RuntimeException('Cannot reserve a fixture port.'); }
$address = stream_socket_get_name($serverSocket, false);
fclose($serverSocket);
$logDir = sys_get_temp_dir() . '/upload-heartbeat-transport-' . bin2hex(random_bytes(6));
mkdir($logDir, 0700, true);
$process = proc_open([PHP_BINARY, '-S', $address, __FILE__], [0 => ['pipe', 'r'], 1 => ['file', $logDir . '/server.log', 'w'], 2 => ['file', $logDir . '/server.log', 'a']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start fixture server.'); }
fclose($pipes[0]);

try {
    $port = (int) substr(strrchr($address, ':'), 1);
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($socket !== false) { fclose($socket); $ready = true; break; }
        usleep(100_000);
    }
    if (!$ready) { throw new RuntimeException('Fixture server did not start.'); }
    $logger = new \LocalAgent\Logger($logDir);
    $client = new \LocalAgent\Client('http://' . $address, 'fixture-agent', 'fixture-token', 'fixture-secret', 2, $logger);
    $body = str_repeat('fixture-upload-', 75_000);
    $heartbeats = 0;
    $result = $client->uploadRawBody('POST', '/fixture/content', $body, static function () use (&$heartbeats): void { $heartbeats++; });
    if ($result['status'] !== 200 || $result['body']['length'] !== strlen($body)
        || $result['body']['checksum'] !== hash('sha256', $body) || $heartbeats !== 0) {
        throw new RuntimeException('Signed upload bytes or fast-transfer throttle failed.');
    }
    $result = $client->uploadRawBody('POST', '/fixture/content', 'null callback fixture', null);
    if ($result['status'] !== 200) { throw new RuntimeException('Null callback upload failed.'); }
    $invalidClient = new \LocalAgent\Client('http://' . $address, 'fixture-agent', 'fixture-token', 'invalid-fixture-secret', 2, $logger);
    if ($invalidClient->uploadRawBody('POST', '/fixture/content', $body)['status'] !== 401) {
        throw new RuntimeException('Invalid fixture signature was accepted.');
    }
    echo "Passed 3 isolated upload transport checks (signed bytes, null callback, invalid signature).\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($logDir . '/*.log') ?: [] as $log) { unlink($log); }
    rmdir($logDir);
}
