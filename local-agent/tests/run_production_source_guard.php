<?php

declare(strict_types=1);

require __DIR__ . '/../lib/SafetyChecker.php';

use LocalAgent\SafetyChecker;

$failures = [];

$check = static function (string $label, bool $condition) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$check('/var/www project paths are forbidden as source', SafetyChecker::productionSourcePathForbidden('/var/www/customer-site'));
$check('/srv/www project paths are forbidden as source', SafetyChecker::productionSourcePathForbidden('/srv/www/customer-site'));
$check('release directories are forbidden as source', SafetyChecker::productionSourcePathForbidden('/opt/apps/customer-site/releases/20260822'));
$check('Windows IIS production paths are forbidden as source', SafetyChecker::productionSourcePathForbidden('C:\\inetpub\\wwwroot\\customer-site'));
$check('developer/build checkout path is allowed', !SafetyChecker::productionSourcePathForbidden('C:\\laragon\\www\\customer-site'));
$check('Linux build checkout outside production roots is allowed', !SafetyChecker::productionSourcePathForbidden('/home/build/customer-site'));

if ($failures !== []) {
    fwrite(STDERR, "Production source guard test failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Production source guard test passed.\n";
