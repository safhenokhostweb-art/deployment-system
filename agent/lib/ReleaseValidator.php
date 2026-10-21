<?php

declare(strict_types=1);

namespace DeploymentAgent;

/**
 * Fail-closed Laravel/Composer release-completeness validator — the
 * deployment-system reusable equivalent of customer-site's own
 * scripts/validate-release.php.
 *
 * Root cause it closes (recurring production incidents): a release could
 * become ACTIVE while missing gitignored runtime files such as
 * vendor/autoload.php or public/build/manifest.json, because the
 * artifact's checksum proves only "these bytes are exactly what was
 * packaged", never "the packaged tree is a complete, runnable Laravel
 * release". This validator inspects the FINAL STAGED TREE immediately
 * before activation and derives its requirements from the tree itself
 * (fail-closed), so an incomplete release can never reach the atomic swap
 * regardless of which Local Agent version built the artifact or what the
 * Hub metadata happened to say.
 *
 * Contract:
 *   - pass=false means the release MUST NOT be activated.
 *   - read-only: never writes to the release tree.
 *   - deterministic: checks run in a fixed order and every check result
 *     is reported, so two runs over the same tree produce identical
 *     output.
 *
 * Only meaningful for Composer/Laravel releases. A non-Composer artifact
 * (no composer.json/artisan/bootstrap chain) still gets the shared
 * release contract checks: explicit commit SHA, required_paths
 * fail-closed rule, Vite build manifest rule and junction/symlink escape
 * scanning of vendor/ and public/build/.
 */
final class ReleaseValidator
{
    /** Manifest file embedded by the Local Agent's ArtifactBuilder. */
    public const IN_ARTIFACT_MANIFEST_PATH = '.deployment/release-manifest.json';

    public function __construct(
        private readonly string $phpBinary = 'php',
    ) {
    }

