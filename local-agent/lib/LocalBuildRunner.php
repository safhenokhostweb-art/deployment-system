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
     */
    public function __construct(
        private readonly GitManager $runner,
        private readonly array $composerCommand = ['composer'],
        private readonly array $npmCommand = ['npm'],
        private readonly ?string $phpBinary = null,
        private readonly int $composerTimeoutSeconds = 600,
        private readonly int $npmInstallTimeoutSeconds = 600,
        private readonly int $npmBuildTimeoutSeconds = 600,
        private readonly int $platformCheckTimeoutSeconds = 60,
    ) {
    }

    /**
     * @return array{ranComposer: bool, ranNpmInstall: bool, ranNpmBuild: bool, log: array<int, string>}
     * @throws LocalBuildFailedException
     */
    public function run(string $repositoryPath, ?callable $onTick = null): array
    {
        $ranComposer = false;
        $ranNpmInstall = false;
        $ranNpmBuild = false;
        $log = [];

        if (is_file("{$repositoryPath}/composer.json")) {
            $this->assertPlatformRequirementsSatisfied($repositoryPath);

            $command = array_merge(
                $this->resolvedComposerCommand(),
                ['install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'],
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

        if (is_file("{$repositoryPath}/package.json")) {
            $installCommand = array_merge($this->resolvedNpmCommand(), ['ci', '--include=dev']);
            $log[] = self::describe($installCommand);
            $installResult = $this->runner->run($installCommand, $repositoryPath, $this->npmInstallTimeoutSeconds, $onTick);

            if ($installResult['exitCode'] !== 0) {
                throw new LocalBuildFailedException(
                    'local_npm_install_failed',
                    'npm ci failed (exit ' . $installResult['exitCode'] . '): ' . self::tail($installResult['output']),
                );
            }
            $ranNpmInstall = true;

            if (self::hasBuildScript($repositoryPath)) {
                $buildCommand = array_merge($this->resolvedNpmCommand(), ['run', 'build']);
                $log[] = self::describe($buildCommand);
                $buildResult = $this->runner->run($buildCommand, $repositoryPath, $this->npmBuildTimeoutSeconds, $onTick);

                if ($buildResult['exitCode'] !== 0) {
                    throw new LocalBuildFailedException(
                        'local_asset_build_failed',
                        'npm run build failed (exit ' . $buildResult['exitCode'] . '): ' . self::tail($buildResult['output']),
                    );
                }
                $ranNpmBuild = true;
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
