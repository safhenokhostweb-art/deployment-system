<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\LocalProof\SentinelExactCommitExporter;
use App\Services\Sentinel\LocalProof\SentinelLocalArtifactAdapter;
use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelProofDiagnostics;
use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use ZipArchive;

/** Temporary Git/ZIP component fixtures only; no build, ledger, runtime or real artifact. */
final class SentinelExactCommitExporterTest extends TestCase
{
    private string $repo;
    private string $commit;
    private SentinelLocalProofFilesystem $files;
    private SentinelExactCommitExporter $exporter;
    private array $blobs;

    protected function setUp(): void
    {
        $this->repo = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())) . '/SentinelBlobUnit/' . Str::uuid();
        mkdir($this->repo, 0700, true);
        $this->git(['init', '-q', '--initial-branch=fixture']);
        $this->blobs = ['.gitattributes' => "*.bat text eol=crlf\n*.txt text eol=lf\n",
            'deploy.bat' => str_pad("@echo off\necho exact blob\n", 349, ' '),
            'lf.txt' => "one\ntwo\n", 'crlf.txt' => "one\r\ntwo\r\n",
            'run.sh' => "#!/bin/sh\nprintf exact\n", 'binary.dat' => "\0\xff\r\n\n"];
        foreach (SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES as $name) {
            $this->blobs[$name] = "{\"fixture\":true}\n";
        }
        foreach ($this->blobs as $name => $bytes) { $this->indexBlob($name, $bytes, $name === 'run.sh' ? '100755' : '100644'); }
        $this->commit = $this->commitIndex();
        $this->configure($this->commit);
    }

    private function configure(string $commit): void
    {
        $config = SentinelProofRunConfig::create($this->repo, $commit, 'fixture', 18101, 18102, 'fixture-project');
        $this->files = new SentinelLocalProofFilesystem($config);
        $this->files->initialize(true);
        $this->exporter = new SentinelExactCommitExporter($this->files);
    }

    private function git(array $args, ?string $input = null): string
    {
        $process = new Process(array_merge(['git', '-C', $this->repo, '-c', 'user.name=Blob Fixture',
            '-c', 'user.email=blob-fixture@example.invalid', '-c', 'commit.gpgsign=false'], $args));
        $process->setTimeout(30);
        if ($input !== null) { $process->setInput($input); }
        $process->mustRun();
        return $process->getOutput();
    }

    private function indexBlob(string $name, string $bytes, string $mode = '100644'): void
    {
        $oid = trim($this->git(['hash-object', '-w', '--stdin'], $bytes));
        $this->git(['update-index', '--add', '--cacheinfo', $mode, $oid, $name]);
    }

    private function commitIndex(): string
    {
        $tree = trim($this->git(['write-tree']));
        $commit = trim($this->git(['commit-tree', $tree], "component fixture\n"));
        $this->git(['update-ref', 'refs/heads/fixture', $commit]);
        return $commit;
    }

    private function rejects(callable $operation, string $message): void
    {
        try { $operation(); self::fail('Expected rejection: ' . $message); }
        catch (RuntimeException $error) { self::assertSame($message, $error->getMessage()); }
    }

    private function diagnosticFor(callable $operation): array
    {
        try {
            $operation();
            self::fail('Expected operation to fail.');
        } catch (RuntimeException $error) {
            return SentinelProofDiagnostics::describe(
                new RuntimeException('proof_execution_failed', 0, $error),
                'EXACT_COMMIT_EXPORT',
            );
        }
    }

    private function processMock(string $output, string $stderr, ?int $exitCode, bool $successful, ?\Throwable $runError = null): Process
    {
        $process = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setTimeout', 'setInput', 'run', 'isSuccessful', 'getOutput', 'getErrorOutput', 'getExitCode'])
            ->getMock();
        $process->expects(self::once())->method('setTimeout')->with(30)->willReturnSelf();
        $process->method('setInput')->willReturnSelf();
        if ($runError !== null) {
            $process->expects(self::once())->method('run')->willThrowException($runError);
        } else {
            $process->expects(self::once())->method('run')->willReturn(0);
        }
        $process->method('isSuccessful')->willReturn($successful);
        $process->method('getOutput')->willReturn($output);
        $process->method('getErrorOutput')->willReturn($stderr);
        $process->method('getExitCode')->willReturn($exitCode);
        return $process;
    }

    private function exporterWithCatFileProcess(Process $process): SentinelExactCommitExporter
    {
        return new SentinelExactCommitExporter($this->files, function (array $command, array $arguments) use ($process): Process {
            return ($arguments[0] ?? null) === 'cat-file' ? $process : new Process($command);
        });
    }

    public function test_raw_blob_zip_preserves_all_bytes_modes_and_final_checksum(): void
    {
        $result = $this->exporter->export('fixtures/source.zip', true);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($result['path'], ZipArchive::RDONLY));
        try {
            self::assertSame(count($this->blobs), $zip->numFiles);
            foreach ($this->blobs as $name => $bytes) {
                self::assertSame($bytes, $zip->getFromName($name));
                self::assertSame(strlen($bytes), $zip->statName($name)['size']);
                self::assertSame(hash('sha256', $bytes), $result['blob_inventory'][$name]['sha256']);
                $opsys = $attributes = 0;
                self::assertTrue($zip->getExternalAttributesName($name, $opsys, $attributes));
                self::assertSame(ZipArchive::OPSYS_UNIX, $opsys);
                self::assertSame($name === 'run.sh' ? 0100755 : 0100644, $attributes >> 16 & 0177777);
            }
            self::assertSame(349, $zip->statName('deploy.bat')['size']);
        } finally { $zip->close(); }
        self::assertSame(hash_file('sha256', $result['path']), $result['archive_sha256']);
        self::assertSame(filesize($result['path']), $result['archive_size']);
        self::assertNotSame($result['blob_inventory']['deploy.bat']['sha256'], $result['archive_sha256']);
        $this->rejects(fn () => $this->exporter->export('fixtures/source.zip', true), 'proof_export_already_exists');
    }

    public function test_committed_blobs_use_a_bounded_cat_file_batch_and_verify_each_object_id(): void
    {
        $catFileCommands = [];
        $exporter = new SentinelExactCommitExporter($this->files,
            function (array $command, array $arguments) use (&$catFileCommands): Process {
                if (($arguments[0] ?? null) === 'cat-file') { $catFileCommands[] = $arguments; }
                return new Process($command);
            });

        $entries = $exporter->readEntries();

        self::assertSame([['cat-file', '--batch']], $catFileCommands);
        $expectedNames = array_keys($this->blobs);
        $actualNames = array_keys($entries);
        sort($expectedNames);
        sort($actualNames);
        self::assertSame($expectedNames, $actualNames);
        foreach ($this->blobs as $name => $bytes) {
            self::assertSame($bytes, $entries[$name]['bytes']);
            self::assertSame(hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes), $entries[$name]['oid']);
            self::assertSame(hash('sha256', $bytes), $entries[$name]['sha256']);
        }
    }

    public function test_actual_target_deploy_bat_and_five_fixtures_are_byte_identical_in_temp_zip(): void
    {
        $repo = dirname(__DIR__, 6) . '/saas-platform';
        $sha = '13c845b0f0cd519e12722ac8f0e8a0b587078b68';
        $branch = 'fix/canvas-default-homepage';
        // This reads the live sibling checkout, which the exporter rightly
        // refuses (proof_source_changed) unless HEAD is exactly the pinned
        // commit on the pinned branch. Work committed in saas-platform is an
        // environment change, not a regression in this repository.
        $head = new Process(['git', '-C', $repo, 'rev-parse', 'HEAD']);
        $current = new Process(['git', '-C', $repo, 'branch', '--show-current']);
        if (!is_dir($repo) || $head->run() !== 0 || $current->run() !== 0
            || trim($head->getOutput()) !== $sha || trim($current->getOutput()) !== $branch) {
            self::markTestSkipped("Sibling saas-platform checkout is not at the pinned proof source {$sha} on {$branch}.");
        }
        $config = SentinelProofRunConfig::create($repo, $sha, $branch, 18101, 18102, 'fixture-project');
        // Dedicated component fixture root, unrelated to the preserved failed run.
        $files = new SentinelLocalProofFilesystem($config);
        $files->initialize(true);
        try {
            $exporter = new SentinelExactCommitExporter($files);
            $paths = array_merge(['deploy.bat'], SentinelLocalArtifactAdapter::STORAGE_SOURCE_FIXTURES);
            $result = $exporter->export('fixtures/target-subset.zip', true, $paths);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($result['path'], ZipArchive::RDONLY));
            try {
                self::assertSame(6, $zip->numFiles);
                foreach ($paths as $name) {
                    $process = new Process(['git', '-C', $repo, 'cat-file', 'blob', $config->commitSha . ':' . $name]);
                    $process->mustRun();
                    self::assertSame($process->getOutput(), $zip->getFromName($name));
                }
                self::assertSame(349, $zip->statName('deploy.bat')['size']);
                self::assertFalse($zip->locateName('docs/CANVAS_DEFAULT_HOMEPAGE_AUDIT_REPORT.md'));
            } finally { $zip->close(); }
            self::assertSame(hash_file('sha256', $result['path']), $result['archive_sha256']);
        } finally { $files->cleanup(true); }
    }

    public function test_live_edits_and_untracked_files_never_enter_export(): void
    {
        file_put_contents($this->repo . '/deploy.bat', "uncommitted replacement\r\n");
        file_put_contents($this->repo . '/untracked.txt', 'untracked component fixture');
        $entries = $this->exporter->readEntries();
        self::assertSame($this->blobs['deploy.bat'], $entries['deploy.bat']['bytes']);
        self::assertArrayNotHasKey('untracked.txt', $entries);
        $this->rejects(fn () => $this->exporter->readEntries(['untracked.txt']), 'proof_source_path_missing');
        $result = $this->exporter->export('fixtures/dirty-source.zip', true);
        self::assertArrayNotHasKey('untracked.txt', $result['blob_inventory']);
    }

    public function test_commit_mismatch_and_missing_blob_fail_closed(): void
    {
        $this->indexBlob('changed.txt', 'second commit');
        $this->commitIndex();
        $this->rejects(fn () => $this->exporter->readEntries(), 'proof_source_changed');
        $tree = trim($this->git(['mktree', '--missing'], '100644 blob ' . str_repeat('1', 40) . "\tmissing.txt\n"));
        $missing = trim($this->git(['commit-tree', $tree], "missing object fixture\n"));
        $this->git(['update-ref', 'refs/heads/fixture', $missing]);
        $config = SentinelProofRunConfig::create($this->repo, $missing, 'fixture', 18101, 18102, 'fixture-project');
        $exporter = new SentinelExactCommitExporter(new SentinelLocalProofFilesystem($config));
        $report = $this->diagnosticFor(fn () => $exporter->readEntries());
        self::assertSame('proof_source_blob_unavailable', $report['sanitized_exception_message']);
        self::assertSame('cat_file_blob', $report['git_operation']);
        self::assertSame('GIT_OBJECT_MISSING', $report['stderr_classification']);
        self::assertSame('missing.txt', $report['repository_relative_path']);
        self::assertSame(str_repeat('1', 40), $report['blob_oid']);
        self::assertSame(0, $report['git_exit_code']);
        self::assertFalse($report['git_timeout']);
        self::assertNotEmpty($report['exception_chain']);
    }

    public function test_git_process_failure_preserves_safe_metadata_and_redacts_stderr(): void
    {
        $stderr = 'fatal: access_token=do-not-log C:\\private\\repository unavailable';
        $process = $this->processMock('', $stderr, 128, false);
        $report = $this->diagnosticFor(fn () => $this->exporterWithCatFileProcess($process)->readEntries(['lf.txt']));
        self::assertSame('proof_source_blob_unavailable', $report['sanitized_exception_message']);
        self::assertSame('GIT_PROCESS_FAILED', $report['stderr_classification']);
        self::assertSame('cat_file_blob', $report['git_operation']);
        self::assertSame('lf.txt', $report['repository_relative_path']);
        self::assertSame($this->exporter->readEntries(['lf.txt'])['lf.txt']['oid'], $report['blob_oid']);
        self::assertSame(128, $report['git_exit_code']);
        self::assertTrue($report['stderr_present']);
        $serialized = json_encode($report, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('do-not-log', $serialized);
        self::assertStringNotContainsString('C:\\private', $serialized);
        self::assertStringNotContainsString($stderr, $serialized);
    }

    public function test_sensitive_repository_paths_are_redacted_before_recording(): void
    {
        $method = new \ReflectionMethod($this->exporter, 'safeRepositoryPath');
        foreach (['secrets/prod.json', 'src/credentials.json', '.env.production', 'C:/private/file'] as $path) {
            self::assertSame('[REDACTED]', $method->invoke($this->exporter, $path));
        }
        self::assertSame('src/Example.php', $method->invoke($this->exporter, 'src/Example.php'));
    }

    public function test_explicit_missing_object_stderr_is_classified_as_missing(): void
    {
        $stderr = "fatal: path 'lf.txt' does not exist in '{$this->commit}'";
        $process = $this->processMock('', $stderr, 128, false);
        $report = $this->diagnosticFor(fn () => $this->exporterWithCatFileProcess($process)->readEntries(['lf.txt']));
        self::assertSame('GIT_OBJECT_MISSING', $report['stderr_classification']);
        self::assertSame('lf.txt', $report['repository_relative_path']);
        self::assertSame(128, $report['git_exit_code']);
        self::assertStringNotContainsString($stderr, json_encode($report));
    }

    public function test_git_timeout_is_distinguished_without_exposing_exception_text(): void
    {
        $timedOutProcess = $this->getMockBuilder(Process::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTimeout', 'getExitCode', 'getErrorOutput', 'getCommandLine'])
            ->getMock();
        $timedOutProcess->method('getTimeout')->willReturn(30.0);
        $timedOutProcess->method('getExitCode')->willReturn(null);
        $timedOutProcess->method('getErrorOutput')->willReturn('token=hidden');
        $timedOutProcess->method('getCommandLine')->willReturn('git cat-file');
        $timeout = new ProcessTimedOutException($timedOutProcess, ProcessTimedOutException::TYPE_GENERAL);
        $process = $this->processMock('', 'token=hidden', null, false, $timeout);
        $report = $this->diagnosticFor(fn () => $this->exporterWithCatFileProcess($process)->readEntries(['lf.txt']));
        self::assertSame('GIT_TIMEOUT', $report['stderr_classification']);
        self::assertTrue($report['git_timeout']);
        self::assertNull($report['git_exit_code']);
        self::assertStringNotContainsString('token=hidden', json_encode($report));
    }

    public function test_repeated_receive_reuses_validation_only_for_unchanged_same_artifact(): void
    {
        $adapter = new SentinelLocalArtifactAdapter($this->files);
        $path = $this->files->write('artifacts/already-validated.zip', 'sealed fixture bytes');
        $checksum = hash('sha256', 'sealed fixture bytes');
        $observed = ['path' => $path, 'checksum' => $checksum, 'size' => strlen('sealed fixture bytes'),
            'manifest' => ['validated' => true]];
        (new \ReflectionProperty(SentinelLocalArtifactAdapter::class, 'observed'))->setValue($adapter, $observed);

        self::assertSame($observed, $adapter->receive($path, $checksum));

        file_put_contents($path, 'changed fixture bytes');
        $this->rejects(fn () => $adapter->receive($path, $checksum), 'proof_checksum_mismatch');
    }

    public function test_invalid_tree_output_is_classified_without_changing_existing_failure_code(): void
    {
        $process = $this->processMock("invalid tree record\0", '', 0, true);
        $exporter = new SentinelExactCommitExporter($this->files, function (array $command, array $arguments) use ($process): Process {
            return ($arguments[0] ?? null) === 'ls-tree' ? $process : new Process($command);
        });
        $report = $this->diagnosticFor(fn () => $exporter->readEntries());
        self::assertSame('proof_source_mode_rejected', $report['failure_code']);
        self::assertSame('GIT_OUTPUT_INVALID', $report['stderr_classification']);
        self::assertSame('ls_tree', $report['git_operation']);
    }

    public function test_blob_hash_mismatch_is_classified_and_fail_closed(): void
    {
        $oid = $this->exporter->readEntries(['lf.txt'])['lf.txt']['oid'];
        $altered = 'altered blob bytes';
        $process = $this->processMock(
            $oid . ' blob ' . strlen($altered) . "\n" . $altered . "\n",
            '',
            0,
            true,
        );
        $report = $this->diagnosticFor(fn () => $this->exporterWithCatFileProcess($process)->readEntries(['lf.txt']));
        self::assertSame('proof_source_blob_mismatch', $report['failure_code']);
        self::assertSame('GIT_BLOB_HASH_MISMATCH', $report['stderr_classification']);
        self::assertSame('lf.txt', $report['repository_relative_path']);
        self::assertSame($this->exporter->readEntries(['lf.txt'])['lf.txt']['oid'], $report['blob_oid']);
    }

    public function test_unknown_storage_and_noncanonical_requested_paths_are_rejected(): void
    {
        foreach (['../escape', '/absolute/path', 'C:\\absolute\\path', 'C:relative',
            "file\0name", 'storage/json-import-samples/unknown.json'] as $name) {
            $this->rejects(fn () => $this->exporter->readEntries([$name]), 'proof_artifact_entry_rejected');
        }
        $this->indexBlob('storage/session.data', 'runtime fixture');
        $commit = $this->commitIndex();
        $config = SentinelProofRunConfig::create($this->repo, $commit, 'fixture', 18101, 18102, 'fixture-project');
        $exporter = new SentinelExactCommitExporter(new SentinelLocalProofFilesystem($config));
        $this->rejects(fn () => $exporter->readEntries(), 'proof_artifact_entry_rejected');
    }

    public function test_environment_objects_are_filtered_before_blob_reads_and_export_is_opt_in(): void
    {
        // A missing environment object proves filtering happens before cat-file; no secret bytes exist or are read.
        $oid = trim($this->git(['hash-object', '-w', '--stdin'], "public fixture\n"));
        $tree = trim($this->git(['mktree', '--missing'], '100644 blob ' . str_repeat('2', 40)
            . "\t.env\n100644 blob " . $oid . "\tlf.txt\n"));
        $commit = trim($this->git(['commit-tree', $tree], "filtered object fixture\n"));
        $this->git(['update-ref', 'refs/heads/fixture', $commit]);
        $config = SentinelProofRunConfig::create($this->repo, $commit, 'fixture', 18101, 18102, 'fixture-project');
        $exporter = new SentinelExactCommitExporter(new SentinelLocalProofFilesystem($config));
        self::assertSame(['lf.txt'], array_keys($exporter->readEntries()));
        $this->rejects(fn () => $exporter->export('fixtures/no.zip'), 'proof_execution_not_authorized');
        self::assertFalse(file_exists($config->path('fixtures/no.zip')));
    }

    public function test_credentials_directories_preserve_source_blobs_but_filter_sensitive_file_basenames(): void
    {
        $source = [
            'src/Credentials/ApiKeyCredential.php' => '<?php class ApiKeyCredential {}',
            'src/Credentials/Credentials.php' => '<?php class Credentials {}',
        ];
        $sensitive = ['credentials', 'credentials.json', 'credentials-prod',
            'src/Credentials/credentials.json', 'src/Credentials/credentials-prod',
            'src/Credentials/.env', 'src/Credentials/private.pem', 'src/Credentials/private.key',
            'src/Credentials/private.p12', 'src/Credentials/private.pfx'];
        foreach ($source as $name => $bytes) { $this->indexBlob($name, $bytes); }
        foreach ($sensitive as $name) { $this->indexBlob($name, 'inert sensitive-name fixture'); }
        $commit = $this->commitIndex();
        $config = SentinelProofRunConfig::create($this->repo, $commit, 'fixture', 18101, 18102, 'fixture-project');
        $exporter = new SentinelExactCommitExporter(new SentinelLocalProofFilesystem($config));
        $entries = $exporter->readEntries();
        self::assertCount(count($this->blobs) + count($source), $entries);
        $adapter = new SentinelLocalArtifactAdapter(new SentinelLocalProofFilesystem($config));
        foreach ($source as $name => $bytes) {
            self::assertSame($bytes, $entries[$name]['bytes']);
            self::assertSame(hash('sha256', $bytes), $entries[$name]['sha256']);
            self::assertFalse(SentinelLocalArtifactAdapter::isSensitiveEntry($name));
            $adapter->assertEntry('vendor/package/' . $name, ['vendor/autoload.php']);
        }
        foreach ($sensitive as $name) {
            self::assertArrayNotHasKey($name, $entries);
            self::assertTrue(SentinelLocalArtifactAdapter::isSensitiveEntry($name));
            $this->rejects(fn () => $adapter->assertEntry($name, []), 'proof_artifact_entry_rejected');
        }
        $this->rejects(fn () => $exporter->readEntries(['credentials.json']), 'proof_artifact_entry_rejected');
        $this->rejects(fn () => $exporter->readEntries(['credentials-prod']), 'proof_artifact_entry_rejected');
    }

    public function test_post_finalize_verification_rejects_extra_and_mutated_zip_entries(): void
    {
        $entries = $this->exporter->readEntries();
        $result = $this->exporter->export('fixtures/tampered.zip', true);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($result['path']));
        self::assertTrue($zip->addFromString('untracked.txt', 'not in commit'));
        self::assertTrue($zip->close());
        $verify = new \ReflectionMethod($this->exporter, 'verifyZip');
        $this->rejects(fn () => $verify->invoke($this->exporter, $result['path'], $entries), 'proof_source_inventory_mismatch');
        self::assertTrue($zip->open($result['path']));
        self::assertTrue($zip->deleteName('untracked.txt'));
        self::assertTrue($zip->addFromString('deploy.bat', str_repeat('x', 349)));
        self::assertTrue($zip->close());
        $this->rejects(fn () => $verify->invoke($this->exporter, $result['path'], $entries), 'proof_source_zip_mismatch');
    }

    public function test_safe_symlink_metadata_is_preserved_without_materializing_a_link(): void
    {
        $this->indexBlob('dir/link', '../lf.txt', '120000');
        $commit = $this->commitIndex();
        $config = SentinelProofRunConfig::create($this->repo, $commit, 'fixture', 18101, 18102, 'fixture-project');
        $exporter = new SentinelExactCommitExporter(new SentinelLocalProofFilesystem($config));
        $entries = $exporter->readEntries();
        self::assertSame('120000', $entries['dir/link']['mode']);
        self::assertSame('../lf.txt', $entries['dir/link']['bytes']);
        self::assertFalse(file_exists($this->repo . '/dir/link'));
        $files = new SentinelLocalProofFilesystem($config);
        $files->initialize(true);
        try {
            $exporter = new SentinelExactCommitExporter($files);
            $result = $exporter->export('fixtures/link-metadata.zip', true);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($result['path'], ZipArchive::RDONLY));
            try {
                self::assertSame('../lf.txt', $zip->getFromName('dir/link'));
                $opsys = $attributes = 0;
                self::assertTrue($zip->getExternalAttributesName('dir/link', $opsys, $attributes));
                self::assertSame(0120000, $attributes >> 16 & 0177777);
            } finally { $zip->close(); }
            $adapter = new SentinelLocalArtifactAdapter($files);
            $this->rejects(fn () => $adapter->sourceInventory(), 'proof_source_symlink_or_submodule');
        } finally { $files->cleanup(true); }
    }

    public function test_symlink_escape_targets_are_rejected_without_resolving_them(): void
    {
        $check = new \ReflectionMethod($this->exporter, 'assertLinkTarget');
        foreach (['../../outside', '/absolute', 'C:/absolute', 'C:\\absolute', "bad\0target"] as $target) {
            $this->rejects(fn () => $check->invoke($this->exporter, 'dir/link', $target), 'proof_source_symlink_escape');
        }
        $this->indexBlob('link', '../outside', '120000');
        $commit = $this->commitIndex();
        $config = SentinelProofRunConfig::create($this->repo, $commit, 'fixture', 18101, 18102, 'fixture-project');
        $exporter = new SentinelExactCommitExporter(new SentinelLocalProofFilesystem($config));
        $this->rejects(fn () => $exporter->readEntries(), 'proof_source_symlink_escape');
    }

    protected function tearDown(): void
    {
        if (isset($this->files)) { $this->files->cleanup(true); }
        // Only this generated component Git fixture; no native links are ever created.
        if (isset($this->repo) && is_dir($this->repo)) {
            $prefix = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())) . '/SentinelBlobUnit/';
            if (!str_starts_with(str_replace('\\', '/', (string) realpath($this->repo)), $prefix)
                || is_link($this->repo)) { throw new RuntimeException('fixture_cleanup_escape'); }
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->repo,
                \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                if ($item->isLink()) { throw new RuntimeException('fixture_cleanup_symlink'); }
                if ($item->isDir()) { rmdir($item->getPathname()); }
                else {
                    // Git marks loose objects read-only on Windows.
                    chmod($item->getPathname(), 0600);
                    unlink($item->getPathname());
                }
            }
            rmdir($this->repo);
        }
    }
}
