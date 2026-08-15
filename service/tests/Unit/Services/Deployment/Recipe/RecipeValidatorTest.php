<?php

namespace Tests\Unit\Services\Deployment\Recipe;

use App\Services\Deployment\Recipe\RecipeValidator;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Phase 2 (sandogh-family-fund deployment overhaul): RecipeValidator is
 * the allowlist that makes `Project.deployment_recipe` safe to add on
 * top of the existing admin-trusted `pipeline` column — every step must
 * be one of a fixed, agent-implemented type, with structured (never raw
 * shell string, except the explicit `shell` escape hatch) parameters.
 */
class RecipeValidatorTest extends TestCase
{
    private RecipeValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RecipeValidator();
    }

    public function test_accepts_a_flat_array_of_steps_and_fills_in_defaults(): void
    {
        $result = $this->validator->validate([
            ['type' => 'install_dependencies', 'working_directory' => '/srv/app'],
            ['type' => 'activate_release'],
        ]);

        $this->assertSame(RecipeValidator::DEFAULT_TOTAL_TIMEOUT_SECONDS, $result['timeout_seconds']);
        $this->assertCount(2, $result['steps']);
        $this->assertSame('install_dependencies', $result['steps'][0]['type']);
        $this->assertSame('npm', $result['steps'][0]['manager']);
        $this->assertSame('ci', $result['steps'][0]['mode']);
        $this->assertSame(RecipeValidator::DEFAULT_STEP_TIMEOUT_SECONDS, $result['steps'][0]['timeout_seconds']);
    }

    public function test_accepts_the_wrapped_object_shape_with_a_total_timeout_override(): void
    {
        $result = $this->validator->validate([
            'timeout_seconds' => 1200,
            'steps' => [
                ['type' => 'shell', 'command' => 'echo hi'],
            ],
        ]);

        $this->assertSame(1200, $result['timeout_seconds']);
        $this->assertSame('echo hi', $result['steps'][0]['command']);
    }

    public function test_rejects_an_empty_recipe(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([]);
    }

    public function test_rejects_a_non_array_recipe(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate('not-an-array');
    }

    public function test_rejects_an_unknown_step_type(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'delete_everything'],
        ]);
    }

    public function test_rejects_a_step_missing_a_required_param(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'run_script', 'working_directory' => '/srv/app'],
        ]);
    }

    public function test_rejects_an_unexpected_key_on_a_step(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'shell', 'command' => 'echo hi', 'run_as_root' => true],
        ]);
    }

    public function test_rejects_an_unexpected_key_on_the_wrapped_object(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            'steps' => [['type' => 'shell', 'command' => 'echo hi']],
            'retries' => 3,
        ]);
    }

    public function test_rejects_a_recipe_with_only_verify_steps(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'verify_runtime', 'runtime' => 'node', 'min_version' => '20.9.0'],
            ['type' => 'verify_path_exists', 'path' => '/srv/app'],
        ]);
    }

    public function test_rejects_an_invalid_version_string(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'verify_runtime', 'runtime' => 'node', 'min_version' => 'latest'],
        ]);
    }

    public function test_rejects_a_non_http_health_check_url(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'http_health_check', 'url' => 'ftp://example.test'],
        ]);
    }

    public function test_rejects_an_invalid_env_var_name(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'run_script', 'script' => 'build', 'working_directory' => '/srv/app', 'env' => ['1INVALID' => 'x']],
        ]);
    }

    /**
     * The `env` map is passed straight through to the agent's
     * proc_open() `env` argument (a real array assigned directly to the
     * child process's environment block, never concatenated into a
     * shell string) — this confirms values are preserved as-is rather
     * than being escaped or mangled, which would break e.g. a URL value.
     */
    public function test_preserves_env_values_verbatim(): void
    {
        $result = $this->validator->validate([
            [
                'type' => 'run_script',
                'script' => 'build:web',
                'working_directory' => '/srv/app/apps/web',
                'env' => ['NEXT_PUBLIC_API_URL' => 'https://sandogh.safhenokhost.ir/api'],
            ],
        ]);

        $this->assertSame('https://sandogh.safhenokhost.ir/api', $result['steps'][0]['env']['NEXT_PUBLIC_API_URL']);
    }

    public function test_rejects_a_step_timeout_above_the_maximum(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'shell', 'command' => 'echo hi', 'timeout_seconds' => RecipeValidator::MAX_STEP_TIMEOUT_SECONDS + 1],
        ]);
    }

    public function test_rejects_a_total_timeout_above_the_maximum(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            'timeout_seconds' => RecipeValidator::MAX_TOTAL_TIMEOUT_SECONDS + 1,
            'steps' => [['type' => 'shell', 'command' => 'echo hi']],
        ]);
    }

    public function test_rejects_a_destructive_prisma_migrate_flag_of_the_wrong_type(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'prisma_migrate', 'working_directory' => '/srv/app', 'allow_destructive' => 'yes'],
        ]);
    }

    public function test_rejects_a_checkout_release_step_missing_source_repository(): void
    {
        $this->expectException(HttpException::class);

        $this->validator->validate([
            ['type' => 'checkout_release'],
        ]);
    }

    public function test_accepts_a_valid_checkout_release_step(): void
    {
        $result = $this->validator->validate([
            ['type' => 'checkout_release', 'source_repository' => '/var/www/sandogh/source'],
        ]);

        $this->assertSame('/var/www/sandogh/source', $result['steps'][0]['source_repository']);
    }

    public function test_accepts_a_realistic_sandogh_shaped_recipe(): void
    {
        $result = $this->validator->validate([
            ['type' => 'checkout_release', 'source_repository' => '/var/www/sandogh/source'],
            ['type' => 'verify_runtime', 'runtime' => 'node', 'min_version' => '20.9.0'],
            ['type' => 'verify_disk_space', 'path' => '/var/www/sandogh', 'min_free_mb' => 1024],
            ['type' => 'install_dependencies', 'working_directory' => '/var/www/sandogh/releases/abc123'],
            ['type' => 'run_script', 'script' => 'prisma:generate', 'working_directory' => '/var/www/sandogh/releases/abc123'],
            ['type' => 'run_script', 'script' => 'build:api', 'working_directory' => '/var/www/sandogh/releases/abc123'],
            [
                'type' => 'run_script',
                'script' => 'build:web',
                'working_directory' => '/var/www/sandogh/releases/abc123',
                'env' => ['NEXT_PUBLIC_API_URL' => 'https://sandogh.safhenokhost.ir/api'],
            ],
            ['type' => 'verify_path_exists', 'path' => 'apps/api/dist/main.js'],
            ['type' => 'verify_path_exists', 'path' => 'apps/web/.next/standalone/apps/web/server.js'],
            ['type' => 'verify_file_not_contains', 'path' => 'apps/web/.next', 'forbidden' => 'localhost:3000'],
            ['type' => 'prisma_migrate', 'working_directory' => '/var/www/sandogh/releases/abc123'],
            ['type' => 'assemble_next_standalone', 'app_path' => 'apps/web'],
            ['type' => 'set_ownership', 'path' => '/var/www/sandogh/releases/abc123', 'owner' => 'sandogh:sandogh'],
            ['type' => 'activate_release'],
            ['type' => 'restart_service', 'service' => 'sandogh-api.service'],
            ['type' => 'restart_service', 'service' => 'sandogh-web.service'],
            ['type' => 'verify_service_active', 'service' => 'sandogh-api.service'],
            ['type' => 'verify_service_active', 'service' => 'sandogh-web.service'],
            ['type' => 'http_health_check', 'url' => 'https://sandogh.safhenokhost.ir/api/health'],
            ['type' => 'http_health_check', 'url' => 'https://sandogh.safhenokhost.ir/login'],
        ]);

        $this->assertCount(20, $result['steps']);
    }
}
