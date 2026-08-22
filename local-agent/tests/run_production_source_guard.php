<?php

declare(strict_types=1);

require __DIR__ . '/../lib/SafetyChecker.php';
require __DIR__ . '/../lib/GitCommandException.php';
require __DIR__ . '/../lib/GitManager.php';

use LocalAgent\SafetyChecker;

$failures = [];

$check = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$check('/var/www project paths are forbidden as source', SafetyChecker::productionSourcePathForbidden('/var/www/customer-site'));
$check('/srv/www project paths are forbidden as source', SafetyChecker::productionSourcePathForbidden('/srv/www/customer-site'));
$check('/var/www root itself is forbidden as source', SafetyChecker::productionSourcePathForbidden('/var/www'));
$check('/home user public paths are forbidden as source', SafetyChecker::productionSourcePathForbidden('/home/customer/public'));
$check('/home user public_html paths are forbidden as source', SafetyChecker::productionSourcePathForbidden('/home/customer/public_html/app'));
$check('release directories are forbidden as source', SafetyChecker::productionSourcePathForbidden('/opt/apps/customer-site/releases/20260822'));
$check('Windows IIS production paths are forbidden as source', SafetyChecker::productionSourcePathForbidden('C:\\inetpub\\wwwroot\\customer-site'));
$check('developer/build checkout path is allowed', !SafetyChecker::productionSourcePathForbidden('C:\\laragon\\www\\customer-site'));
$check('Linux build checkout outside production roots is allowed', !SafetyChecker::productionSourcePathForbidden('/home/build/customer-site'));

$pushGuardedBeforeGit = false;
try {
    (new SafetyChecker())->assertSafeToPublish(new \LocalAgent\GitManager(), '/var/www/customer-site', 'main');
} catch (RuntimeException $e) {
    $pushGuardedBeforeGit = str_contains($e->getMessage(), 'production-like');
}
$check('production directory is rejected before any git push preflight can execute', $pushGuardedBeforeGit);

if ($failures !== []) {
    fwrite(STDERR, "Production source guard test failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Production source guard test passed.\n";
