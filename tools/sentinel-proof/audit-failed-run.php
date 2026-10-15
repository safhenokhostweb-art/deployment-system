<?php
declare(strict_types=1);
// Read-only inspection: immutable SQLite URI and no application bootstrap.
$root = 'C:/Users/Farshad-IT/AppData/Local/Temp/SentinelProof/2f606c65-a4c9-48af-8646-de4ae6f12d49';
$db = new PDO('sqlite:file:' . $root . '/hub/database.sqlite?mode=ro&immutable=1', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA query_only=ON');
$result = [];
foreach (['projects', 'releases', 'artifacts', 'deployments', 'local_agents', 'heartbeat_nonces'] as $table) {
    $result[$table] = ['count' => (int) $db->query("SELECT COUNT(*) FROM $table")->fetchColumn(),
        'schema' => $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC)];
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
if (in_array('--validate-artifact-read-only', $argv, true)) {
    require __DIR__ . '/../../service/vendor/autoload.php';
    $config = App\Services\Sentinel\LocalProof\SentinelProofRunConfig::restore(json_decode(file_get_contents($root . '/run.json'), true));
    $files = new App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem($config);
    $adapter = new App\Services\Sentinel\LocalProof\SentinelLocalArtifactAdapter($files);
    try {
        $adapter->receive($root . '/artifacts/exact-commit-final.zip', '524867a2f59abe57d0e8bad30be6d2c4e22bc4909c1801aa24b1e9c64e925221');
        echo "read_only_artifact_validation=PASS\n";
    } catch (Throwable $error) {
        echo get_class($error), ': ', preg_match('/^proof_[a-z_]+$/D', $error->getMessage()) ? $error->getMessage() : '[REDACTED]', "\n";
        echo basename($error->getFile()), ':', $error->getLine(), "\n";
        exit(1);
    }
}
