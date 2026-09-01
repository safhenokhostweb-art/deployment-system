<?php

declare(strict_types=1);

foreach (['GitCommandException','GitManager','LocalBuildFailedException','SafeFilesystem','Logger','BuildWorkspace','SafetyChecker','DependencyRepositoryResolver','PinnedDependencyMaterializer','WorkspaceSourcePreparer','ArtifactBuilder'] as $class) require __DIR__ . '/../lib/' . $class . '.php';

use LocalAgent\ArtifactBuilder;
use LocalAgent\BuildWorkspace;
use LocalAgent\DependencyRepositoryResolver;
use LocalAgent\GitManager;
use LocalAgent\LocalBuildFailedException;
use LocalAgent\PinnedDependencyMaterializer;
use LocalAgent\SafeFilesystem;
use LocalAgent\WorkspaceSourcePreparer;

if (($argv[1] ?? '') === '--worker') {
    [$base,$job,$remote,$sha,$output]=array_slice($argv,2,5);
    $workspace=BuildWorkspace::create($base,'consumer',$job);
    $allow=[['project_id'=>'dependency','repository_mapping'=>'dependency','branch'=>'main','package_name'=>'acme/widget','source_path'=>'packages/acme/widget','destination_path'=>'../dependency/packages/acme/widget']];
    $locks=(new PinnedDependencyMaterializer(new GitManager()))->materialize($workspace,[[...$allow[0],'commit_sha'=>$sha]],$allow,['dependency'=>['repository_url'=>$remote]]);
    $content=(string)file_get_contents($workspace->resolvePathDependencyTarget('../dependency/packages/acme/widget').'/src/Version.php');
    file_put_contents($output,json_encode(['content'=>$content,'checksum'=>$locks[0]['checksum_sha256'],'workspace'=>$workspace->root()]));
    $workspace->cleanup(); exit(0);
}

$root = str_replace('\\', '/', sys_get_temp_dir()) . '/pinned-deps-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$git = new GitManager(); $failures = []; $passed = 0;
$check = static function (string $label, bool $ok) use (&$failures, &$passed): void { $ok ? $passed++ : $failures[] = $label; };
$run = static function (array $command, string $cwd) use ($git): string { $r=$git->run($command,$cwd,60); if($r['exitCode']!==0) throw new RuntimeException($r['output']); return trim($r['output']); };
$fails = static function (callable $fn) use ($check): bool { try { $fn(); return false; } catch (LocalBuildFailedException) { return true; } };
$failureMessage = static function (callable $fn): string { try { $fn(); return ''; } catch (LocalBuildFailedException $e) { return $e->getMessage(); } };

