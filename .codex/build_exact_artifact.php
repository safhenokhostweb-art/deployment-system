<?php

declare(strict_types=1);

$root = 'C:/ProgramData/DeploymentLocalAgent/agent';
foreach ([
    'Security.php', 'Logger.php', 'Client.php', 'GitCommandException.php', 'JobMaxRuntimeExceededException.php',
    'GitManager.php', 'SafeFilesystem.php', 'BuildWorkspace.php', 'WorkspaceSourcePreparer.php',
    'ArtifactBuilder.php', 'LocalBuildFailedException.php', 'LocalBuildRunner.php', 'ArtifactUploader.php',
] as $file) {
    require $root . '/lib/' . $file;
}

use LocalAgent\ArtifactBuilder;
use LocalAgent\ArtifactUploader;
use LocalAgent\Client;
use LocalAgent\GitManager;
use LocalAgent\LocalBuildRunner;
use LocalAgent\Logger;
use LocalAgent\WorkspaceSourcePreparer;

$config = require $root . '/config.php';
$project = $config['projects']['deployment-system'] ?? null;
if (!is_array($project) || !is_string($project['local_path'] ?? null)) {
    fwrite(STDERR, "deployment-system mapping is unavailable.\n");
    exit(2);
}

$composer = $config['composer_command'] ?? ['composer'];
$npm = $config['npm_command'] ?? ['npm'];
if (!is_array($composer) || $composer === [] || !is_array($npm) || $npm === []) {
    fwrite(STDERR, "typed composer/npm command configuration is invalid.\n");
    exit(2);
}

$logger = new Logger($config['log_dir']);
$client = new Client(
    baseUrl: $config['hub_url'],
    localAgentId: $config['local_agent_id'],
    agentToken: $config['agent_token'],
    signingSecret: $config['signing_secret'],
    timeoutSeconds: $config['request_timeout_seconds'],
    logger: $logger,
);
$git = new GitManager(timeouts: [
    'status' => (int) ($config['git_status_timeout_seconds'] ?? 30),
    'add' => (int) ($config['git_add_timeout_seconds'] ?? 120),
    'commit' => (int) ($config['git_commit_timeout_seconds'] ?? 60),
    'push' => (int) ($config['git_push_timeout_seconds'] ?? 180),
    'pull' => (int) ($config['git_pull_timeout_seconds'] ?? 180),
], jobHeartbeatIntervalSeconds: (int) ($config['job_heartbeat_interval_seconds'] ?? 25));

$uploader = new ArtifactUploader(
    new LocalBuildRunner($git, array_values($composer), array_values($npm), $config['php_binary'] ?? null, logger: $logger, npmGracePeriodSeconds: (int) ($config['npm_grace_period_seconds'] ?? 120)),
    new WorkspaceSourcePreparer($git),
    new ArtifactBuilder(),
    $client,
    $logger,
    str_replace('\\', '/', rtrim((string) ($config['workspace_base_dir'] ?? (sys_get_temp_dir() . '/DeploymentLocalAgent/builds')), '\\/')),
    (int) ($config['workspace_ttl_seconds'] ?? 86400),
);

$result = $uploader->buildAndUpload(
    'deployment-system',
    $project['local_path'],
    '9a500f6c6fecb7382141c38e9131cb26d1bc989f',
    (string) ($project['branch'] ?? 'master'),
    'exact-sha-9a500f6c6fec',
);

echo 'ARTIFACT_BUILD_RESULT=' . ($result['succeeded'] ? 'succeeded' : 'failed') . PHP_EOL;
echo 'FAILURE_CODE=' . ($result['failureCode'] ?? 'none') . PHP_EOL;
echo 'MESSAGE=' . preg_replace('/(token|secret|password)\s*[:=]\s*\S+/i', '$1=<redacted>', $result['message']) . PHP_EOL;
exit($result['succeeded'] ? 0 : 1);
