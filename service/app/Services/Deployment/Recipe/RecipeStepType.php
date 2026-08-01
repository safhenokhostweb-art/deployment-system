<?php

namespace App\Services\Deployment\Recipe;

/**
 * The fixed, agent-implemented step-type catalog every deployment_recipe
 * step must be drawn from (see RecipeValidator). This catalog — not
 * parameter validation alone — is what makes deployment_recipe safe to
 * add on top of the existing admin-trusted `pipeline` column: every type
 * except SHELL takes structured parameters (a script name, a URL, a
 * service name) that the agent interprets itself, never a raw string
 * hint that. SHELL is kept only for parity with the legacy `pipeline`
 * column, so a recipe can still drop into a trusted admin-authored
 * command for something the typed catalog doesn't cover yet.
 *
 * This class is intentionally generic (npm/Prisma/Next.js concepts show
 * up as PARAMETERS — a script name, an app path — never as
 * sandogh-specific literals baked into the type list itself). The
 * concrete sandogh-family-fund recipe is just a JSON document built from
 * these types.
 */
final class RecipeStepType
{
    public const SHELL = 'shell';
    /**
     * Materializes the exact deployed commit into a brand-new, immutable
     * release directory the agent names and owns (`{release_path}` —
     * never admin-specified, so the Hub still never learns server file
     * paths). Implemented agent-side via `git worktree add` against a
     * persistent, out-of-band-bootstrapped source clone (source_repository)
     * — fast (shares the object store, no re-clone over the network on
     * every deploy) and, once created, untouched by any later deploy,
     * which is exactly what makes rollback a plain symlink swap instead
     * of "hope git reset --hard didn't leave the tree dirty".
     */
    public const CHECKOUT_RELEASE = 'checkout_release';
    public const VERIFY_RUNTIME = 'verify_runtime';
    public const VERIFY_DISK_SPACE = 'verify_disk_space';
    public const VERIFY_PATH_EXISTS = 'verify_path_exists';
    public const VERIFY_FILE_NOT_CONTAINS = 'verify_file_not_contains';
    public const INSTALL_DEPENDENCIES = 'install_dependencies';
    public const RUN_SCRIPT = 'run_script';
    public const PRISMA_MIGRATE = 'prisma_migrate';
    public const ASSEMBLE_NEXT_STANDALONE = 'assemble_next_standalone';
    public const ACTIVATE_RELEASE = 'activate_release';
    public const RESTART_SERVICE = 'restart_service';
    public const HTTP_HEALTH_CHECK = 'http_health_check';
    public const VERIFY_SERVICE_ACTIVE = 'verify_service_active';
    public const SET_OWNERSHIP = 'set_ownership';

    public const ALL = [
        self::SHELL,
        self::CHECKOUT_RELEASE,
        self::VERIFY_RUNTIME,
        self::VERIFY_DISK_SPACE,
        self::VERIFY_PATH_EXISTS,
        self::VERIFY_FILE_NOT_CONTAINS,
        self::INSTALL_DEPENDENCIES,
        self::RUN_SCRIPT,
        self::PRISMA_MIGRATE,
        self::ASSEMBLE_NEXT_STANDALONE,
        self::ACTIVATE_RELEASE,
        self::RESTART_SERVICE,
        self::HTTP_HEALTH_CHECK,
        self::VERIFY_SERVICE_ACTIVE,
        self::SET_OWNERSHIP,
    ];

    /**
     * Step types that change server state (as opposed to only reading/
     * verifying it) — used by RecipeValidator to require at least one
     * mutating step, so a "recipe" that is only preflight checks can't
     * silently masquerade as a real deployment.
     */
    public const MUTATING = [
        self::CHECKOUT_RELEASE,
        self::INSTALL_DEPENDENCIES,
        self::RUN_SCRIPT,
        self::PRISMA_MIGRATE,
        self::ASSEMBLE_NEXT_STANDALONE,
        self::ACTIVATE_RELEASE,
        self::RESTART_SERVICE,
        self::SET_OWNERSHIP,
        self::SHELL,
    ];
}