    /**
     * @param array<int, string> $descriptorRequiredPaths the artifact descriptor's
     *                          required_paths list (may legitimately be empty for a
     *                          non-Composer project; MUST NOT be empty for a Composer release)
     * @return array{
     *   pass: bool,
     *   failures: array<int, string>,
     *   checks: array<string, array{status: string, message: string}>,
     *   composer_lock_sha256: string,
     *   commit_sha: string,
     *   build_manifest_present: bool,
     *   vite_required: bool
     * }
     */
    public function validate(string $releaseRoot, string $commitSha, array $descriptorRequiredPaths, bool $dependencyLockRequired = false, string $applicationRoot = '.'): array
    {
        $root = rtrim($releaseRoot, '/\\');
        if ($applicationRoot !== '.' && $applicationRoot !== '') {
            $relative = str_replace('\\', '/', trim($applicationRoot, '/\\'));
            if ($relative === '' || str_starts_with($relative, '/') || preg_match('#^[A-Za-z]:#', $relative) === 1 || str_contains($relative, '..')) {
                throw new \InvalidArgumentException('Invalid application_root');
            }
            $root .= '/' . $relative;
        }
        $checks = [];
        $failures = [];

        $check = function (string $key, string $label, callable $run) use (&$checks, &$failures): void {
            try {
                $result = $run();
                if ($result === true) {
                    $checks[$key] = ['status' => 'pass', 'message' => $label];
                } else {
                    $checks[$key] = ['status' => 'fail', 'message' => $result];
                    $failures[] = $key;
                }
            } catch (\Throwable $e) {
                $checks[$key] = ['status' => 'fail', 'message' => $label . ' (exception: ' . $e->getMessage() . ')'];
                $failures[] = $key;
            }
        };

        $hasComposer = is_file($root . '/composer.json');
        $hasArtisan = is_file($root . '/artisan');
        $hasBootstrap = is_file($root . '/bootstrap/app.php');
        $isLaravelish = $hasComposer || $hasArtisan || $hasBootstrap;
        $hasVite = self::hasViteConfig($root);
        $hasBuildDir = is_dir($root . '/public/build');

        // 1. Explicit commit SHA — the release contract is commit-pinned.
        // Enforced for every Composer/Laravel release, and also whenever the
        // Hub supplied a commit value at all (production artifacts always
        // carry one, so in practice this is universal). A non-Composer
        // direct-caller/test artifact with no commit value is the only case
        // that does not fail here.
        if ($isLaravelish || $commitSha !== '') {
            $check('commit_sha', 'explicit commit SHA is present and valid', function () use ($commitSha): bool|string {
                if (!is_string($commitSha) || !preg_match('/^[0-9a-f]{40}$/', $commitSha)) {
                    return 'commit SHA is missing or not a 40-character hex SHA';
                }

                return true;
            });
        }

        // 2. required_paths must NEVER fail open for a Composer release.
        if ($isLaravelish) {
            $check('required_paths_contract', 'artifact required_paths are non-empty for a Composer release', function () use ($descriptorRequiredPaths): bool|string {
                if (!is_array($descriptorRequiredPaths) || $descriptorRequiredPaths === []) {
                    return 'the artifact descriptor carried no required_paths — refusing to fail open for a Composer release';
                }

                return true;
            });
        }


        // 3. Composer/Laravel runtime completeness, derived from the tree itself.
        if ($isLaravelish) {
            $check('composer_json', 'composer.json present', function () use ($root, $hasComposer): bool|string {
                return $hasComposer ? true : 'composer.json is MISSING from a release that carries Laravel bootstrap files';
            });
            $check('composer_lock', 'composer.lock present for a reproducible release', fn (): bool|string => is_file($root . '/composer.lock') ? true : 'composer.lock is MISSING from the release');
            $check('vendor_autoload', 'vendor/autoload.php present', fn (): bool|string => is_file($root . '/vendor/autoload.php') ? true : 'vendor/autoload.php is MISSING — composer install never produced a runnable vendor tree');
            $check('vendor_composer', 'vendor/composer/ metadata present', fn (): bool|string => is_dir($root . '/vendor/composer') ? true : 'vendor/composer/ is MISSING — composer install is incomplete');
            $check('artisan_chain', 'Laravel bootstrap chain present', function () use ($root): bool|string {
                foreach (['artisan', 'bootstrap/app.php', 'public/index.php'] as $required) {
                    if (!is_file($root . '/' . $required)) {
                        return $required . ' is MISSING from the release';
                    }
                }

                return true;
            });
            $check('required_packages', 'every non-virtual composer require package is present in vendor/', function () use ($root): bool|string {
                $composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
                if (!is_array($composer)) {
                    return 'composer.json is not valid JSON';
                }
                $missing = [];
                foreach (array_keys($composer['require'] ?? []) as $package) {
                    if ($package === 'php' || $package === 'composer-plugin-api' || $package === 'composer-runtime-api' || str_starts_with($package, 'ext-')) {
                        continue;
                    }
                    if (!is_dir($root . '/vendor/' . $package)) {
                        $missing[] = $package;
                    }
                }

                return $missing === [] ? true : 'required package(s) missing from vendor/: ' . implode(', ', $missing);
            });
            $check('autoload_boots', 'vendor/autoload.php boots in an isolated subprocess', function () use ($root): bool|string {
                if (!is_file($root . '/vendor/autoload.php')) {
                    return 'vendor/autoload.php missing (see vendor_autoload)';
                }
                $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; echo "AUTOLOAD_OK";';
                $result = self::runProcess([$this->phpBinary, '-r', $code], null, 30);
                if ($result['exit'] !== 0 || !str_contains($result['stdout'], 'AUTOLOAD_OK')) {
                    return 'composer autoload failed to boot: ' . trim($result['stdout'] . ' ' . $result['stderr']);
                }

                return true;
            });
            if ($hasArtisan) {
                $check('artisan_syntax', 'artisan passes php -l', function () use ($root): bool|string {
                    $result = self::runProcess([$this->phpBinary, '-l', $root . '/artisan'], null, 30);

                    return $result['exit'] === 0 ? true : 'artisan syntax check failed: ' . trim($result['stdout'] . ' ' . $result['stderr']);
                });
            }
        }


        // 4. Junction/symlink escape scanning — a release must be self-contained.
        if (is_dir($root . '/vendor')) {
            $check('vendor_no_escape', 'no vendor entry resolves outside the release root', fn (): bool|string => self::firstEscapeOutside($root, $root . '/vendor'));
        }
        if ($hasBuildDir) {
            $check('build_no_escape', 'no public/build entry resolves outside the release root', fn (): bool|string => self::firstEscapeOutside($root, $root . '/public/build'));
        }

        // 5. Frontend build output — fail-closed when Vite is declared.
        if ($hasVite) {
            $check('build_manifest', 'public/build/manifest.json present for a Vite project', fn (): bool|string => is_file($root . '/public/build/manifest.json') ? true : 'public/build/manifest.json is MISSING while the release declares Vite');
        }
        if ($hasBuildDir) {
            $check('build_manifest_dir', 'public/build/ contains its manifest.json', fn (): bool|string => is_file($root . '/public/build/manifest.json') ? true : 'public/build/ exists but manifest.json is MISSING');
        }

        // 6. In-artifact release manifest (provenance) — verified when present.
        $manifestPath = $root . '/' . self::IN_ARTIFACT_MANIFEST_PATH;
        if ($dependencyLockRequired && !is_file($manifestPath)) {
            $check('dependency_lock', 'dependency lock manifest is required by project policy', fn (): string => 'dependency lock is required by project policy but the artifact has no release manifest');
        }
        if (is_file($manifestPath)) {
            $check('release_manifest', 'in-artifact release manifest is consistent', function () use ($manifestPath, $commitSha): bool|string {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                if (!is_array($manifest)) {
                    return 'the in-artifact release manifest is not valid JSON';
                }
                if (($manifest['status'] ?? null) !== 'pass') {
                    return 'the in-artifact release manifest did not pass build-time validation';
                }
                if (isset($manifest['commit_sha']) && $manifest['commit_sha'] !== $commitSha) {
                    return 'the in-artifact release manifest commit_sha does not match the job commit';
                }

                return true;
            });
            $check('dependency_lock', 'dependency lock entries match artifact bytes', function () use ($manifestPath, $root, $dependencyLockRequired): bool|string {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                $locks = is_array($manifest) && is_array($manifest['dependency_locks'] ?? null) ? $manifest['dependency_locks'] : [];
                if ($dependencyLockRequired && (($manifest['version'] ?? null) !== 2 || ($manifest['dependency_lock_required'] ?? false) !== true || $locks === [])) {
                    return 'project policy requires a version 2 non-empty dependency lock';
                }
                foreach ($locks as $lock) {
                    foreach (['project_id', 'repository_mapping', 'branch', 'commit_sha', 'package_name', 'source_path', 'destination_path', 'artifact_path', 'tree_hash', 'checksum_sha256'] as $field) {
                        if (!is_string($lock[$field] ?? null) || $lock[$field] === '') return "dependency lock field {$field} is missing";
                    }
                    if (preg_match('/^[0-9a-f]{40}$/', $lock['commit_sha']) !== 1 || preg_match('/^[0-9a-f]{40}$/', $lock['tree_hash']) !== 1 || preg_match('/^[0-9a-f]{64}$/', $lock['checksum_sha256']) !== 1) return 'dependency lock contains an invalid SHA or checksum';
                    if ($lock['artifact_path'] !== 'vendor/' . $lock['package_name'] || str_contains($lock['artifact_path'], '..')) return 'dependency lock artifact_path is invalid';
                    $packageRoot = $root . '/' . $lock['artifact_path'];
                    if (!self::ordinaryDirectoryTree($packageRoot)) return 'dependency package is missing, linked, or contains a reparse point';
                    if (!hash_equals($lock['checksum_sha256'], self::contentChecksum($packageRoot))) return 'dependency package checksum does not match the manifest lock';
                }
                return true;
            });
        }

        return [
            'pass' => $failures === [],
            'failures' => $failures,
            'checks' => $checks,
            'composer_lock_sha256' => $hasComposer && is_file($root . '/composer.lock') ? hash_file('sha256', $root . '/composer.lock') : '',
            'commit_sha' => $commitSha,
            'build_manifest_present' => is_file($root . '/public/build/manifest.json'),
            'vite_required' => $hasVite,
        ];
    }

