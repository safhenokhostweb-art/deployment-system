<?php

namespace App\Providers;

use App\Console\Commands\DatabaseSafety\GuardedFreshCommand;
use App\Console\Commands\DatabaseSafety\GuardedRefreshCommand;
use App\Console\Commands\DatabaseSafety\GuardedResetCommand;
use App\Console\Commands\DatabaseSafety\GuardedRollbackCommand;
use App\Console\Commands\DatabaseSafety\GuardedWipeCommand;
use App\Listeners\NotificationEventSubscriber;
use App\Models\Project;
use App\Models\User;
use App\Services\AgentDashboard\AgentGraphService;
use App\Services\AgentDashboard\AgentProcessLauncher;
use App\Services\AgentDashboard\AgentRunManager;
use App\Services\AgentDashboard\AgentRuntimeRepository;
use App\Services\AgentDashboard\NativeAgentProcessLauncher;
use App\Services\Sentinel\AgentRuntimeReader;
use App\Services\Sentinel\AgentRuntimeEvidenceReader;
use App\Services\Sentinel\ArtifactChecksumReader;
use App\Services\Sentinel\HubArtifactChecksumReader;
use App\Services\Sentinel\HubReleaseExpectationProvider;
use App\Services\Sentinel\IndependentHealthProbe;
use App\Services\Sentinel\ConfiguredHttpHealthProbe;
use App\Services\Sentinel\SentinelEvidenceCollector;
use App\Services\Sentinel\CurlPinnedHealthProbeTransport;
use App\Services\Sentinel\HealthProbeAddressResolver;
use App\Services\Sentinel\PinnedHealthProbeTransport;
use App\Services\Sentinel\ReleaseExpectationProvider;
use App\Services\Sentinel\SystemHealthProbeAddressResolver;
use App\Services\Sentinel\DatabaseSentinelVerificationRunStore;
use App\Services\Sentinel\SentinelVerificationRunStore;
use App\Services\Sentinel\SentinelEvidenceCollection;
use App\Policies\ProjectPolicy;
use App\Support\Authorization\PermissionMatrix;
use Illuminate\Console\Application as Artisan;
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
        $this->app->bind(ReleaseExpectationProvider::class, HubReleaseExpectationProvider::class);
        $this->app->bind(ArtifactChecksumReader::class, HubArtifactChecksumReader::class);
        $this->app->bind(AgentRuntimeReader::class, AgentRuntimeEvidenceReader::class);
        $this->app->bind(HealthProbeAddressResolver::class, SystemHealthProbeAddressResolver::class);
        $this->app->bind(PinnedHealthProbeTransport::class, CurlPinnedHealthProbeTransport::class);
        $this->app->bind(SentinelVerificationRunStore::class, DatabaseSentinelVerificationRunStore::class);
        $this->app->bind(SentinelEvidenceCollection::class, SentinelEvidenceCollector::class);
        $this->app->bind(IndependentHealthProbe::class, function ($app): ConfiguredHttpHealthProbe {
            return new ConfiguredHttpHealthProbe(
                $app->make(HealthProbeAddressResolver::class),
                $app->make(PinnedHealthProbeTransport::class),
                (array) config('deployment.sentinel_health_probe', []),
            );
        });

        $this->app->bind(AgentProcessLauncher::class, NativeAgentProcessLauncher::class);
        $this->app->bind(AgentRuntimeRepository::class, fn (): AgentRuntimeRepository => new AgentRuntimeRepository(
            rtrim((string) config('agent_dashboard.runtime_path'), '/\\'),
        ));
        $this->app->bind(AgentRunManager::class, fn ($app): AgentRunManager => new AgentRunManager(
            $app->make(AgentRuntimeRepository::class),
            $app->make(AgentProcessLauncher::class),
            (string) config('agent_dashboard.state_path'),
            config('agent_dashboard.php_binary'),
            (int) config('agent_dashboard.kept_run_logs', 10),
        ));
        $this->app->bind(AgentGraphService::class, fn ($app): AgentGraphService => new AgentGraphService(
            $app->make(AgentRuntimeRepository::class),
            $app->make(AgentRunManager::class),
            (int) config('agent_dashboard.journal_tail_bytes', 1048576),
        ));
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

        // Database Safety Hardening: fail-closed guard against a
        // schema/data-destroying Artisan command (migrate:fresh, db:wipe,
        // migrate:reset, migrate:refresh, migrate:rollback) ever running
        // against anything but an approved, disposable test database —
        // see DestructiveCommandGuard's own docblock for the exact
        // incident this exists to make impossible.
        //
        // Root-cause fix: the first version of this used a
        // CommandStarting event listener, which turned out to have a
        // real bypass — confirmed by direct testing, not assumed. Laravel
        // only dispatches CommandStarting when NOT
        // app()->runningUnitTests() (see Kernel::rerouteSymfonyCommandEvents()),
        // and app()->runningUnitTests() is simply `$app['env'] ===
        // 'testing'` — which `--env=testing` sets DIRECTLY, independent
        // of any actual .env file or DB config (see
        // EnvironmentDetector::getEnvironmentArgument()). So the exact
        // command shape of the original incident
        // (`migrate:fresh --env=testing --force`) silently skipped
        // CommandStarting entirely and the guard never ran.
        //
        // Artisan::starting() has no such gate: Illuminate\Console\
        // Application's constructor calls it unconditionally, for every
        // console invocation. Registering Guarded*Command classes here
        // overrides the framework's own migrate:fresh/reset/refresh/
        // rollback/db:wipe commands by name (Symfony Console: "if a
        // command with the same name already exists, it will be
        // overridden") — each override runs DestructiveCommandGuard
        // first and only then delegates to the real parent handle(), so
        // the destructive SQL is never reached when the guard denies.
        Artisan::starting(function (Artisan $artisan): void {
            $artisan->resolveCommands([
                GuardedFreshCommand::class,
                GuardedResetCommand::class,
                GuardedRefreshCommand::class,
                GuardedRollbackCommand::class,
                GuardedWipeCommand::class,
            ]);
        });
    }
}
