<?php

namespace App\Services\Bootstrap;

/**
 * Parsed, validated contents of config.env — one property per variable
 * documented in deployment-bootstrap/config.example.env. Kept as a plain
 * typed DTO (not an Eloquent model or a raw array) so a typo in a config
 * key fails loudly, with a clear Persian message, at parse time instead
 * of silently producing a null deep inside DeploymentBootstrapService.
 *
 * DEPLOYMENT_APP_PATH, LARAVEL_PATH, and TARGET_LARAVEL_PATH are three
 * separate fields in config.env (even though they're the same path in
 * the single-server example) and are kept separate here on purpose:
 * DEPLOYMENT_APP_PATH is only ever used by install.sh itself (where to
 * `cd` to run `php artisan deployment:bootstrap` — this class never
 * reads it), LARAVEL_PATH is what gets baked into the Project's
 * pipeline commands, and TARGET_LARAVEL_PATH/TARGET_PATH are what the
 * Agent's own local config.php working_directory is built from — never
 * stored as a DeploymentTarget column at all (this project's
 * DeploymentTarget deliberately holds no filesystem path, see
 * app/Models/DeploymentTarget.php's own docblock).
 */
final class DeploymentBootstrapConfig
{
    public function __construct(
        public readonly string $panelUrl,
        public readonly string $repositoryPath,
        public readonly string $laravelPath,
        public readonly string $serverName,
        public readonly ?string $serverIp,
        public readonly string $agentName,
        public readonly string $projectName,
        public readonly string $projectKey,
        public readonly string $repositorySshUrl,
        public readonly ?string $repositoryHttpsUrl,
        public readonly string $githubOwner,
        public readonly string $githubRepository,
        public readonly string $deployBranch,
        public readonly string $environmentName,
        public readonly string $environmentKey,
        public readonly string $targetName,
        public readonly string $targetPath,
        public readonly string $targetLaravelPath,
        public readonly string $phpBinary,
        public readonly string $phpFpmService,
        public readonly string $webServerService,
        public readonly bool $runComposerInstall,
        public readonly ?string $githubToken,
        public readonly bool $autoCreateGithubWebhook,
    ) {
    }

    /**
     * A small, dependency-free KEY=VALUE parser (comments and blank lines
     * skipped, optional matching single/double quotes stripped) rather
     * than reaching for vlucas/phpdotenv's own parser — config.env is a
     * separate, user-edited file outside this app's own .env lifecycle,
     * and this app's already-loaded environment must never be mutated
     * by reading someone else's config file.
     *
     * @return array<string, string>
     */
    public static function parseEnvFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new DeploymentBootstrapException("فایل تنظیمات قابل خواندن نیست: {$path}");
        }

        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $result[$key] = $value;
        }

        return $result;
    }

    public static function fromEnvFile(string $path): self
    {
        return self::fromEnvArray(self::parseEnvFile($path));
    }

    /**
     * @param array<string, string> $env raw KEY => value pairs, exactly as
     *   parsed from config.env (still strings — this is where they get
     *   typed/defaulted/validated).
     */
    public static function fromEnvArray(array $env): self
    {
        $require = static function (string $key) use ($env): string {
            $value = trim((string) ($env[$key] ?? ''));
            if ($value === '') {
                throw new DeploymentBootstrapException("مقدار الزامی «{$key}» در فایل تنظیمات خالی یا وجود ندارد.");
            }

            return $value;
        };

        $optional = static fn (string $key): ?string => filled(trim((string) ($env[$key] ?? ''))) ? trim($env[$key]) : null;

        $boolean = static function (string $key) use ($env): bool {
            $value = strtolower(trim((string) ($env[$key] ?? 'false')));

            return in_array($value, ['1', 'true', 'yes', 'on'], true);
        };

        return new self(
            panelUrl: $require('DEPLOYMENT_PANEL_URL'),
            repositoryPath: $require('REPOSITORY_PATH'),
            laravelPath: $require('LARAVEL_PATH'),
            serverName: $require('SERVER_NAME'),
            serverIp: $optional('SERVER_IP'),
            agentName: $require('AGENT_NAME'),
            projectName: $require('PROJECT_NAME'),
            projectKey: $require('PROJECT_KEY'),
            repositorySshUrl: $require('REPOSITORY_SSH_URL'),
            repositoryHttpsUrl: $optional('REPOSITORY_HTTPS_URL'),
            githubOwner: $require('GITHUB_OWNER'),
            githubRepository: $require('GITHUB_REPOSITORY'),
            deployBranch: $require('DEPLOY_BRANCH'),
            environmentName: $require('ENVIRONMENT_NAME'),
            environmentKey: $require('ENVIRONMENT_KEY'),
            targetName: $require('TARGET_NAME'),
            targetPath: $require('TARGET_PATH'),
            targetLaravelPath: $require('TARGET_LARAVEL_PATH'),
            phpBinary: $require('PHP_BINARY'),
            phpFpmService: $require('PHP_FPM_SERVICE'),
            webServerService: $require('WEB_SERVER_SERVICE'),
            runComposerInstall: $boolean('RUN_COMPOSER_INSTALL'),
            githubToken: $optional('GITHUB_TOKEN'),
            autoCreateGithubWebhook: $boolean('AUTO_CREATE_GITHUB_WEBHOOK'),
        );
    }

    public function repository(): string
    {
        return "{$this->githubOwner}/{$this->githubRepository}";
    }
}
