<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use RuntimeException;

/** Isolated proof harness only; never falls back to junctions or copies. */
final class WindowsNativeSymlinkCreator
{
    public function create(string $link, string $target, string $runRoot): void
    {
        foreach ([$link, $target, $runRoot] as $path) {
            if (str_contains($path, "\0") || preg_match('~(^|[\\\\/])\.\.?([\\\\/]|$)~', $path)) {
                throw new RuntimeException('proof_symlink_path_rejected');
            }
        }
        $root = realpath($runRoot);
        $expected = realpath($target);
        $parent = realpath(dirname($link));
        if ($root === false || $expected === false || $parent === false || !is_dir($root)
            || !$this->same($runRoot, $root) || !$this->same($target, $expected)
            || !$this->same(dirname($link), $parent)
            || !$this->contained($expected, $root) || !$this->contained($parent, $root, true)
            || !$this->contained($link, $root) || !$this->contained($target, $root)
            || file_exists($link) || is_link($link)) {
            throw new RuntimeException('proof_symlink_containment_rejected');
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $helper = dirname(__DIR__, 5) . '/tools/sentinel-proof/windows-native-symlink.ps1';
            $process = proc_open(['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive',
                '-File', $helper, '-LinkPath', $link, '-TargetPath', $target, '-RunRoot', $root],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('proof_native_symlink_executor_unavailable'); }
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            if (proc_close($process) !== 0 || trim($output) !== 'NATIVE_SYMLINK_VERIFIED') {
                throw new RuntimeException('proof_native_symlink_failed: ' . trim($error));
            }
        } elseif (!symlink($expected, $link)) {
            throw new RuntimeException('proof_symlink_failed');
        }
        clearstatcache(true);
        $actual = realpath($link);
        $observed = readlink($link);
        if (!is_link($link) || $actual === false || $observed === false
            || !$this->same($actual, $expected) || realpath($observed) === false
            || !$this->same((string) realpath($observed), $expected)) {
            throw new RuntimeException('proof_symlink_verification_failed');
        }
    }

    private function same(string $a, string $b): bool
    {
        $a = str_replace('\\', '/', $a); $b = str_replace('\\', '/', $b);
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    private function contained(string $path, string $root, bool $allowRoot = false): bool
    {
        $path = str_replace('\\', '/', $path); $root = rtrim(str_replace('\\', '/', $root), '/');
        if ($allowRoot && $this->same($path, $root)) { return true; }
        return $this->same(substr($path, 0, strlen($root) + 1), $root . '/');
    }
}