try {
    $depSeed = $root . '/dep-seed'; $depRemote = $root . '/dep.git'; mkdir($depSeed,0700,true);
    $run(['git','init','--initial-branch=main'],$depSeed); $run(['git','config','user.email','test@example.test'],$depSeed); $run(['git','config','user.name','Test'],$depSeed);
    mkdir($depSeed . '/packages/acme/widget/src',0755,true);
    file_put_contents($depSeed . '/packages/acme/widget/composer.json', json_encode(['name'=>'acme/widget','autoload'=>['psr-4'=>['Acme\\Widget\\'=>'src/']]]));
    file_put_contents($depSeed . '/packages/acme/widget/src/Version.php', "<?php return 'A';\n");
    $run(['git','add','.'],$depSeed); $run(['git','commit','-m','A'],$depSeed); $shaA=$git->lastCommitHash($depSeed);
    file_put_contents($depSeed . '/packages/acme/widget/src/Version.php', "<?php return 'B';\n");
    $run(['git','add','.'],$depSeed); $run(['git','commit','-m','B'],$depSeed); $shaB=$git->lastCommitHash($depSeed);
    file_put_contents($depSeed . '/link-spec', '../../outside');
    $linkBlob=$run(['git','hash-object','-w','link-spec'],$depSeed);
    $run(['git','update-index','--add','--cacheinfo','120000,'.$linkBlob.',packages/acme/widget/escape-link'],$depSeed);
    $run(['git','commit','-m','symlink fixture'],$depSeed); $shaSymlink=$git->lastCommitHash($depSeed);
    $run(['git','clone','--bare',$depSeed,$depRemote],$root);

    $consumerSeed=$root.'/consumer-seed'; $consumerRemote=$root.'/consumer.git'; $consumerSource=$root.'/consumer-source'; mkdir($consumerSeed,0700,true);
    $run(['git','init','--initial-branch=main'],$consumerSeed); $run(['git','config','user.email','test@example.test'],$consumerSeed); $run(['git','config','user.name','Test'],$consumerSeed);
    file_put_contents($consumerSeed.'/composer.json',json_encode(['repositories'=>[['type'=>'path','url'=>'../dependency/packages/acme/widget','options'=>['symlink'=>true]]],'require'=>['acme/widget'=>'@dev']]));
    $run(['git','add','.'],$consumerSeed); $run(['git','commit','-m','consumer'],$consumerSeed); $consumerSha=$git->lastCommitHash($consumerSeed);
    $run(['git','clone','--bare',$consumerSeed,$consumerRemote],$root); $run(['git','clone','--branch','main',$consumerRemote,$consumerSource],$root);

    $allow=[['project_id'=>'dependency','repository_mapping'=>'dependency','branch'=>'main','package_name'=>'acme/widget','source_path'=>'packages/acme/widget','destination_path'=>'../dependency/packages/acme/widget']];
    $request=fn(string $sha): array => ['version'=>2,'required'=>true,'dependencies'=>[[...$allow[0], 'commit_sha'=>$sha]]];
    $mappings=['dependency'=>['repository_url'=>$depRemote]];
    $preparer=new WorkspaceSourcePreparer($git);

    $dirtySibling=$root.'/dirty-sibling'; mkdir($dirtySibling); file_put_contents($dirtySibling.'/Version.php','DIRTY');
    $wa=BuildWorkspace::create($root.'/builds','consumer','sha-a');
    $pa=$preparer->prepareRemoteCommit($wa,$consumerSource,$consumerRemote,'origin','main',$consumerSha,null,$request($shaA),$allow,$mappings);
    $targetA=$wa->resolvePathDependencyTarget('../dependency/packages/acme/widget');
    $check('correct reachable SHA materializes', str_contains((string)file_get_contents($targetA.'/src/Version.php'), "'A'"));
    $check('dirty sibling has no influence', !str_contains((string)file_get_contents($targetA.'/src/Version.php'),'DIRTY'));
    $check('lock records exact SHA, tree hash, checksum', ($pa['dependencyLocks'][0]['commit_sha']??'')===$shaA && preg_match('/^[0-9a-f]{40}$/',$pa['dependencyLocks'][0]['tree_hash']??'')===1 && preg_match('/^[0-9a-f]{64}$/',$pa['dependencyLocks'][0]['checksum_sha256']??'')===1);

    $wb=BuildWorkspace::create($root.'/builds','consumer','sha-b');
    $pb=$preparer->prepareRemoteCommit($wb,$consumerSource,$consumerRemote,'origin','main',$consumerSha,null,$request($shaB),$allow,$mappings);
    $check('different SHA builds use isolated workspaces', $wa->root()!==$wb->root() && str_contains((string)file_get_contents($wb->resolvePathDependencyTarget('../dependency/packages/acme/widget').'/src/Version.php'),"'B'") && $pa['dependencyLocks'][0]['checksum_sha256']!==$pb['dependencyLocks'][0]['checksum_sha256']);
    $parallelBase=$root.'/parallel'; $outA=$root.'/parallel-a.json'; $outB=$root.'/parallel-b.json';
    $descriptors=[0=>['pipe','r'],1=>['file',$root.'/parallel-a.out','w'],2=>['file',$root.'/parallel-a.err','w']];
    $procA=proc_open([PHP_BINARY,__FILE__,'--worker',$parallelBase,'job-a',$depRemote,$shaA,$outA],$descriptors,$pipesA); if(is_resource($procA)) fclose($pipesA[0]);
    $descriptorsB=[0=>['pipe','r'],1=>['file',$root.'/parallel-b.out','w'],2=>['file',$root.'/parallel-b.err','w']];
    $procB=proc_open([PHP_BINARY,__FILE__,'--worker',$parallelBase,'job-b',$depRemote,$shaB,$outB],$descriptorsB,$pipesB); if(is_resource($procB)) fclose($pipesB[0]);
    if(is_resource($procA)) proc_close($procA); if(is_resource($procB)) proc_close($procB);
    $parallelA=json_decode((string)@file_get_contents($outA),true); $parallelB=json_decode((string)@file_get_contents($outB),true);
    $check('two simultaneous SHA builds use independent dependency checkouts',is_array($parallelA)&&is_array($parallelB)&&$parallelA['workspace']!==$parallelB['workspace']&&str_contains($parallelA['content'],"'A'")&&str_contains($parallelB['content'],"'B'")&&$parallelA['checksum']!==$parallelB['checksum']);
    $linked=BuildWorkspace::create($root.'/builds','consumer','linked-tree');
    $check('Git symlink entries are rejected before extraction',$fails(fn()=>$preparer->prepareRemoteCommit($linked,$consumerSource,$consumerRemote,'origin','main',$consumerSha,null,$request($shaSymlink),$allow,$mappings))); $linked->cleanup();

    $bad=BuildWorkspace::create($root.'/builds','consumer','bad-sha');
    $invalid=$request('abc'); $check('invalid SHA is rejected',$fails(fn()=>$preparer->prepareRemoteCommit($bad,$consumerSource,$consumerRemote,'origin','main',$consumerSha,null,$invalid,$allow,$mappings))); $bad->cleanup();
    $missing=BuildWorkspace::create($root.'/builds','consumer','missing-sha');
    $check('SHA absent from allowed repository is rejected',$fails(fn()=>$preparer->prepareRemoteCommit($missing,$consumerSource,$consumerRemote,'origin','main',$consumerSha,null,$request(str_repeat('f',40)),$allow,$mappings))); $missing->cleanup();
    $unauthorized=BuildWorkspace::create($root.'/builds','consumer','unauthorized'); $badRequest=$request($shaA); $badRequest['dependencies'][0]['source_path']='packages/secret';
    $check('non-allowlisted package path is rejected',$fails(fn()=>$preparer->prepareRemoteCommit($unauthorized,$consumerSource,$consumerRemote,'origin','main',$consumerSha,null,$badRequest,$allow,$mappings))); $unauthorized->cleanup();
    $wrongMapping=BuildWorkspace::create($root.'/builds','consumer','wrong-mapping'); $mappingRequest=$request($shaA); $mappingRequest['dependencies'][0]['repository_mapping']='attacker-repository';
    $check('non-allowlisted repository mapping is rejected',$fails(fn()=>$preparer->prepareRemoteCommit($wrongMapping,$consumerSource,$consumerRemote,'origin','main',$consumerSha,null,$mappingRequest,$allow,['attacker-repository'=>['repository_url'=>$depRemote]]))); $wrongMapping->cleanup();
    $traversal=BuildWorkspace::create($root.'/builds','consumer','traversal'); $traversalRequest=$request($shaA); $traversalRequest['dependencies'][0]['source_path']='../escape'; $traversalAllow=[$traversalRequest['dependencies'][0]]; unset($traversalAllow[0]['commit_sha']);
    $check('path traversal is rejected',$fails(fn()=>$preparer->prepareRemoteCommit($traversal,$consumerSource,$consumerRemote,'origin','main',$consumerSha,null,$traversalRequest,$traversalAllow,$mappings))); $traversal->cleanup();

    // Regression fixture for customer-site's declarative dependency policy.
    // The persistent source checkout ends at the six-repository commit, while
    // the first build deliberately requests the earlier five-repository SHA:
    // path discovery must therefore read the exact requested commit, never
    // the persistent workspace's newer composer.json.
    $customerPackages = [
        ['saas-platform/modules-tickets', 'packages/saas-platform/modules/tickets'],
        ['saas-platform/modules-forms', 'packages/saas-platform/modules/forms'],
        ['saas-platform/modules-crm', 'packages/saas-platform/modules/crm'],
        ['saas-platform/modules-seo-advanced', 'packages/saas-platform/modules/seo-advanced'],
        ['saas-platform/core-utils', 'packages/saas-platform/core-utils'],
        ['saas-platform/modules-theme-runtime', 'packages/saas-platform/modules/theme-runtime'],
    ];
    $saasSeed=$root.'/saas-seed'; $saasRemote=$root.'/saas.git'; mkdir($saasSeed,0700,true);
    $run(['git','init','--initial-branch=main'],$saasSeed); $run(['git','config','user.email','test@example.test'],$saasSeed); $run(['git','config','user.name','Test'],$saasSeed);
    foreach ($customerPackages as [$packageName, $sourcePath]) {
        mkdir($saasSeed.'/'.$sourcePath.'/src',0755,true);
        file_put_contents($saasSeed.'/'.$sourcePath.'/composer.json',json_encode(['name'=>$packageName,'autoload'=>['psr-4'=>['Fixture\\Package\\'=>'src/']]]));
        file_put_contents($saasSeed.'/'.$sourcePath.'/src/Package.php',"<?php return '".$packageName."';\n");
    }
    $run(['git','add','.'],$saasSeed); $run(['git','commit','-m','six dependency packages'],$saasSeed); $saasSha=$git->lastCommitHash($saasSeed);
    $run(['git','clone','--bare',$saasSeed,$saasRemote],$root);
    $saasPersistent=$root.'/saas-persistent'; $run(['git','clone','--branch','main',$saasRemote,$saasPersistent],$root);
    file_put_contents($saasSeed.'/README.md',"newer branch tip\n");
    $run(['git','add','README.md'],$saasSeed); $run(['git','commit','-m','newer main tip'],$saasSeed); $saasTipSha=$git->lastCommitHash($saasSeed);
    $run(['git','remote','add','origin',$saasRemote],$saasSeed); $run(['git','push','origin','main'],$saasSeed);
    $check('dependency fixture has main but no master', $git->run(['git','show-ref','--verify','refs/heads/main'],$saasRemote,30)['exitCode']===0 && $git->run(['git','show-ref','--verify','refs/heads/master'],$saasRemote,30)['exitCode']!==0);
    $check('pinned dependency SHA is an ancestor rather than branch tip', $saasSha!==$saasTipSha && $git->run(['git','merge-base','--is-ancestor',$saasSha,$saasTipSha],$saasSeed,30)['exitCode']===0);
    $check('stale clean persistent dependency workspace already contains the pinned SHA', $git->lastCommitHash($saasPersistent)===$saasSha && $git->changedFiles($saasPersistent)===[] && $git->run(['git','cat-file','-e',$saasSha.'^{commit}'],$saasPersistent,30)['exitCode']===0);
    $sshRepository='ssh://git@ssh.github.com:443/example/saas-platform.git';
    $httpsRepository='https://github.com/example/saas-platform.git';
    $run(['git','remote','set-url','origin',$sshRepository],$saasPersistent);
    $resolvedTransport=(new DependencyRepositoryResolver($git))->resolve(['repository_url'=>$httpsRepository,'source_workspace_path'=>$saasPersistent,'remote_name'=>'origin','branch'=>'main'],'saas-platform','main');
    $check('same-repository HTTPS mapping resolves to the trusted workspace SSH transport without network access',$resolvedTransport===$sshRepository);
    $check('wrong dependency repository identity fails closed',$fails(fn()=>(new DependencyRepositoryResolver($git))->resolve(['repository_url'=>'https://github.com/example/other.git','source_workspace_path'=>$saasPersistent,'remote_name'=>'origin','branch'=>'main'],'saas-platform','main')));
    $run(['git','remote','set-url','origin',$saasRemote],$saasPersistent);

    $customerSeed=$root.'/customer-seed'; $customerRemote=$root.'/customer.git'; $customerSource=$root.'/customer-source'; mkdir($customerSeed,0700,true);
    $run(['git','init','--initial-branch=main'],$customerSeed); $run(['git','config','user.email','test@example.test'],$customerSeed); $run(['git','config','user.name','Test'],$customerSeed);
    $writeCustomerComposer = static function (string $path, array $packages): void {
        $repositories=[]; $requires=[];
        foreach ($packages as [$packageName,$sourcePath]) {
            $repositories[]=['type'=>'path','url'=>'../saas-platform/'.$sourcePath,'options'=>['symlink'=>true]];
            $requires[$packageName]='@dev';
        }
        file_put_contents($path,json_encode(['repositories'=>$repositories,'require'=>$requires],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    };
    $writeCustomerComposer($customerSeed.'/composer.json',array_slice($customerPackages,0,5));
    $run(['git','add','composer.json'],$customerSeed); $run(['git','commit','-m','five path repositories'],$customerSeed); $customerFiveSha=$git->lastCommitHash($customerSeed);
    $writeCustomerComposer($customerSeed.'/composer.json',$customerPackages);
    $run(['git','add','composer.json'],$customerSeed); $run(['git','commit','-m','add theme runtime path repository'],$customerSeed); $customerSixSha=$git->lastCommitHash($customerSeed);
    $run(['git','clone','--bare',$customerSeed,$customerRemote],$root); $run(['git','clone','--branch','main',$customerRemote,$customerSource],$root);

    $policyFor = static function (array $packages, string $commitSha): array {
        $allow=[]; $dependencies=[];
        foreach ($packages as [$packageName,$sourcePath]) {
            $base=['project_id'=>'saas-platform','repository_mapping'=>'saas-platform','branch'=>'main','package_name'=>$packageName,'source_path'=>$sourcePath,'destination_path'=>'../saas-platform/'.$sourcePath];
            $allow[]=$base; $dependencies[]=[...$base,'commit_sha'=>$commitSha];
        }
        return [$allow,['version'=>2,'required'=>true,'dependencies'=>$dependencies]];
    };
    [$allowFive,$contractFive]=$policyFor(array_slice($customerPackages,0,5),$saasSha);
    [$allowSix,$contractSix]=$policyFor($customerPackages,$saasSha);
    $saasMappings=['saas-platform'=>['repository_url'=>$saasRemote,'source_workspace_path'=>$saasPersistent,'remote_name'=>'origin','branch'=>'main']];

    $five=BuildWorkspace::create($root.'/builds','customer-site','five-existing-paths');
    $preparedFive=$preparer->prepareRemoteCommit($five,$customerSource,$customerRemote,'origin','main',$customerFiveSha,null,$contractFive,$allowFive,$saasMappings);
    $check('five existing customer-site path repositories remain valid',count($preparedFive['dependencyLocks'])===5);
    $check('exact consumer commit beats newer persistent workspace composer state',count($preparedFive['dependencyLocks'])===5 && $git->lastCommitHash($customerSource)===$customerSixSha);

    $stale=BuildWorkspace::create($root.'/builds','customer-site','stale-five-pin-contract');
    $check('stale five-pin mapping cannot satisfy six-repository commit',$fails(fn()=>$preparer->prepareRemoteCommit($stale,$customerSource,$customerRemote,'origin','main',$customerSixSha,null,$contractFive,$allowFive,$saasMappings))); $stale->cleanup();

    $six=BuildWorkspace::create($root.'/builds','customer-site','six-with-theme-runtime');
    $preparedSix=$preparer->prepareRemoteCommit($six,$customerSource,$customerRemote,'origin','main',$customerSixSha,null,$contractSix,$allowSix,$saasMappings);
    $themeLocks=array_values(array_filter($preparedSix['dependencyLocks'],static fn(array $lock): bool=>($lock['destination_path']??'')==='../saas-platform/packages/saas-platform/modules/theme-runtime'));
    $check('sixth theme-runtime repository is valid and pinned exactly once',count($preparedSix['dependencyLocks'])===6 && count($themeLocks)===1 && ($themeLocks[0]['commit_sha']??'')===$saasSha);
    $check('six pins sharing one repository branch and SHA use one isolated checkout and fetch',count(glob($six->root().'/dependency-checkouts/*',GLOB_ONLYDIR)?:[])===1);

    $sixRetry=BuildWorkspace::create($root.'/builds','customer-site','six-with-theme-runtime-retry');
    $preparedRetry=$preparer->prepareRemoteCommit($sixRetry,$customerSource,$customerRemote,'origin','main',$customerSixSha,null,$contractSix,$allowSix,$saasMappings);
    $check('repeated six-pin materialization is idempotent in a fresh retry workspace',count($preparedRetry['dependencyLocks'])===6 && array_column($preparedRetry['dependencyLocks'],'checksum_sha256')===array_column($preparedSix['dependencyLocks'],'checksum_sha256'));

    $diagnostic=BuildWorkspace::create($root.'/builds','customer-site','failed-fetch-diagnostic');
    $diagnosticDependency=$contractSix['dependencies'][0]; $diagnosticDependency['branch']='missing-branch';
    $diagnosticAllow=$allowSix[0]; $diagnosticAllow['branch']='missing-branch';
    $message=$failureMessage(fn()=>(new PinnedDependencyMaterializer($git))->materialize($diagnostic,[$diagnosticDependency],[$diagnosticAllow],['saas-platform'=>['repository_url'=>$saasRemote]]));
    $check('fetch failure reports safe mapping branch exit timeout and stderr category diagnostics',str_contains($message,'mapping=saas-platform')&&str_contains($message,'branch=missing-branch')&&str_contains($message,'exit_code=')&&str_contains($message,'timed_out=false')&&str_contains($message,'stderr_category=remote_ref_missing')&&!str_contains($message,$saasRemote));

    $missingPin=BuildWorkspace::create($root.'/builds','customer-site','missing-theme-runtime-pin'); $missingContract=$contractSix; array_pop($missingContract['dependencies']);
    $check('missing dependency pin fails closed',$fails(fn()=>$preparer->prepareRemoteCommit($missingPin,$customerSource,$customerRemote,'origin','main',$customerSixSha,null,$missingContract,$allowSix,$saasMappings))); $missingPin->cleanup();
    $duplicatePin=BuildWorkspace::create($root.'/builds','customer-site','duplicate-theme-runtime-pin'); $duplicateContract=$contractSix; $duplicateContract['dependencies'][]=$duplicateContract['dependencies'][5];
    $check('duplicate dependency pin fails closed',$fails(fn()=>$preparer->prepareRemoteCommit($duplicatePin,$customerSource,$customerRemote,'origin','main',$customerSixSha,null,$duplicateContract,$allowSix,$saasMappings))); $duplicatePin->cleanup();
    $unresolved=BuildWorkspace::create($root.'/builds','customer-site','unresolved-theme-runtime'); $unresolvedContract=$contractSix; $unresolvedAllow=$allowSix;
    $unresolvedContract['dependencies'][5]['source_path']='packages/saas-platform/modules/missing-runtime'; $unresolvedAllow[5]['source_path']='packages/saas-platform/modules/missing-runtime';
    $check('allowlisted but unresolved dependency source fails closed',$fails(fn()=>$preparer->prepareRemoteCommit($unresolved,$customerSource,$customerRemote,'origin','main',$customerSixSha,null,$unresolvedContract,$unresolvedAllow,$saasMappings))); $unresolved->cleanup();

    $five->cleanup(); $six->cleanup(); $sixRetry->cleanup(); $diagnostic->cleanup();

    // Simulate Composer's final vendor package with a normal copy, then seal and re-verify it.
    mkdir($wa->projectDir().'/vendor/acme',0755,true); SafeFilesystem::copyRecursiveSafely((string)realpath($targetA),(string)realpath($targetA),$wa->projectDir().'/vendor/acme/widget');
    file_put_contents($wa->projectDir().'/vendor/autoload.php',"<?php\n");
    $archive=(new ArtifactBuilder())->build($wa->projectDir(),$wa->root(),1700000000,['ranComposer'=>true,'ranNpmBuild'=>false],null,['project_key'=>'consumer','commit_sha'=>$consumerSha,'branch'=>'main','agent_version'=>'test','dependency_locks'=>$pa['dependencyLocks'],'dependency_pinning_required'=>true]);
    $extract=$root.'/extract'; mkdir($extract); SafeFilesystem::extractZipSafely($archive['path'],$extract);
    $manifest=json_decode((string)file_get_contents($extract.'/.deployment/release-manifest.json'),true);
    $check('manifest v2 records dependency lock',($manifest['version']??null)===2 && ($manifest['dependency_lock_required']??false)===true && ($manifest['dependency_locks'][0]['commit_sha']??'')===$shaA);
    $check('v2 protocol marker is sealed and advertised',is_file($extract.'/.deployment/dependency-lock-v2') && in_array('.deployment/dependency-lock-v2',$archive['requiredPaths'],true));
    $artifactPackage=$extract.'/vendor/acme/widget';
    $check('sealed dependency is an ordinary non-reparse directory',is_dir($artifactPackage) && !SafeFilesystem::isLinkLikeEntry($artifactPackage));
    $check('sealed dependency has byte-diff zero',PinnedDependencyMaterializer::contentChecksum($artifactPackage)===$pa['dependencyLocks'][0]['checksum_sha256']);
    @unlink($archive['path']); $wa->cleanup(); $wb->cleanup();
} finally { if(is_dir($root)) SafeFilesystem::recursiveDelete($root); }

$total=$passed+count($failures); echo "Pinned dependencies: {$passed}/{$total} passed\n";
if($failures!==[]){ foreach($failures as $failure) echo "  FAIL: {$failure}\n"; exit(1); }
echo "Pinned dependencies: ALL PASS\n";
