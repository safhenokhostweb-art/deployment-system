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
     * @param string|null $phpBinary consulted only when $composerCommand
     *     resolves to a bare `*.phar` with no interpreter already in
     *     front of it (Windows cannot execute a .phar directly) — it is
     *     then transparently prepended. Ignored in every other case,
     *     including the two-element [php_binary, composer.phar] form
     *     above, which is already fully self-contained.
     */
    public function __construct(
        private readonly GitManager $runner,
        private readonly array $composerCommand = ['composer'],
        private readonly array $npmCommand = ['npm'],
        private readonly ?string $phpBinary = null,
        private readonly int $composerTimeoutSeconds = 600,
        private readonly int $npmInstallTimeoutSeconds = 600,
        private readonly int $npmBuildTimeoutSeconds = 600,
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
