<?php

namespace Tests\Unit\Services\Deployment\Recipe;

use App\Services\Deployment\Recipe\RecipeValidator;
use Tests\TestCase;

/**
 * Phase 3 of the sandogh-family-fund deployment overhaul: the concrete,
 * official recipe lives at docs/recipes/sandogh-family-fund.deployment_recipe.json
 * (paste-ready for the Project's "Deployment recipe" dashboard field, not
 * hardcoded into application code — Phase 2 explicitly required the
 * recipe SYSTEM to stay generic). This test is the regression guard: if
 * RecipeValidator's rules ever change, or someone edits the fixture file
 * by hand, CI catches an incompatibility immediately instead of it
 * surfacing as a 422 the next time an operator tries to save it.
 */
class SandoghRecipeFixtureTest extends TestCase
{
    private function fixturePath(): string
    {
        return base_path('../docs/recipes/sandogh-family-fund.deployment_recipe.json');
    }

    public function test_fixture_file_exists(): void
    {
        $this->assertFileExists($this->fixturePath());
    }

    public function test_fixture_is_valid_json(): void
    {
        $raw = file_get_contents($this->fixturePath());
        json_decode($raw, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'sandogh-family-fund.deployment_recipe.json is not valid JSON: ' . json_last_error_msg());
    }

    public function test_fixture_passes_recipe_validation(): void
    {
        $decoded = json_decode(file_get_contents($this->fixturePath()), true);

        $result = app(RecipeValidator::class)->validate($decoded);

        $this->assertGreaterThan(0, count($result['steps']));
    }

    public function test_fixture_contains_every_phase_3_required_concern(): void
    {
        $decoded = json_decode(file_get_contents($this->fixturePath()), true);
        $result = app(RecipeValidator::class)->validate($decoded);
        $types = array_column($result['steps'], 'type');

        // Preflight
        $this->assertContains('verify_runtime', $types);
        $this->assertContains('verify_disk_space', $types);
        // Build
        $this->assertContains('checkout_release', $types);
        $this->assertContains('install_dependencies', $types);
        $this->assertContains('run_script', $types);
        $this->assertContains('verify_file_not_contains', $types, 'must guard against a build that leaked a localhost API URL');
        // Standalone assembly
        $this->assertContains('assemble_next_standalone', $types);
        // Database
        $this->assertContains('prisma_migrate', $types);
        // Activation
        $this->assertContains('activate_release', $types);
        $this->assertContains('restart_service', $types);
        // Health checks
        $this->assertContains('verify_service_active', $types);
        $this->assertContains('http_health_check', $types);
    }

    public function test_fixture_restarts_and_verifies_both_sandogh_services(): void
    {
        $decoded = json_decode(file_get_contents($this->fixturePath()), true);
        $result = app(RecipeValidator::class)->validate($decoded);

        $restartedServices = array_column(array_filter($result['steps'], fn ($s) => $s['type'] === 'restart_service'), 'service');
        $verifiedServices = array_column(array_filter($result['steps'], fn ($s) => $s['type'] === 'verify_service_active'), 'service');

        $this->assertEqualsCanonicalizing(['sandogh-api.service', 'sandogh-web.service'], $restartedServices);
        $this->assertEqualsCanonicalizing(['sandogh-api.service', 'sandogh-web.service'], $verifiedServices);
    }

    public function test_fixture_builds_web_with_the_production_api_url_not_localhost(): void
    {
        $decoded = json_decode(file_get_contents($this->fixturePath()), true);
        $result = app(RecipeValidator::class)->validate($decoded);

        $buildWeb = collect($result['steps'])->firstWhere('script', 'build:web');

        $this->assertNotNull($buildWeb);
        $this->assertSame('https://sandogh.safhenokhost.ir/api', $buildWeb['env']['NEXT_PUBLIC_API_URL'] ?? null);
        $this->assertStringNotContainsString('localhost', $buildWeb['env']['NEXT_PUBLIC_API_URL']);
    }

    public function test_fixture_defaults_destructive_migrations_to_requiring_approval(): void
    {
        $decoded = json_decode(file_get_contents($this->fixturePath()), true);
        $result = app(RecipeValidator::class)->validate($decoded);

        $migrate = collect($result['steps'])->firstWhere('type', 'prisma_migrate');

        $this->assertNotNull($migrate);
        $this->assertFalse($migrate['allow_destructive'], 'a destructive migration must stop and require explicit approval, not apply automatically');
    }
}
