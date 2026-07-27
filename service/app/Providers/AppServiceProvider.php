<?php

namespace App\Providers;

use App\Listeners\NotificationEventSubscriber;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use App\Support\Authorization\PermissionMatrix;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Already live on the real production Hub (deployment.safhenokhost.ir,
        // behind a reverse proxy) — mirrored here so the next deployment's
        // git reset --hard doesn't revert it. Laravel's own scheme/host
        // detection can be wrong behind a proxy that terminates TLS itself;
        // this forces every generated URL (asset(), route(), the GitHub
        // webhook URL bootstrap prints, etc.) to https in production
        // specifically, leaving local/dev environments unaffected.
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        // Phase 4-F: every dot-notation ability (e.g. "deployments.rollback")
        // is resolved from the role->permission matrix — this is the one
        // place $user->can('x.y') / @can('x.y') actually gets answered, so
        // no Controller needs its own role check. Abilities that aren't
        // dot-notation (below) fall through unaffected by returning null.
        Gate::before(function (User $user, string $ability) {
            if (!str_contains($ability, '.')) {
                return null;
            }

            return PermissionMatrix::roleHasPermission($user->role, $ability);
        });

        // Phase 4-C.5 Task 7's named choke point for Environment/
        // DeploymentTarget mutations — originally a deliberate no-op
        // ("every authenticated user can pass it right now... the only
        // thing this adds is a seam a future phase can tighten"). This is
        // that future phase: it now consults the same permission matrix
        // as everything else, via the same environments.manage ability
        // used nowhere else, so it stays a single named choke point.
        Gate::define('manage-deployment-topology', fn (User $user) => $user->hasPermission('environments.manage'));

        // Phase 4-G: the first real resource-level Policy — Project
        // abilities ('view'/'update'/'deploy'/'rollback', none dot-named,
        // so the Gate::before() hook above never intercepts them) combine
        // the Phase 4-F permission matrix with per-project membership.
        Gate::policy(Project::class, ProjectPolicy::class);

        // Phase 4-J: the Notification Center's only subscriber — every
        // System Event this app dispatches (deployment lifecycle,
        // agent/GitHub/security operational events) turns into a
        // Notification row here, and nowhere else.
        Event::subscribe(NotificationEventSubscriber::class);
    }
}
