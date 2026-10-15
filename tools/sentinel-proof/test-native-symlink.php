<?php

declare(strict_types=1);

// Standalone component fixtures only: no framework, database, release or Agent.
require __DIR__ . '/../../service/app/Services/Sentinel/LocalProof/WindowsNativeSymlinkCreator.php';

use App\Services\Sentinel\LocalProof\WindowsNativeSymlinkCreator;

$root = sys_get_temp_dir() . '/native-link-component-' . bin2hex(random_bytes(12));
mkdir($root);
$root = (string) realpath($root);
mkdir($root . '/directory');
file_put_contents($root . '/file.txt', 'component fixture');
file_put_contents($root . '/copy.txt', 'component fixture');
$creator = new WindowsNativeSymlinkCreator();
$checks = 0;
$reject = function (string $name, string $link, string $target) use ($creator, $root, &$checks): void {
    try { $creator->create($link, $target, $root); }
    catch (RuntimeException $e) { echo "PASS rejection: $name\n"; $checks++; return; }
    throw new RuntimeException("Expected rejection: $name");
};
$reject('outside target', $root . '/outside-target', __FILE__);
$reject('outside link', $root . '-escape', $root . '/file.txt');
$reject('target traversal', $root . '/traversal-target', $root . '/directory/../file.txt');
$reject('link traversal', $root . '/directory/../traversal-link', $root . '/file.txt');
$reject('missing target', $root . '/missing-link', $root . '/missing');
$reject('existing copy', $root . '/copy.txt', $root . '/file.txt');
$reject('existing directory / junction destination', $root . '/directory', $root . '/file.txt');
if (PHP_OS_FAMILY === 'Windows') {
    // Junction fixture is solely a rejection test, never a creation fallback.
    $quote = fn (string $value): string => "'" . str_replace("'", "''", $value) . "'";
    $command = '$ErrorActionPreference="Stop"; New-Item -ItemType Junction -Path '
        . $quote($root . '/junction') . ' -Target ' . $quote($root . '/directory') . ' | Out-Null';
    $process = proc_open(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
        $command],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Junction fixture executor unavailable'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Junction fixture failed: ' . $error); }
    $reject('real junction target', $root . '/junction-target-link', $root . '/junction');
    $reject('real junction destination', $root . '/junction', $root . '/file.txt');
    $reject('junction parent', $root . '/junction/nested-link', $root . '/file.txt');
    $invalidNativeLink = $root . '/' . str_repeat('n', 300);
    try {
        $creator->create($invalidNativeLink, $root . '/file.txt', $root);
        throw new LogicException('Expected native API failure');
    } catch (RuntimeException $e) {
        if (!str_starts_with($e->getMessage(), 'proof_native_symlink_failed:')) { throw $e; }
        if (file_exists($invalidNativeLink) || is_link($invalidNativeLink)) { throw new LogicException('Fallback created'); }
        echo "PASS native failure without fallback (invalid Windows filename)\n";
        $checks++;
    }
}
$native = 0;
$blocked = 0;
foreach (['file.txt' => 'file-link', 'directory' => 'directory-link'] as $target => $link) {
    try {
        $creator->create($root . '/' . $link, $root . '/' . $target, $root);
        if (!is_link($root . '/' . $link) || realpath($root . '/' . $link) !== realpath($root . '/' . $target)) {
            throw new LogicException('Link verification failed');
        }
        echo "PASS native link: $target (helper verifies reparse tag and native final target on Windows)\n";
        $native++;
        $reject('symlink input / escape', $root . '/via-link-' . $link, $root . '/' . $link);
    } catch (RuntimeException $e) {
        if (PHP_OS_FAMILY !== 'Windows' || !str_starts_with($e->getMessage(), 'proof_native_symlink_failed:')) { throw $e; }
        if (file_exists($root . '/' . $link) || is_link($root . '/' . $link)) {
            throw new LogicException('Native failure left a link; verification failed closed, fixture preserved');
        }
        echo "BLOCKED native link: $target: {$e->getMessage()}\n";
        echo "PASS failure without fallback: $target\n";
        $blocked++;
    }
}
echo "FIXTURE_ROOT: $root (preserved; no cleanup)\n";
echo "REJECTION_CHECKS: $checks; NATIVE_SUCCESS: $native; NATIVE_BLOCKED: $blocked\n";
echo 'UNIX_BEHAVIOR: ' . (PHP_OS_FAMILY === 'Windows' ? 'NOT EXECUTED ON WINDOWS' : 'VERIFIED') . "\n";
exit($blocked > 0 ? 2 : 0);
