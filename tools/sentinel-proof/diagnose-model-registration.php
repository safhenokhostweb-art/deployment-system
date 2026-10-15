<?php
declare(strict_types=1);
require __DIR__ . '/../../service/vendor/autoload.php';
use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelLocalHubAdapter;
$config = SentinelProofRunConfig::create(dirname(__DIR__, 2), str_repeat('a', 40), 'diagnostic-fixture', 18103, 18104, 'diagnostic-fixture');
$files = new SentinelLocalProofFilesystem($config);
$hub = SentinelLocalHubAdapter::inMemoryFixture($files);
$hub->initialize(true);
try {
    (new ReflectionMethod($hub, 'saveModel'))->invoke($hub, App\Models\Project::class, [
        'project_key' => 'diagnostic-fixture', 'name' => 'diagnostic-fixture', 'environment' => 'local',
        'branch' => 'diagnostic-fixture', 'repository' => 'diagnostic-fixture', 'requires_complete_artifact' => true,
    ]);
    echo "standalone_project_registration=PASS\n";
} catch (Throwable $error) {
    // Do not print the message: it may include bound values or connection details.
    echo 'exception_class=', get_class($error), "\n";
    echo 'exception_origin=', basename($error->getFile()), ':', $error->getLine(), "\n";
    echo 'message_sha256=', hash('sha256', $error->getMessage()), "\n";
    exit(1);
}
