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
 */
final class LocalBuildRunner
{
    public function __construct(
        private readonly GitManager $runner,
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
            $log[] = 'composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction';
            $result = $this->runner->run(
                ['composer', 'install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction'],
                $repositoryPath,
                $this->composerTimeoutSeconds,
                $onTick,
            );

            if ($result['exitCode'] !== 0) {
                throw new LocalBuildFailedException(
                    'local_composer_failed',
                    'composer install failed (exit ' . $result['exitCode'] . '): ' . self::tail($result['output']),
                );
            }
            $ranComposer = true;
        }

        if (is_file("{$repositoryPath}/package.json")) {
            $log[] = 'npm ci --include=dev';
            $installResult = $this->runner->run(['npm', 'ci', '--include=dev'], $repositoryPath, $this->npmInstallTimeoutSeconds, $onTick);

            if ($installResult['exitCode'] !== 0) {
                throw new LocalBuildFailedException(
                    'local_npm_install_failed',
                    'npm ci failed (exit ' . $installResult['exitCode'] . '): ' . self::tail($installResult['output']),
                );
            }
            $ranNpmInstall = true;

            if (self::hasBuildScript($repositoryPath)) {
                $log[] = 'npm run build';
                $buildResult = $this->runner->run(['npm', 'run', 'build'], $repositoryPath, $this->npmBuildTimeoutSeconds, $onTick);

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
