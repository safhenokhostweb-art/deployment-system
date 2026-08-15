<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Root-cause fix (production has no access to repo.packagist.org,
 * api.github.com, or the npm registry): builds `vendor/` and any static
 * asset bundle on THIS machine (which has normal internet access),
 * BEFORE the artifact is archived — production never runs `composer
 * install`/`npm ci` at all anymore (see agent/agent.php's
 * skip_when_artifact handling and ArtifactManager).
 *
 * Every command here is a fixed argv array with no user-controlled
 * interpolation — there is nothing for command injection to attach to,
 * unlike a shell-string pipeline step. Auto-detects what a project
 * actually needs (composer.json / package.json presence) rather than
 * assuming every project uses both, so a pure-static or PHP-only project
 * is unaffected.
 *
 * Root-cause fix (v1.4.1): a Windows Scheduled Task does not necessarily
 * inherit the same PATH an interactive shell has (confirmed: Laragon adds
 * its own php/composer directories to PATH only for shells it launches
 * itself) — `composer`/`npm` resolved purely from PATH can be entirely
 * absent from the Task's own environment even though they work fine from
 * a terminal. $composerCommand/$npmCommand let an operator configure the
 * exact, absolute invocation once (in config.php) instead of depending on
 * PATH at all; the bare-name fallback (['composer'] / ['npm']) preserves
 * the previous, PATH-dependent behavior for anyone who never hits this.
 *
 * Root-cause fix (v1.4.2): fixing the PATH problem above surfaced a
 * second, distinct one — the configured PHP binary itself can be missing
 * a PHP extension a dependency requires (confirmed: phpoffice/phpspreadsheet
 * requiring ext-gd, not enabled in this machine's php.ini used by the
 * Local Agent). `composer install` DOES eventually catch this too, but
 * only after downloading every package, and reports it as an
 * undifferentiated `local_composer_failed` with no indication of *which*
 * php.ini is even in play. assertPlatformRequirementsSatisfied() runs
 * `composer check-platform-reqs` (Composer's own, authoritative
 * ext-* / php-version check against composer.lock) BEFORE `composer
 * install` ever starts, so a missing extension fails fast, with its own
 * `missing_php_extension` code and the exact php.ini path attached —
 * never silently bypassed (`--ignore-platform-req` is never passed
 * anywhere in this class).
 *
 * Root-cause fix (v1.4.2, isolated build workspace): `composer install`
 * now always passes `--no-scripts`. This was added specifically because
 * moving the build into an isolated workspace (no `.env`, no real
 * database, no real credentials — by design, see BuildWorkspace) exposed
 * that a project's own `post-autoload-dump` script
 * (`artisan package:discover`, standard in every Laravel project) fully
 * BOOTS the application, including every registered service provider —
 * confirmed by direct testing against a real project's exact dependency
 * tree: this failed first on a missing SQLite database file, and after
 * that was worked around, failed again on a broadcasting provider
 * constructing a Pusher client with a null auth key — both purely
 * because no `.env` exists yet at this point, never because anything
 * was actually wrong with the build. Chasing each such failure with a
 * fake/dummy env value is an unbounded, project-specific game of
 * whack-a-mole. `--no-scripts` sidesteps the entire category: the
 * package-discovery cache it would have produced
 * (`bootstrap/cache/packages.php`) is not required for Laravel to run —
 * `PackageManifest` regenerates it lazily, in-process, the first time
 * ANY artisan command or request boots the framework and finds that
 * file missing (standard Laravel behavior, not something this project
 * has to arrange) — and the very first thing production's own pipeline
 * runs after activating the new artifact is `artisan migrate --force`,
 * which will trigger exactly that lazy rebuild using the REAL `.env`
 * this local build deliberately never has. That's the more correct
 * place for it to happen anyway.
 *
 * Root-cause fix (v1.4.2, npm process-tail on Windows): a real,
 * empirically-verified problem distinct from everything above — `npm ci`/
 * `npm run build` can finish ALL of their user-visible work (dependency
 * install, postinstall scripts) and still be a genuinely live OS process
 * for a long tail afterward (registry-client keep-alive sockets and the
 * update-notifier check both delay Node's event loop from emptying — see
 * GitManager's own docblock for the full, instrumented audit). Two
 * independent mitigations, both scoped to npm only:
 * 1. `--no-audit --no-fund --no-update-notifier` on every npm invocation
 *    — removes two of the known contributors to that tail outright
 *    (measured to shrink it, not just theorized).
 * 2. A configurable grace period ($npmGracePeriodSeconds): if the
 *    ORIGINAL timeout is reached but npm's own output already shows a
 *    genuine completion indicator (see npmLikelySucceeded()), the
 *    process is given up to that many MORE seconds to exit on its own
 *    before being force-killed — never masking an actually-hung/failed
 *    npm (no completion indicator ⇒ immediate kill, exactly as before).
 */
final class LocalBuildRunner
{
    /**
     * @param array<int, string> $composerCommand base argv for invoking
     *     Composer — e.g. ['composer'] (default, relies on PATH) or an
     *     explicit path, optionally as [php_binary, composer.phar] (two
     *     argv elements — never a shell string). Subcommand/flags are
     *     array_merge()'d onto this, never concatenated as text.
     * @param array<int, string> $npmCommand same idea, for npm — e.g.
     *     ['npm'] (default) or ['C:\Program Files\nodejs\npm.cmd'].
     * @param string|null $phpBinary consulted (a) when $composerCommand
     *     resolves to a bare `*.phar` with no interpreter already in
     *     front of it (Windows cannot execute a .phar directly) — it is
     *     then transparently prepended; and (b) as the preferred PHP
     *     binary to run the `--ini`/`-m` diagnostics against in
     *     assertPlatformRequirementsSatisfied(). Ignored for (a) when
     *     $composerCommand is already a full [php_binary, composer.phar]
     *     pair, which is self-contained.
     * @param Logger|null $logger optional — only ever used to surface the
     *     three npm process-tail events (entering the graceful wait,
     *     exiting naturally during it, the eventual force-kill) as they
     *     happen. Every other method on this class is unaffected by
     *     whether one is given.
     * @param int $npmGracePeriodSeconds see this class's own docblock —
     *     0 disables the grace period entirely (npm reverts to the
     *     original, unconditional kill-at-timeout behavior).
     */
    /**
     * Appended to every npm invocation. `--no-update-notifier` applies
     * globally (any npm subcommand can trigger the background version
     * check); `--no-audit`/`--no-fund` are install-specific config keys
     * that npm silently ignores for `run` — harmless to include on both,
     * confirmed by direct testing (`npm run build --no-audit --no-fund
     * --no-update-notifier` exits 0, unaffected).
     */
    private const NPM_QUIET_FLAGS = ['--no-audit', '--no-fund', '--no-update-notifier'];

    public function __construct(
        private readonly GitManager $runner,
        private readonly array $composerCommand = ['composer'],
        private readonly array $npmCommand = ['npm'],
        private readonly ?string $phpBinary = null,
        private readonly int $composerTimeoutSeconds = 600,
        private readonly int $npmInstallTimeoutSeconds = 600,
        private readonly int $npmBuildTimeoutSeconds = 600,
        private readonly int $platformCheckTimeoutSeconds = 60,
        private readonly ?Logger $logger = null,
        private readonly int $npmGracePeriodSeconds = 120,
    ) {
    }

    /**
     * @return array{ranComposer: bool, ranNpmInstall: bool, ranNpmBuild: bool, log: array<int, string>}
     * @throws LocalBuildFailedException
     */
    public function run(string $repositoryPath, ?callable $onTick = null, ?BuildProfile $profile = null): array
    {
        $ranComposer = false;
        $ranNpmInstall = false;
        $ranNpmBuild = false;
        $log = [];

        $runComposer = $profile?->composer ?? is_file("{$repositoryPath}/composer.json");
        $runNpmInstall = $profile?->npmInstall ?? is_file("{$repositoryPath}/package.json");
        $runNpmBuild = $profile?->npmBuild ?? ($runNpmInstall && self::hasBuildScript($repositoryPath));
        if ($runComposer) {
            if (!is_file("{$repositoryPath}/composer.json")) throw new LocalBuildFailedException('invalid_build_profile', 'composer:true requires composer.json.');
            $this->assertPlatformRequirementsSatisfied($repositoryPath);

            $command = array_merge(
                $this->resolvedComposerCommand(),
                ['install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-scripts'],
            );
            $log[] = self::describe($command);
            $result = $this->runner->run($command, $repositoryPath, $this->composerTimeoutSeconds, $onTick);

            if ($result['exitCode'] !== 0) {
                throw new LocalBuildFailedException(
                    'local_composer_failed',
                    'composer install failed (exit ' . $result['exitCode'] . '): ' . self::tail($result['output']),
                );
            }
            $ranComposer = true;
        }

        if ($runNpmInstall) {
            if (!is_file("{$repositoryPath}/package.json")) throw new LocalBuildFailedException('invalid_build_profile', 'npm_install:true requires package.json.');
            $installCommand = array_merge($this->resolvedNpmCommand(), ['ci', '--include=dev', ...self::NPM_QUIET_FLAGS]);
            $log[] = self::describe($installCommand);
            $installResult = $this->runner->run(
                $installCommand,
                $repositoryPath,
                $this->npmInstallTimeoutSeconds,
                $onTick,
                $this->npmGracePeriodSeconds,
                static fn (string $output): bool => self::npmLikelySucceeded($output, isInstallCommand: true),
                $this->npmLogSink('npm ci'),
            );

            if ($installResult['exitCode'] !== 0) {
                throw new LocalBuildFailedException(
                    'local_npm_install_failed',
                    'npm ci failed (exit ' . $installResult['exitCode'] . '): ' . self::tail($installResult['output']),
                );
            }
            $ranNpmInstall = true;

            if ($runNpmBuild) {
                if (!self::hasBuildScript($repositoryPath)) throw new LocalBuildFailedException('invalid_build_profile', 'npm_build:true requires package.json scripts.build.');
                $buildCommand = array_merge($this->resolvedNpmCommand(), ['run', 'build', ...self::NPM_QUIET_FLAGS]);
                $log[] = self::describe($buildCommand);
                $buildResult = $this->runner->run(
                    $buildCommand,
                    $repositoryPath,
                    $this->npmBuildTimeoutSeconds,
                    $onTick,
                    $this->npmGracePeriodSeconds,
                    static fn (string $output): bool => self::npmLikelySucceeded($output, isInstallCommand: false),
                    $this->npmLogSink('npm run build'),
                );

                if ($buildResult['exitCode'] !== 0) {
                    throw new LocalBuildFailedException(
                        'local_asset_build_failed',
                        'npm run build failed (exit ' . $buildResult['exitCode'] . '): ' . self::tail($buildResult['output']),
                    );
                }
                $ranNpmBuild = true;
            }
            if ($profile?->prismaGenerate) {
                $command = ['npx', '--no-install', 'prisma', 'generate'];
                $log[] = implode(' ', $command);
                $result = $this->runner->run($command, $repositoryPath, $this->npmBuildTimeoutSeconds, $onTick);
                if ($result['exitCode'] !== 0) throw new LocalBuildFailedException('local_prisma_generate_failed', 'prisma generate failed (exit ' . $result['exitCode'] . '): ' . self::tail($result['output']));
            }
        }

        return ['ranComposer' => $ranComposer, 'ranNpmInstall' => $ranNpmInstall, 'ranNpmBuild' => $ranNpmBuild, 'log' => $log];
    }

    /**
     * Root-cause fix (v1.4.2): runs Composer's own, authoritative
     * `check-platform-reqs` — which checks composer.lock's actual
     * (including transitive) `ext-*`/`php` requirements against the PHP
     * environment Composer itself is running under — BEFORE `composer
     * install` ever starts. Never passes `--ignore-platform-req`
     * anywhere; this only detects and reports, it never bypasses.
     *
     * Best-effort by design: if this sub-command can't produce a valid
     * verdict at all (e.g. an old Composer without this command, or a
     * misconfigured composer_command that can't start any process), this
     * silently returns rather than inventing a false diagnosis — the
     * real `composer install` right after this is always the
     * authoritative step, and its own failure (reported as the existing
     * generic local_composer_failed) is still correct in that case.
     *
     * @throws LocalBuildFailedException with failureCode
     *     'missing_php_extension' when a real, concrete missing
     *     `ext-*` requirement is found.
     */
    private function assertPlatformRequirementsSatisfied(string $repositoryPath): void
    {
        $command = array_merge($this->resolvedComposerCommand(), ['check-platform-reqs', '--no-dev', '--format=json']);
        $result = $this->runner->run($command, $repositoryPath, $this->platformCheckTimeoutSeconds);

        $entries = self::extractJsonArray($result['output']);
        if ($entries === null) {
            return;
        }

        $missingExtensions = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['status'] ?? 'success') === 'success') {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');
            if (!str_starts_with($name, 'ext-')) {
                continue; // out of scope here: a failing "php" version entry is a different problem
            }

            $source = $entry['failed_requirement']['source'] ?? null;
            $missingExtensions[] = is_string($source) && $source !== ''
                ? "{$name} (required by {$source})"
                : $name;
        }

        if ($missingExtensions === []) {
            return;
        }

        throw new LocalBuildFailedException(
            'missing_php_extension',
            $this->buildMissingExtensionMessage($repositoryPath, $missingExtensions),
        );
    }

    /**
     * @param array<int, string> $missingExtensions
     */
    private function buildMissingExtensionMessage(string $repositoryPath, array $missingExtensions): string
    {
        $diagnosticPhpBinary = $this->resolvedDiagnosticPhpBinary();
        $iniResult = $this->runner->run([$diagnosticPhpBinary, '--ini'], $repositoryPath, $this->platformCheckTimeoutSeconds);
        $modulesResult = $this->runner->run([$diagnosticPhpBinary, '-m'], $repositoryPath, $this->platformCheckTimeoutSeconds);

        $loadedIniPath = self::extractLoadedIniPath($iniResult['output']);

        return 'Missing required PHP extension(s) for this project: ' . implode(', ', $missingExtensions) . '.'
            . ' The PHP binary used to run Composer for this build does not have the extension(s) above enabled.'
            . ' Enable them in the php.ini below, then retry the publish — do NOT use --ignore-platform-req:'
            . ' the deployed artifact would still be missing this extension in production.'
            . "\n\nPHP binary checked: {$diagnosticPhpBinary}"
            . "\nLoaded php.ini: {$loadedIniPath}"
            . "\n\n--- php --ini ---\n" . trim($iniResult['output'])
            . "\n\n--- php -m (loaded modules) ---\n" . trim($modulesResult['output']);
    }

    /**
     * Which PHP binary to run `--ini`/`-m` against for diagnostics —
     * best-effort, since composer_command may be a bare ['composer']
     * that resolves its own interpreter internally (a .bat/.phar shim)
     * with no single obvious "the php binary" to point at.
     */
    private function resolvedDiagnosticPhpBinary(): string
    {
        if ($this->phpBinary !== null && $this->phpBinary !== '') {
            return $this->phpBinary;
        }

        $composerCommand = $this->resolvedComposerCommand();
        if (count($composerCommand) >= 2 && self::looksLikePhpExecutable($composerCommand[0])) {
            return $composerCommand[0];
        }

        return 'php';
    }

    private static function looksLikePhpExecutable(string $path): bool
    {
        $basename = strtolower(pathinfo($path, PATHINFO_FILENAME));

        return $basename === 'php' || str_starts_with($basename, 'php-') || str_starts_with($basename, 'php8') || str_starts_with($basename, 'php7');
    }

    /**
     * @return array<int, mixed>|null
     */
    private static function extractJsonArray(string $output): ?array
    {
        $start = strpos($output, '[');
        $end = strrpos($output, ']');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function extractLoadedIniPath(string $phpIniOutput): string
    {
        if (preg_match('/^Loaded Configuration File:\s*(.*)$/mi', $phpIniOutput, $matches) === 1) {
            $path = trim($matches[1]);

            return $path === '' ? '(none — no php.ini loaded at all)' : $path;
        }

        return '(could not be determined — see the raw php --ini output below)';
    }

    /**
     * @return array<int, string>
     */
    private function resolvedComposerCommand(): array
    {
        $command = array_values(array_filter($this->composerCommand, static fn (mixed $v): bool => is_string($v) && $v !== ''));
        if ($command === []) {
            $command = ['composer'];
        }

        if (
            $this->phpBinary !== null
            && $this->phpBinary !== ''
            && str_ends_with(strtolower($command[0]), '.phar')
        ) {
            array_unshift($command, $this->phpBinary);
        }

        return $command;
    }

    /**
     * @return array<int, string>
     */
    private function resolvedNpmCommand(): array
    {
        $command = array_values(array_filter($this->npmCommand, static fn (mixed $v): bool => is_string($v) && $v !== ''));

        return $command === [] ? ['npm'] : $command;
    }

    /**
     * `null` (no-op) when no Logger was given — every existing caller
     * that constructs this class without one (every test fixture prior
     * to this fix, and any future caller that doesn't need this) is
     * completely unaffected.
     */
    private function npmLogSink(string $label): ?\Closure
    {
        if ($this->logger === null) {
            return null;
        }

        return function (string $message) use ($label): void {
            $this->logger->info("[{$label}] {$message}");
        };
    }

    /**
     * Root-cause fix (npm process-tail, see this class's and
     * GitManager's own docblocks): whether npm's ACCUMULATED output
     * already shows a genuine completion indicator — consulted only once
     * the configured timeout has already been reached, to decide between
     * an immediate kill (unchanged, original behavior) and a bounded
     * grace period.
     *
     * A real `npm error`/`npm ERR!` line always disqualifies — that's
     * evidence of an actual failure, never "done and just lingering",
     * and the original, immediate-kill behavior is the right one for it.
     *
     * For `npm ci`/`npm install`, npm prints one fixed, well-known final
     * summary line ("added N packages in Xs", "up to date in Xs",
     * "changed N packages in Xs", ...) only once dependency resolution
     * AND every lifecycle script (postinstall included) have genuinely
     * finished — confirmed against real npm output captured during this
     * fix's own audit. Its presence is strong, specific evidence.
     *
     * `npm run <script>` has no such universal marker — it depends
     * entirely on whatever build tool the script invokes. Non-empty
     * output with no npm error, this deep into the configured timeout,
     * is treated as weaker but still meaningful evidence of the same
     * tail-lifetime pattern rather than a genuine hang — bounded by the
     * grace period either way, so a wrong guess here costs at most
     * $npmGracePeriodSeconds of extra wait, never a silently-accepted
     * failure (a truly stuck build is still killed once grace elapses).
     */
    private static function npmLikelySucceeded(string $output, bool $isInstallCommand): bool
    {
        if (preg_match('/\bnpm (?:error|ERR!)\b/i', $output) === 1) {
            return false;
        }

        if ($isInstallCommand) {
            return preg_match(
                '/\b(?:added|removed|changed)\s+\d+\s+packages?\b[\s\S]*?\bin\s+[\d.]+m?s\b|\bup to date\b[\s\S]*?\bin\s+[\d.]+m?s\b/i',
                $output,
            ) === 1;
        }

        return trim($output) !== '';
    }

    /**
     * @param array<int, string> $command
     */
    private static function describe(array $command): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => str_contains($part, ' ') ? '"' . $part . '"' : $part,
            $command,
        ));
    }

    private static function hasBuildScript(string $repositoryPath): bool
    {
        $decoded = json_decode((string) @file_get_contents("{$repositoryPath}/package.json"), true);

        return is_array($decoded) && isset($decoded['scripts']['build']);
    }

    private static function tail(string $output, int $maxChars = 4000): string
    {
        return mb_strlen($output) > $maxChars ? '...' . mb_substr($output, -$maxChars) : $output;
    }
}
