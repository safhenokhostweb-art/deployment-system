<?php
declare(strict_types=1);
// Independent read-only audit of preserved bytes. Never used by the proof run.
require __DIR__ . '/../../service/vendor/autoload.php';
use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelLocalArtifactAdapter;
use App\Services\Sentinel\LocalProof\SentinelProofDiagnostics;
use Symfony\Component\Process\Process;
$root = 'C:/Users/Farshad-IT/AppData/Local/Temp/SentinelProof/2f606c65-a4c9-48af-8646-de4ae6f12d49';
$config = SentinelProofRunConfig::restore(json_decode(file_get_contents($root . '/run.json'), true));
$adapter = new SentinelLocalArtifactAdapter(new SentinelLocalProofFilesystem($config));
$git = function (array $args) use ($config): string {
    $p = new Process(array_merge(['git', '--no-optional-locks', '-C', $config->sourceRepository], $args));
    $p->setTimeout(30); $p->mustRun(); return $p->getOutput();
};
try {
    if (trim($git(['rev-parse', 'HEAD'])) !== $config->commitSha
        || trim($git(['branch', '--show-current'])) !== $config->branch) { throw new RuntimeException('proof_source_changed'); }
    $sourceZip = new ZipArchive();
    if ($sourceZip->open($root . '/build/source.zip', ZipArchive::RDONLY) !== true) { throw new RuntimeException('proof_zip_invalid'); }
    $source = [];
    foreach (explode("\0", $git(['ls-tree', '-r', '-z', '--full-tree', $config->commitSha])) as $record) {
        if ($record === '') { continue; }
        if (!preg_match('/^(100644|100755|120000) blob ([0-9a-f]{40})\t(.+)$/sD', $record, $match)) { throw new RuntimeException('proof_source_mode_rejected'); }
        $name = $match[3];
        if (SentinelLocalArtifactAdapter::isSensitiveEntry($name)) { continue; }
        $adapter->assertEntry($name, []);
        if ($match[1] === '120000') { throw new RuntimeException('proof_source_symlink_or_submodule'); }
        $bytes = $sourceZip->getFromName($name);
        if ($bytes === false || !hash_equals($match[2], hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes))) { throw new RuntimeException('proof_source_blob_mismatch'); }
        $opsys = $attributes = 0;
        if (!$sourceZip->getExternalAttributesName($name, $opsys, $attributes)
            || $opsys !== ZipArchive::OPSYS_UNIX || ($attributes >> 16 & 0177777) !== octdec($match[1])) { throw new RuntimeException('proof_source_zip_mismatch'); }
        $source[$name] = hash('sha256', $bytes);
    }
    if ($sourceZip->numFiles !== count($source)) { throw new RuntimeException('proof_source_inventory_mismatch'); }
    $sourceZip->close();
    foreach (SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES as $name) {
        (new ReflectionMethod($adapter, 'assertFixtureSourceContained'))->invoke($adapter, $name);
    }
    $artifact = $root . '/artifacts/exact-commit-final.zip';
    $adapter->verifyChecksum(hash_file('sha256', $artifact), '524867a2f59abe57d0e8bad30be6d2c4e22bc4909c1801aa24b1e9c64e925221');
    $zip = new ZipArchive();
    if ($zip->open($artifact, ZipArchive::RDONLY) !== true) { throw new RuntimeException('proof_zip_invalid'); }
    $manifest = json_decode($zip->getFromName('.deployment/release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    $adapter->validateManifest($manifest);
    $declared = $manifest['source_paths']; $actual = array_keys($source); sort($declared); sort($actual);
    if ($declared !== $actual) { throw new RuntimeException('proof_source_inventory_mismatch'); }
    $seen = $hashes = []; $total = 0;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $stat = $zip->statIndex($index); $name = $stat['name']; $total += $stat['size'];
        if ($total > 536870912 || isset($seen[$name])) { throw new RuntimeException('proof_zip_size_or_duplicate'); }
        $seen[$name] = true;
        $opsys = $attributes = 0; $zip->getExternalAttributesIndex($index, $opsys, $attributes);
        $bytes = $zip->getFromIndex($index);
        $adapter->assertZipEntry($name, $manifest['required_paths'], $source, $bytes, $attributes >> 16);
        if (!str_ends_with($name, '/')) { $hashes[$name] = hash('sha256', $bytes); }
    }
    foreach ($source as $name => $hash) { if (!isset($seen[$name])) { throw new RuntimeException('proof_committed_source_missing'); } }
    $adapter->verifySourceEntries($source, $hashes);
    if (!hash_equals($manifest['composer_lock_sha256'], $hashes['composer.lock'])) { throw new RuntimeException('proof_lock_mismatch'); }
    foreach ($manifest['required_paths'] as $name) { if ($zip->locateName($name) === false) { throw new RuntimeException('proof_required_path_missing'); } }
    $zip->close();
    echo json_encode(['read_only_artifact_audit' => 'PASS', 'git_blob_count' => count($source),
        'artifact_sha256' => hash_file('sha256', $artifact), 'original_exception_cause' => 'NOT_PROVABLE_FROM_PRESERVED_EVIDENCE'], JSON_PRETTY_PRINT), "\n";
} catch (Throwable $error) {
    echo json_encode(SentinelProofDiagnostics::describe($error, 'READ_ONLY_ARTIFACT_AUDIT'), JSON_PRETTY_PRINT), "\n";
    exit(1);
}
