<?php

declare(strict_types=1);

require __DIR__ . '/../lib/Logger.php';
require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/BuildWorkspace.php';
require __DIR__ . '/../lib/LocalBuildFailedException.php';
require __DIR__ . '/../lib/ExactCommitEvidence.php';

use LocalAgent\BuildWorkspace;
use LocalAgent\ExactCommitEvidence;
use LocalAgent\LocalBuildFailedException;

$failures = [];
$passes = 0;
$check = static function (string $name, bool $condition) use (&$failures, &$passes): void {
    if ($condition) { $passes++; return; }
    $failures[] = $name;
};
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $child) $value[$key] = $canonicalize($child);
    return $value;
};
$digest = static fn (array $value): string => hash('sha256', json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/exact-evidence-' . bin2hex(random_bytes(4));
$workspace = BuildWorkspace::create($base, 'project', 'job');
try {
    mkdir($workspace->projectDir() . '/.git', 0777, true);
    file_put_contents($workspace->projectDir() . '/.git/HEAD', str_repeat('a', 40) . "\n");
    file_put_contents($workspace->projectDir() . '/Valid.php', '<?php return 1;');
    $contract = [
        'contract_version' => 1, 'project_key' => 'project', 'release_id' => 'release',
        'publish_job_id' => 'job', 'commit_sha' => str_repeat('a', 40), 'branch' => 'main', 'agent_id' => 'agent',
        'required_checks' => [
            ['id' => 'source_provenance', 'type' => 'exact_commit', 'required' => true],
            ['id' => 'php_syntax', 'type' => 'php_syntax', 'required' => true],
            ['id' => 'build_pipeline', 'type' => 'trusted_build', 'required' => true],
            ['id' => 'artifact_manifest', 'type' => 'release_manifest', 'required' => true],
        ],
    ];
    $contract['contract_digest'] = $digest($contract);
    $collector = new ExactCommitEvidence($contract, 'project', 'job', str_repeat('a', 40), 'main', 'agent', '1.5.0');
    $collector->verifySource($workspace, str_repeat('a', 40));
    mkdir($workspace->projectDir() . '/.deployment', 0777, true);
    file_put_contents($workspace->projectDir() . '/.deployment/release-manifest.json', json_encode([
        'format' => 'deployment-system-release-manifest', 'project_key' => 'project',
        'commit_sha' => str_repeat('a', 40), 'branch' => 'main', 'status' => 'pass',
    ]));
    $collector->verifyBuildAndManifest($workspace->projectDir() . '/.deployment/release-manifest.json', ['log' => ['composer install']]);
    $evidence = $collector->finish();
    $claimed = $evidence['evidence_digest'];
    unset($evidence['evidence_digest']);
    $check('evidence digest is deterministic and covers the complete structured payload', hash_equals($claimed, $digest($evidence)));
    $check('all four Hub-required checks are reported in contract order', array_column($evidence['checks'], 'id') === ['source_provenance', 'php_syntax', 'build_pipeline', 'artifact_manifest']);
    $check('PHP syntax check ran against the isolated exact workspace', ($evidence['checks'][1]['details']['files_checked'] ?? 0) === 1);

    $bad = $contract; $bad['branch'] = 'wrong';
    try {
        new ExactCommitEvidence($bad, 'project', 'job', str_repeat('a', 40), 'main', 'agent', '1.5.0');
        $check('tampered contract fails closed', false);
    } catch (LocalBuildFailedException) {
        $check('tampered contract fails closed', true);
    }
} finally {
    $workspace->cleanup();
    @rmdir($base);
}
foreach ($failures as $failure) echo "FAIL: {$failure}\n";
echo "{$passes} passed, " . count($failures) . " failed.\n";
exit($failures === [] ? 0 : 1);
