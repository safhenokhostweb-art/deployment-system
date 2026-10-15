<?php
declare(strict_types=1);
$root = 'C:/Users/Farshad-IT/AppData/Local/Temp/SentinelProof/2f606c65-a4c9-48af-8646-de4ae6f12d49';
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $entry) {
    $path = $entry->getPathname();
    $name = substr(str_replace('\\', '/', $path), strlen($root) + 1);
    if ($entry->isLink()) { $files[$name] = ['link' => readlink($path)]; }
    elseif ($entry->isFile()) { $files[$name] = ['size' => $entry->getSize(), 'sha256' => hash_file('sha256', $path)]; }
}
ksort($files);
echo json_encode($files, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
