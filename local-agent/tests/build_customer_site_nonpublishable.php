<?php

declare(strict_types=1);

foreach (['GitCommandException','GitManager','LocalBuildFailedException','SafeFilesystem','Logger','BuildWorkspace','SafetyChecker','PinnedDependencyMaterializer','WorkspaceSourcePreparer','ArtifactBuilder','ApplicationRoot','LocalBuildRunner'] as $class) require __DIR__ . '/../lib/' . $class . '.php';

use LocalAgent\ArtifactBuilder;
use LocalAgent\BuildWorkspace;
use LocalAgent\GitManager;
use LocalAgent\LocalBuildRunner;
use LocalAgent\SafeFilesystem;
use LocalAgent\WorkspaceSourcePreparer;

const CONSUMER_SHA = '1bf0d8913948f928e90c452d8896a71922ac0398';
const DEPENDENCY_SHA = 'd9997e892564b2b8f16e7f67129b1e629f592122';

$repoRoot = dirname(__DIR__, 2);
$customerRepo = 'C:/laragon/www/customer-site';
$dependencyRepo = 'C:/laragon/www/saas-platform';
$php = 'C:/laragon/bin/php/php-8.4.16-nts-Win32-vs17-x64/php.exe';
$composer = ['C:/laragon/bin/php/php-8.4.16-nts-Win32-vs17-x64/php.exe', 'C:/laragon/bin/composer/composer.phar'];
$npm = ['C:/Program Files/nodejs/npm.cmd'];
$output = $repoRoot . '/.deployment/nonpublishable/customer-site-' . substr(CONSUMER_SHA, 0, 12) . '-deps-' . substr(DEPENDENCY_SHA, 0, 12);
$temp = str_replace('\\', '/', sys_get_temp_dir()) . '/customer-nonpublishable-' . bin2hex(random_bytes(6));
mkdir($temp, 0700, true);
$git = new GitManager(['pull' => 300]);
$run = static function (array $command, string $cwd, int $timeout = 300) use ($git): string {
    $result = $git->run($command, $cwd, $timeout);
    if ($result['exitCode'] !== 0) throw new RuntimeException('Local fixture Git operation failed.');
    return trim($result['output']);
};
$makeRemote = static function (string $source, string $sha, string $bare) use ($run, $temp): void {
    $run(['git','init','--bare',$bare],$temp);
    $run(['git','fetch',$source,$sha],$bare);
    $run(['git','update-ref','refs/heads/main',$sha],$bare);
    $run(['git','symbolic-ref','HEAD','refs/heads/main'],$bare);
};

try {
    $consumerBare=$temp.'/customer.git'; $dependencyBare=$temp.'/saas.git';
    $makeRemote($customerRepo,CONSUMER_SHA,$consumerBare); $makeRemote($dependencyRepo,DEPENDENCY_SHA,$dependencyBare);
    $consumerSource=$temp.'/customer-source'; $run(['git','clone','--branch','main',$consumerBare,$consumerSource],$temp);

    $packages = [
        ['saas-platform/modules-tickets','packages/saas-platform/modules/tickets'],
        ['saas-platform/modules-forms','packages/saas-platform/modules/forms'],
        ['saas-platform/modules-crm','packages/saas-platform/modules/crm'],
        ['saas-platform/modules-seo-advanced','packages/saas-platform/modules/seo-advanced'],
        ['saas-platform/core-utils','packages/saas-platform/core-utils'],
    ];
    $dependencies=[]; $allow=[];
    foreach($packages as [$name,$source]) {
        $base=['project_id'=>'saas-platform','repository_mapping'=>'saas-platform','branch'=>'main','package_name'=>$name,'source_path'=>$source,'destination_path'=>'../saas-platform/'.$source];
        $allow[]=$base; $dependencies[]=[...$base,'commit_sha'=>DEPENDENCY_SHA];
    }
    $contract=['version'=>2,'required'=>true,'dependencies'=>$dependencies];
    $workspace=BuildWorkspace::create($temp.'/builds','customer-site','NONPUBLISHABLE');
    $prepared=(new WorkspaceSourcePreparer($git))->prepareRemoteCommit($workspace,$consumerSource,$consumerBare,'origin','main',CONSUMER_SHA,null,$contract,$allow,['saas-platform'=>['repository_url'=>$dependencyBare]]);
    $build=(new LocalBuildRunner($git,$composer,$npm,$php,1800,1800,1800))->run($workspace->projectDir());
    $artifact=(new ArtifactBuilder())->build($workspace->projectDir(),$workspace->root(),$prepared['commitTimestamp'],$build,null,['project_key'=>'customer-site','commit_sha'=>CONSUMER_SHA,'branch'=>'main','agent_version'=>'1.5.0-NONPUBLISHABLE','dependency_locks'=>$prepared['dependencyLocks'],'dependency_pinning_required'=>true]);

    if (is_dir($output)) SafeFilesystem::recursiveDelete($output);
    mkdir($output.'/extracted',0700,true);
    $zipPath=$output.'/artifact-NONPUBLISHABLE.zip';
    if (!copy($artifact['path'],$zipPath)) throw new RuntimeException('Could not retain NONPUBLISHABLE artifact.');
    SafeFilesystem::extractZipSafely($zipPath,$output.'/extracted');
    file_put_contents($output.'/NONPUBLISHABLE','LOCAL TEST ONLY - DO NOT UPLOAD OR ACTIVATE'.PHP_EOL);
    $report=['publishable'=>false,'consumer_commit'=>CONSUMER_SHA,'dependency_commit'=>DEPENDENCY_SHA,'artifact_sha256'=>hash_file('sha256',$zipPath),'artifact_size'=>filesize($zipPath),'dependency_locks'=>$prepared['dependencyLocks']];
    file_put_contents($output.'/build-report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

    $validation=$git->run([$php,$customerRepo.'/scripts/validate-release.php',$output.'/extracted','--json','--commit-sha='.CONSUMER_SHA],$repoRoot,300);
    file_put_contents($output.'/validate-release.json',$validation['output']);
    if ($validation['exitCode']!==0) throw new RuntimeException('validate-release.php rejected the NONPUBLISHABLE artifact.');
    require_once $repoRoot.'/agent/lib/ReleaseValidator.php';
    $manifest=json_decode((string)file_get_contents($output.'/extracted/.deployment/release-manifest.json'),true);
    $serverValidation=(new \DeploymentAgent\ReleaseValidator($php))->validate($output.'/extracted',CONSUMER_SHA,$manifest['required_paths']??[],true);
    if (!$serverValidation['pass']) throw new RuntimeException('Server Agent dependency-lock validator rejected the NONPUBLISHABLE artifact.');
    if (!is_file($output.'/extracted/.deployment/dependency-lock-v2')) throw new RuntimeException('Artifact v2 protocol marker is missing.');
    file_put_contents($output.'/server-validation.json',json_encode($serverValidation,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    echo json_encode($report+['output_directory'=>$output,'validate_release'=>'pass','server_dependency_validator'=>'pass','v2_marker'=>'pass'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
    @unlink($artifact['path']); $workspace->cleanup();
} finally {
    if (is_dir($temp)) SafeFilesystem::recursiveDelete($temp);
}