    /** @return array<int, string> the file names this validator treats as a Vite declaration. */
    public static function viteConfigNames(): array
    {
        return ['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.cjs', 'vite.config.mts', 'vite.config.cts'];
    }

    private static function hasViteConfig(string $root): bool
    {
        foreach (self::viteConfigNames() as $name) {
            if (is_file($root . '/' . $name)) {
                return true;
            }
        }

        return false;
    }


    /**
     * @return true when every real path under $scanRoot stays under $releaseRoot,
     *               or the first offending relative path as an error string
     */
    private static function firstEscapeOutside(string $releaseRoot, string $scanRoot): bool|string
    {
        $realReleaseRoot = realpath($releaseRoot);
        if ($realReleaseRoot === false) {
            return 'cannot resolve the release root';
        }
        $realScanRoot = realpath($scanRoot);
        if ($realScanRoot === false) {
            return 'cannot resolve ' . $scanRoot;
        }
        // realpath() returns OS-native separators (backslashes on Windows);
        // normalize so the prefix containment test is portable.
        $realReleaseRoot = str_replace('\\', '/', $realReleaseRoot);
        $realScanRoot = str_replace('\\', '/', $realScanRoot);
        $rootPrefix = $realReleaseRoot . '/';

        $errors = [];
        $walk = function (string $dir, int $depth) use (&$walk, &$errors, $rootPrefix, $scanRoot): void {
            if ($depth > 14 || count($errors) >= 20) {
                return;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                $real = realpath($path);
                if ($real === false) {
                    continue; // broken link — collectFilesSafely would have skipped it at build time
                }
                $real = str_replace('\\', '/', $real);
                if (!str_starts_with($real, $rootPrefix)) {
                    $errors[] = ltrim(substr($path, strlen($scanRoot)), '/\\');

                    continue;
                }
                if (is_dir($real) && !is_link($path)) {
                    $walk($path, $depth + 1);
                }
            }
        };

        if (is_dir($realScanRoot)) {
            $walk($realScanRoot, 0);
        }

        return $errors === [] ? true : 'entry(ies) resolve outside the release root: ' . implode(', ', array_slice($errors, 0, 5));
    }

