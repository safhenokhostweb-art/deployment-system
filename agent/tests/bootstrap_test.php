<?php

require __DIR__ . '/../lib/ArtifactFileManifest.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/ReleaseManager.php';
require __DIR__ . '/../lib/ServerAgentRuntimeSnapshotCollector.php';

use DeploymentAgent\ArtifactFileManifest;
use DeploymentAgent\SafeFilesystem;
use DeploymentAgent\ReleaseManager;
use DeploymentAgent\ServerAgentRuntimeSnapshotCollector;

echo "Testing ArtifactFileManifest bootstrap... ";
$files = ['test.txt' => __FILE__];
$manifest = ArtifactFileManifest::buildFromFiles($files);
echo "OK (entries: " . $manifest->entryCount() . ", hash: " . substr($manifest->manifestHash(), 0, 16) . "...)\n";

echo "Testing SafeFilesystem bootstrap... ";
$result = SafeFilesystem::isEffectivelyEmpty(sys_get_temp_dir());
echo "OK (isEffectivelyEmpty returned: " . ($result ? 'true' : 'false') . ")\n";

echo "Testing ReleaseManager bootstrap... ";
$rm = new ReleaseManager();
echo "OK\n";

echo "Testing ServerAgentRuntimeSnapshotCollector bootstrap... ";
$collector = new ServerAgentRuntimeSnapshotCollector(new ReleaseManager(), ['enabled' => false]);
echo "OK\n";

echo "\nAll Server Agent bootstrap tests passed!\n";