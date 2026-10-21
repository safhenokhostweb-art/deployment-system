<?php

declare(strict_types=1);

/**
 * Standalone contract test for ArtifactFileManifest + ArtifactBuilder
 * `file_manifest_hash` alignment.
 *
 * Root-cause fix: ArtifactBuilder::build() was returning
 * $fileManifest->manifestHash() (SHA-256 of canonical JSON WITHOUT the
 * manifest_hash field) as the artifact's file_manifest_hash, but the Hub's
 * ArtifactService::validateArchiveContents() verifies against
 * hash('sha256', raw ZIP entry bytes of .deployment/file-manifest.json)
 * which INCLUDES the manifest_hash field. The fix makes
 * file_manifest_hash = hash('sha256', $manifest->toJson()) so producer and
 * consumer agree.
 *
 * Contract assertions:
 *   1. manifestHash() != hash('sha256', $manifest->toJson()) — they are
 *      intentionally different (toJson includes manifest_hash field).
 *   2. hash('sha256', $manifest->toJson()) is a valid 64-char lowercase hex.
 *   3. Round-trip: fromJson(toJson()) preserves entries, manifestHash,
 *      entryCount, totalSize.
 *   4. ArtifactBuilder::build() returns file_manifest_hash matching
 *      hash('sha256', raw ZIP entry bytes of .deployment/file-manifest.json).
 *
 * Usage: php tests/run_manifest_hash_contract.php
 */

require __DIR__ . '/../lib/SafeFilesystem.php';
require __DIR__ . '/../lib/ArtifactFileManifest.php';
require __DIR__ . '/../lib/ArtifactBuilder.php';

use LocalAgent\ArtifactBuilder;
use LocalAgent\ArtifactFileManifest;
use LocalAgent\SafeFilesystem;

$failures = [];
$passCount = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passCount, $failures;
    if ($condition) {
        $passCount++;
        echo "  PASS: {$name}\n";
    } else {
        $failures[] = $name;
        echo "  FAIL: {$name} — {$detail}\n";
    }
}

echo "=== ArtifactFileManifest / ArtifactBuilder file_manifest_hash contract ===\n\n";

// ---- Setup fixture directory with some source files ----
$fixtureDir = sys_get_temp_dir() . '/la-manifest-contract-' . bin2hex(random_bytes(6));
mkdir($fixtureDir, 0755, true);
mkdir("{$fixtureDir}/vendor", 0755, true);
mkdir("{$fixtureDir}/src", 0755, true);
mkdir("{$fixtureDir}/public", 0755, true);
mkdir("{$fixtureDir}/.deployment", 0755, true);
file_put_contents("{$fixtureDir}/composer.json", json_encode(['name' => 'fixture/app'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents("{$fixtureDir}/vendor/autoload.php", '<?php return [];');
file_put_contents("{$fixtureDir}/src/Helper.php", '<?php namespace App; class Helper {}');
file_put_contents("{$fixtureDir}/public/index.php", '<?php echo "hello";');

// ---- Test 1-3: Manifest-level contract ----
$collected = [];
SafeFilesystem::collectFilesSafely($fixtureDir, $fixtureDir, '', $collected);
$manifest = ArtifactFileManifest::buildFromFiles($collected);

$manifestHash = $manifest->manifestHash();                  // canonical JSON WITHOUT manifest_hash field
$toJsonHash = hash('sha256', $manifest->toJson());         // raw bytes WITH manifest_hash field
$toJson = $manifest->toJson();

check('manifestHash() differs from hash(toJson()) (intentional distinction)',
    $manifestHash !== $toJsonHash,
    "manifestHash={$manifestHash} toJsonHash={$toJsonHash}");

check('hash(toJson()) is valid 64-char lowercase hex',
    preg_match('/^[0-9a-f]{64}$/D', $toJsonHash) === 1,
    "got: {$toJsonHash}");

check('toJson() contains manifest_hash field',
    str_contains($toJson, '"manifest_hash"'),
    "toJson preview: " . substr($toJson, 0, 200));

// Round-trip: fromJson(toJson()) preserves everything
$roundTripped = ArtifactFileManifest::fromJson($toJson);
check('Round-trip preserves manifestHash',
    hash_equals($manifestHash, $roundTripped->manifestHash()),
    "original={$manifestHash} roundtripped={$roundTripped->manifestHash()}");
check('Round-trip preserves entryCount',
    $manifest->entryCount() === $roundTripped->entryCount(),
    "original=" . $manifest->entryCount() . " roundtripped=" . $roundTripped->entryCount());
check('Round-trip preserves totalSize',
    $manifest->totalSize() === $roundTripped->totalSize(),
    "original=" . $manifest->totalSize() . " roundtripped=" . $roundTripped->totalSize());
check('Round-trip preserves entries',
    $manifest->entries() === $roundTripped->entries(),
    'entries differ after round-trip');

// ---- Test 4: ArtifactBuilder.build() file_manifest_hash matches actual ZIP bytes ----
$builder = new ArtifactBuilder();
$archive = $builder->build(
    $fixtureDir,
    $fixtureDir,
    time(),
    ['ranComposer' => false, 'ranNpmBuild' => false],
    null,
    ['project_key' => 'deployment-system', 'commit_sha' => 'abc123', 'branch' => 'master']
);

$zip = new \ZipArchive();
$zip->open($archive['path']);
$manifestIndex = $zip->locateName('.deployment/file-manifest.json');
$rawManifestBytes = $zip->getFromIndex($manifestIndex);
$zip->close();
$zipEntryBytesHash = hash('sha256', $rawManifestBytes);

check('ArtifactBuilder.build() file_manifest_hash equals hash(raw ZIP entry bytes)',
    hash_equals($archive['file_manifest_hash'], $zipEntryBytesHash),
    "builder={$archive['file_manifest_hash']} zipBytes={$zipEntryBytesHash}");

// ---- Cleanup ----
SafeFilesystem::recursiveDelete($fixtureDir);
@unlink($archive['path']);

echo "\n=== {$passCount} passed, " . count($failures) . " failed ===\n";
exit(count($failures) === 0 ? 0 : 1);
