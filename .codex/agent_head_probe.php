<?php

declare(strict_types=1);

$config = require 'C:/ProgramData/DeploymentLocalAgent/agent/config.php';
$path = $config['projects']['deployment-system']['local_path'] ?? '';

if (!is_string($path) || !is_dir($path)) {
    echo "PROJECT_PATH_VALID=no\n";
    exit(1);
}

$head = trim((string) shell_exec('git -C ' . escapeshellarg($path) . ' rev-parse HEAD 2>&1'));
$status = (string) shell_exec('git -C ' . escapeshellarg($path) . ' status --porcelain 2>&1');
$dirtyCount = count(array_filter(preg_split('/\r?\n/', trim($status))));

echo "PROJECT_PATH_VALID=yes\n";
echo 'HEAD=' . ($head === '5e89824b3018cd4faaa56514d60a4fa75b3b5512' ? 'expected' : 'different') . "\n";
echo "DIRTY_FILE_COUNT={$dirtyCount}\n";
