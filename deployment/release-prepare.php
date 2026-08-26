#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__.'/lib/ReleasePreparer.php';

use DeploymentContract\ReleasePreparer;

$arguments = array_slice($argv, 1);
$prepare = in_array('prepare', $arguments, true);
$taskPath = '.deployment/release-task.json';
foreach ($arguments as $argument) {
    if (str_starts_with($argument, '--task=')) {
        $taskPath = substr($argument, 7);
    }
}

try {
    $result = (new ReleasePreparer(getcwd() ?: '.', $taskPath))->run($prepare);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