    private static function ordinaryDirectoryTree(string $root): bool
    {
        if (!is_dir($root) || is_link($root)) return false;
        $parent = realpath(dirname($root));
        $real = realpath($root);
        if ($parent === false || $real === false || $real !== rtrim($parent, '\\/') . DIRECTORY_SEPARATOR . basename($root)) return false;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) return false;
            $entryParent = realpath(dirname($entry->getPathname()));
            if ($entryParent === false || realpath($entry->getPathname()) !== rtrim($entryParent, '\\/') . DIRECTORY_SEPARATOR . $entry->getFilename()) return false;
        }
        return true;
    }

    private static function contentChecksum(string $root): string
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) if ($entry->isFile()) $files[str_replace('\\', '/', substr($entry->getPathname(), strlen(rtrim($root, '\\/')) + 1))] = $entry->getPathname();
        ksort($files, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($files as $relative => $path) {
            $bytes = file_get_contents($path);
            if ($bytes === false) return '';
            hash_update($hash, $relative . "\0" . strlen($bytes) . "\0" . $bytes);
        }
        return hash_final($hash);
    }

    /**
     * @param array<int, string> $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private static function runProcess(array $command, ?string $cwd, int $timeoutSeconds): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process);
                @proc_close($process);

                return ['exit' => 124, 'stdout' => $stdout, 'stderr' => 'timeout'];
            }
            usleep(100000);
        }

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
