<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (sandogh-family-fund incomplete deploys): `pipeline`
 * (a flat `[{name, command}]` array of trusted admin shell strings) is
 * left completely untouched — every existing Project keeps working
 * exactly as before, forever. `deployment_recipe` is a new, OPTIONAL,
 * richer alternative: an ordered list of TYPED steps
 * (`{type, ...params}`) drawn from a fixed, agent-implemented catalog
 * (see RecipeValidator / agent/lib/RecipeStepRunner.php) — install
 * dependencies, run an npm script with env vars, apply a Prisma
 * migration, assemble a Next.js standalone build, atomically activate a
 * release, restart a specific systemd unit, run an HTTP health check.
 * Parameters are DATA (script names, URLs, service names), never a raw
 * shell string, for every type except the explicit `shell` escape hatch
 * (kept for parity with the legacy `pipeline` column, so a recipe can
 * still drop into a trusted admin-authored command when the typed
 * catalog doesn't cover something yet).
 *
 * When present and non-empty, `deployment_recipe` takes precedence over
 * `pipeline` for a given Project — see AgentJobService::buildJobPayload().
 * A Project with no recipe is entirely unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('deployment_recipe')->nullable()->after('pipeline')
                ->comment('Optional ordered list of {type, ...params} typed steps — see RecipeValidator. Takes precedence over `pipeline` when present and non-empty.');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('deployment_recipe');
        });
    }
};
