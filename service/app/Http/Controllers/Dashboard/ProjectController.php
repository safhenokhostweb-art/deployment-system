<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\Environment;
use App\Models\GitHubAccount;
use App\Models\LocalChangeDetectionJob;
use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\PublishJob;
use App\Models\Server;
use App\Models\User;
use App\Services\Deployment\DeploymentDispatcher;
use App\Services\Deployment\Recipe\RecipeValidator;
use App\Services\Deployment\TransferTraceService;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubAppClient;
use App\Services\Publish\ArtifactCommitSelector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    /**
     * Root-cause fix (production has no access to repo.packagist.org,
     * api.github.com, or the npm registry): every preset's source-
     * acquisition AND dependency/build steps are marked
     * skip_when_artifact — the agent skips them entirely (see
     * agent/agent.php's pollAndExecute()) whenever the Hub already
     * attached a matching Local-Agent-built artifact (source + vendor/ +
     * public/build/ already assembled) to this Deployment, activating
     * that instead. migrate/cache-clear/queue-restart are NOT skipped —
     * those still need to run against the newly-activated code on the
     * server. Harmless for any project that never uploads an artifact
     * (this flag is only ever consulted when job['artifact'] is
     * present) — every project not using the Local Agent artifact-
     * publish flow keeps running every one of these steps exactly as
     * before.
     */
    /** Used whenever a Project has no explicit artifact_preserve_paths — mirrors ArtifactManager::DEFAULT_PRESERVE_PATHS (agent side). */
    private const DEFAULT_PRESERVE_PATHS = ['.env', 'storage', 'public/storage'];

    private const PIPELINE_PRESET_LARAVEL = [
        // Maintenance-first ordering: `before_activation` marks the step to
        // run against the CURRENT release before an attached artifact is
        // atomically activated (the site is never exposed to a half-swapped
        // release — and with persistent storage, maintenance survives the
        // swap, so `exit maintenance mode` below is the guaranteed re-entry
        // point after activation). For a plain git-pipeline project (no
        // artifact) the flag is ignored by the agent and the step simply
        // runs first in the normal order.
        ['name' => 'maintenance mode', 'command' => '{php_binary} artisan down || true', 'before_activation' => true],
        ['name' => 'git pull', 'command' => 'git fetch origin {branch} && git reset --hard {commit}', 'skip_when_artifact' => true],
        ['name' => 'composer install', 'command' => 'composer install --no-dev --optimize-autoloader --no-interaction', 'skip_when_artifact' => true],
        ['name' => 'migrate', 'command' => '{php_binary} artisan migrate --force'],
        ['name' => 'cache clear', 'command' => '{php_binary} artisan optimize:clear && {php_binary} artisan optimize'],
        ['name' => 'runtime permissions', 'type' => 'runtime_permissions', 'command' => ''],
        ['name' => 'restart services', 'type' => 'service_reload', 'command' => ''],
        ['name' => 'asset build', 'command' => 'npm ci --include=dev && npm run build', 'skip_when_artifact' => true],
        ['name' => 'queue restart', 'command' => '{php_binary} artisan queue:restart'],
        ['name' => 'exit maintenance mode', 'command' => '{php_binary} artisan up'],
    ];

    private const PIPELINE_PRESET_STATIC = [
        ['name' => 'git pull', 'command' => 'git fetch origin {branch} && git reset --hard {commit}', 'skip_when_artifact' => true],
        ['name' => 'install', 'command' => 'npm ci --include=dev', 'skip_when_artifact' => true],
        ['name' => 'build', 'command' => 'npm run build', 'skip_when_artifact' => true],
    ];

    // Phase 4-H Task 7: a Discovery-suggested starting point for a
    // detected WordPress project — plain file sync + cache flush, no
    // wp-cli assumed installed on the target server. Same as every other
    // preset, this only fills the textarea; nothing executes until the
    // user reviews/saves it.
    private const PIPELINE_PRESET_WORDPRESS = [
        ['name' => 'git pull', 'command' => 'git fetch origin {branch} && git reset --hard {commit}', 'skip_when_artifact' => true],
        ['name' => 'file permissions', 'command' => 'chmod -R 755 {working_directory}/wp-content'],
    ];

    public function __construct(
        private readonly DeploymentDispatcher $dispatcher,
        private readonly GitHubAppClient $github,
        private readonly RecipeValidator $recipeValidator,
        private readonly TransferTraceService $transferTrace,
        private readonly ArtifactCommitSelector $artifactCommits,
    ) {
    }

    public function index()
    {
        Gate::authorize('projects.view');

        // Phase 4-G Task 8: an Admin still sees every Project (bypasses
        // membership, per ProjectPolicy::before()); every other role only
        // sees Projects they were explicitly added to. A legacy Project
        // with zero membership rows is simply absent from a non-admin's
        // list — still fully visible/manageable to any Admin.
        $user = request()->user();
        $query = Project::with('server')->latest();
        if (!$user->isAdmin()) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }
        $projects = $query->get();

        return view('projects.index', compact('projects'));
    }

    public function create(Request $request)
    {
        Gate::authorize('projects.create');

        $servers = Server::where('is_active', true)->get();
        $githubAccounts = GitHubAccount::where('status', GitHubAccount::STATUS_CONNECTED)->get();
        $pipelinePresets = [
            'laravel' => json_encode(self::PIPELINE_PRESET_LARAVEL, JSON_PRETTY_PRINT),
            'wordpress' => json_encode(self::PIPELINE_PRESET_WORDPRESS, JSON_PRETTY_PRINT),
            'static' => json_encode(self::PIPELINE_PRESET_STATIC, JSON_PRETTY_PRINT),
            'custom' => json_encode([['name' => 'example step', 'command' => 'echo hello']], JSON_PRETTY_PRINT),
        ];

        // Phase 4-H Task 8 / Final Phase Task 2-3: optional pre-fill from a
        // Local Discovery result ("Use this project" link on servers.show)
        // — every key is just an existing form field's starting value (or,
        // for path/commit/framework_version, informational display only —
        // Project has no such columns, by the same "never centralize a
        // server's local filesystem layout" principle DeploymentTarget
        // already follows), so a plain GET with none of these (every
        // pre-4-H caller, including every existing test) sees byte-for-byte
        // the same form as before this phase.
        $prefill = [
            'name' => $request->query('discovered_name'),
            'repository' => $request->query('discovered_repository'),
            'branch' => $request->query('discovered_branch'),
            'type' => $request->query('discovered_type'),
            'path' => $request->query('discovered_path'),
            'commit' => $request->query('discovered_commit'),
            'framework_version' => $request->query('discovered_framework_version'),
        ];
        $presetKey = in_array($prefill['type'], ['laravel', 'wordpress', 'static'], true) ? $prefill['type'] : 'laravel';
        $defaultPipeline = $pipelinePresets[$presetKey];

        return view('projects.create', compact('servers', 'githubAccounts', 'pipelinePresets', 'defaultPipeline', 'prefill', 'presetKey'));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('projects.create');

        $validated = $this->validateProject($request);
        $plainWebhookSecret = Str::random(40);
        $projectKey = $validated['project_key'] ?? $this->generateUniqueProjectKey($validated['name']);

        $project = Project::create([
            'project_key' => $projectKey,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'repository' => $validated['repository'],
            'branch' => $validated['branch'],
            'environment' => $validated['environment'],
            'server_id' => $validated['server_id'],
            'pipeline' => $validated['pipeline'],
            'deployment_recipe' => $validated['deployment_recipe'],
            'http_health_check' => $validated['http_health_check'],
            'application_root' => $validated['application_root'],
            'auto_deploy' => $request->boolean('auto_deploy'),
            'github_webhook_secret' => $plainWebhookSecret,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'project.created',
            subjectType: Project::class,
            subjectId: $project->id,
        );

        // Phase 4-C.5's Environment/DeploymentTarget layer, offered here as
        // an in-wizard convenience — entirely opt-in via environment_name.
        // A direct POST without it (any pre-4-E caller, including every
        // existing test) creates exactly the same legacy-only Project row
        // as before, byte for byte.
        if (!empty($validated['environment_name'])) {
            $this->createEnvironmentAndTarget($project, $validated['environment_name'], $validated['server_id']);
        }

        $webhookAutoConfigured = false;
        if (!empty($validated['github_account_id'])) {
            $webhookAutoConfigured = $this->tryAutoConfigureWebhook(
                $request,
                $project,
                (int) $validated['github_account_id'],
                $plainWebhookSecret,
            );
        }

        return redirect()->route('projects.show', $project)
            ->with('generated_webhook_secret', $plainWebhookSecret)
            ->with('webhook_auto_configured', $webhookAutoConfigured)
            ->with('status', 'Project created. Configure the GitHub webhook URL and secret shown below — the secret is shown in full only once.');
    }

    public function show(Project $project)
    {
        Gate::authorize('projects.view');
        Gate::authorize('view', $project);

        $project->load([
            'server',
            'githubAccount',
            'environments.deploymentTargets.server' => fn ($query) => $query->select(['id', 'name', 'last_seen_at']),
            'environments.deploymentTargets.agent' => fn ($query) => $query->select(['id', 'name', 'public_id', 'status', 'agent_version', 'last_seen_at']),
            'members.user' => fn ($query) => $query->select(['id', 'name', 'email']),
            // Phase 5: One-Click Publish — only approved local agents are
            // ever offered as a publish target from this page.
            'localProjectMappings.localAgent',
            'authoritativeBuildAgent',
        ]);
        // Phase 4-L Task 2: candidates for the "connect GitHub" action
        // shown when this project has no github_account_id yet — never
        // re-asks for repository/branch, only which connected account.
        $connectableGithubAccounts = $project->hasGithubWebhookConfigured()
            ? collect()
            : GitHubAccount::where('status', GitHubAccount::STATUS_CONNECTED)->get();
        // Phase 4-G: candidates for the "add member" picker — Admin-only
        // section (see the view), so only fetched when relevant.
        $availableUsers = Gate::allows('projects.manage-members')
            ? User::whereNotIn('id', $project->members->pluck('user_id'))->get(['id', 'name', 'email'])
            : collect();
        $deployments = $project->deployments()->with('server')->latest('id')->take(30)->get();
        $webhookUrl = route('webhooks.github', $project->project_key);
        // Task 6 (Phase 3-D): unlike the "shown once" secret above, the
        // webhook secret genuinely needs to be re-checkable later (e.g.
        // reconfiguring GitHub after it was deleted there) — but it must
        // never be redisplayed in full on every page load. Masked here;
        // "Rotate" issues a fresh one shown once, mirroring the Server/
        // Agent credential pattern.
        $secret = $project->github_webhook_secret;
        $maskedWebhookSecret = substr($secret, 0, 4) . str_repeat('•', max(strlen($secret) - 8, 0)) . substr($secret, -4);
        // Phase 4-C.5: candidates for the "add deployment target" server
        // picker — same active-servers-only rule projects.create already
        // uses. Only id/name/last_seen_at are selected, and only each
        // server's approved Agent (for the "online/offline" hint) is
        // eager-loaded — no credential/token/secret column is ever
        // queried for this list.
        $availableServers = Server::where('is_active', true)
            ->with(['agents' => fn ($query) => $query->where('status', Agent::STATUS_APPROVED)
                ->select(['id', 'server_id', 'name', 'public_id', 'last_seen_at'])])
            ->get(['id', 'name', 'last_seen_at']);

        // Phase 5.2: the Watch Status card shows at most one open
        // detection — if two agents are somehow both mapped and both
        // have open detections, the most recently changed one wins;
        // there's no need to show more than a single "pending changes"
        // signal on this page.
        $openChangeDetection = $project->changeDetectionJobs()
            ->whereIn('status', [LocalChangeDetectionJob::STATUS_DETECTED, LocalChangeDetectionJob::STATUS_WAITING_APPROVAL])
            ->latest('detected_at')
            ->first();

        $transferTrace = $this->transferTrace->forProject($project);

        $artifactCommitOptions = $project->requires_complete_artifact
            ? $this->artifactCommits->options($project, $this->githubHeadsForArtifactSelector($project))
            : [];

        return view('projects.show', compact('project', 'deployments', 'webhookUrl', 'maskedWebhookSecret', 'availableServers', 'availableUsers', 'connectableGithubAccounts', 'openChangeDetection', 'transferTrace', 'artifactCommitOptions'));
    }

    /** @return array<string, string|null> */
    private function githubHeadsForArtifactSelector(Project $project): array
    {
        if (! $project->requires_complete_artifact || ! $project->githubAccount?->isConnected() || ! $this->github->isConfigured()) {
            return [];
        }

        $repositoryParts = explode('/', trim($project->repository, '/'));
        if (count($repositoryParts) !== 2) {
            return [];
        }

        try {
            $token = $this->github->createInstallationToken($project->githubAccount->installation_id)['token'];
            $heads = [];
            foreach ($project->localProjectMappings->map->effectiveBranch()->unique() as $branch) {
                $heads[$branch] = $this->github->getBranchHead($token, $repositoryParts[0], $repositoryParts[1], $branch);
            }

            return $heads;
        } catch (GitHubApiException) {
            return [];
        }
    }

    public function rotateWebhookSecret(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.update');
        Gate::authorize('update', $project);

        $plainWebhookSecret = Str::random(40);

        $project->update(['github_webhook_secret' => $plainWebhookSecret]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'project.webhook_secret_rotated',
            subjectType: Project::class,
            subjectId: $project->id,
        );

        return redirect()->route('projects.show', $project)
            ->with('generated_webhook_secret', $plainWebhookSecret)
            ->with('status', 'Webhook secret rotated. Update it in GitHub now — the old secret stops working immediately.');
    }

    public function edit(Project $project)
    {
        Gate::authorize('projects.update');
        Gate::authorize('update', $project);

        $servers = Server::where('is_active', true)->get();
        $pipelineJson = json_encode($project->pipeline, JSON_PRETTY_PRINT);
        $project->load('localProjectMappings.localAgent');
        $buildAgentCandidates = $project->localProjectMappings
            ->map->localAgent
            ->unique('id')
            ->values();

        return view('projects.edit', compact('project', 'servers', 'pipelineJson', 'buildAgentCandidates'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.update');
        Gate::authorize('update', $project);

        // The edit UI intentionally uses a newline-separated textarea while
        // the canonical persisted contract is an array of project-relative
        // paths. API callers can submit the array directly.
        if ($request->has('artifact_preserve_paths_text')) {
            $request->merge([
                'artifact_preserve_paths' => array_values(array_filter(
                    preg_split('/\R/', (string) $request->input('artifact_preserve_paths_text')) ?: [],
                    static fn (string $path): bool => trim($path) !== '',
                )),
            ]);
        }
        if ($request->has('artifact_persistent_paths_text')) {
            $request->merge([
                'artifact_persistent_paths' => array_values(array_filter(
                    preg_split('/\R/', (string) $request->input('artifact_persistent_paths_text')) ?: [],
                    static fn (string $path): bool => trim($path) !== '',
                )),
            ]);
        }

        $validated = $this->validateProject($request, $project);

        $project->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? $project->description,
            'repository' => $validated['repository'],
            'branch' => $validated['branch'],
            'environment' => $validated['environment'],
            'server_id' => $validated['server_id'],
            'pipeline' => $validated['pipeline'],
            'deployment_recipe' => $validated['deployment_recipe'],
            'http_health_check' => $validated['http_health_check'],
            'authoritative_build_local_agent_id' => array_key_exists('authoritative_build_local_agent_id', $validated)
                ? $validated['authoritative_build_local_agent_id']
                : $project->authoritative_build_local_agent_id,
            'artifact_preserve_paths' => $validated['artifact_preserve_paths'] ?? $project->artifact_preserve_paths,
            'artifact_persistent_paths' => $validated['artifact_persistent_paths'] ?? $project->artifact_persistent_paths,
            'artifact_persistent_root' => $validated['artifact_persistent_root'] ?? $project->artifact_persistent_root,
            'requires_complete_artifact' => $validated['requires_complete_artifact'] ?? $project->requires_complete_artifact,
            'application_root' => $validated['application_root'],
            'auto_deploy' => $request->boolean('auto_deploy'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'project.updated',
            subjectType: Project::class,
            subjectId: $project->id,
        );

        return redirect()->route('projects.show', $project)->with('status', 'Project updated.');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.delete');
        Gate::authorize('delete', $project);

        // deployments.project_id and environments.project_id both
        // ->restrictOnDelete() at the schema level — this check exists to
        // turn that into a friendly, translated redirect instead of a raw
        // QueryException, same convention as ServerController::destroy()
        // and DeploymentTargetController::destroy(). project_members
        // cascadeOnDelete and needs no such check.
        abort_if(
            $project->deployments()->exists() || $project->environments()->exists(),
            409,
            __('messages.errors.project_has_dependents')
        );

        $name = $project->name;

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'project.deleted',
            subjectType: Project::class,
            subjectId: $project->id,
        );

        $project->delete();

        return redirect()->route('projects.index')
            ->with('status', __('messages.flash.project_deleted', ['name' => $name]));
    }

    public function deployManual(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('deployments.create');
        Gate::authorize('deploy', $project);

        $validated = $request->validate([
            'commit_hash' => 'required|string|size:40|regex:/^[0-9a-f]{40}$/',
            'release_label' => 'nullable|string|max:100',
            'release_notes' => 'nullable|string|max:2000',
        ]);

        $result = $this->dispatcher->createManual($project, $validated['commit_hash'], $request->user()->email, [
            'release_label' => $validated['release_label'] ?? null,
            'release_notes' => $validated['release_notes'] ?? null,
        ]);

        if ($result instanceof PublishJob) {
            return redirect()->route('projects.publish.show', [$project, $result])
                ->with('status', 'Artifact build queued. The deployment will be created after the artifact is available.');
        }

        return redirect()->route('deployments.show', $result)->with('status', 'Deployment queued.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProject(Request $request, ?Project $project = null): array
    {
        // Phase 4-E Task 7: manual entry is still fully supported (unique,
        // regex-checked exactly as before) — it's simply no longer
        // required, since store() auto-generates one from the name when
        // absent. A caller that never sends project_key at all (every
        // pre-4-E integration) is completely unaffected.
        $projectKeyRule = 'nullable|string|max:255|regex:/^[a-z0-9-]+$/';
        if ($project === null) {
            $projectKeyRule .= '|unique:projects,project_key';
        }

        $validated = $request->validate([
            'project_key' => $project === null ? $projectKeyRule : 'sometimes',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'repository' => 'required|string|regex:/^[\w.-]+\/[\w.-]+$/',
            'branch' => 'required|string|max:255',
            'environment' => 'required|string|max:100',
            'server_id' => 'required|exists:servers,id',
            'pipeline' => 'required|string',
            'deployment_recipe' => 'nullable|string',
            'authoritative_build_local_agent_id' => 'nullable|exists:local_agents,id',
            'http_health_enabled' => 'sometimes|boolean',
            'http_health_base_url' => 'nullable|url:http,https|max:2048',
            'http_health_path' => 'nullable|string|max:1024',
            'http_health_expected_status' => 'nullable|integer|min:100|max:599',
            'http_health_timeout_seconds' => 'nullable|integer|min:1|max:120',
            'http_health_retries' => 'nullable|integer|min:0|max:10',
            'http_health_retry_interval_seconds' => 'nullable|integer|min:0|max:300',
            'http_health_allowed_redirects' => 'sometimes|boolean',
            'http_health_expected_content' => 'nullable|string|max:500',
            'artifact_preserve_paths' => 'sometimes|nullable|array|max:20',
            'artifact_preserve_paths.*' => 'required|string|max:255',
            'artifact_persistent_paths' => 'sometimes|nullable|array|max:20',
            'artifact_persistent_paths.*' => 'required|string|max:255',
            'artifact_persistent_root' => 'nullable|string|max:255',
            'requires_complete_artifact' => 'sometimes|boolean',
            // Keep this as an array rule. The expression contains `|`, which
            // Laravel treats as a rule separator when it is embedded in a
            // pipe-delimited rule string, producing an invalid preg_match()
            // pattern for every non-empty application_root.
            'application_root' => ['nullable', 'string', 'max:255', 'regex:/^(\.|[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*)*)$/'],
            'environment_name' => 'nullable|string|max:255',
            'github_account_id' => 'nullable|exists:github_accounts,id',
        ]);

        $pipeline = json_decode($validated['pipeline'], true);

        if (!is_array($pipeline) || $pipeline === []) {
            abort(422, 'Pipeline must be a non-empty JSON array of {name, command} steps.');
        }

        foreach ($pipeline as $step) {
            if (!is_array($step)) {
                abort(422, 'Every pipeline step needs a name and a command.');
            }
            $typedOperation = $step['type'] ?? null;
            if (!isset($step['name']) || (!isset($step['command']) && !in_array($typedOperation, ['runtime_permissions', 'service_reload'], true))) {
                abort(422, 'Every pipeline step needs a name and a command.');
            }
            if ($typedOperation !== null && (!in_array($typedOperation, ['runtime_permissions', 'service_reload'], true) || ($step['command'] ?? '') !== '')) {
                abort(422, 'Pipeline typed operations must be approved and cannot include a command.');
            }
        }

        $validated['pipeline'] = $pipeline;

        // Optional and additive: an empty/omitted textarea leaves
        // deployment_recipe null, so this Project keeps deploying via
        // `pipeline` exactly as before — see Project::usesDeploymentRecipe().
        // The existing edit form intentionally does not expose a recipe
        // editor. Its absence must therefore preserve a configured recipe,
        // rather than silently replacing it with null while saving another
        // Project setting such as application_root.
        if (! $request->has('deployment_recipe')) {
            $validated['deployment_recipe'] = $project?->deployment_recipe;
        } else {
            $rawRecipe = trim((string) $validated['deployment_recipe']);
            $validated['deployment_recipe'] = $rawRecipe === ''
                ? null
                : $this->recipeValidator->validate(json_decode($rawRecipe, true));
        }
        $validated['application_root'] = $validated['application_root'] ?? $project?->application_root ?? '.';
        $validated['http_health_check'] = $this->normalizeHttpHealthCheck($request, $validated, $project);

        if (!empty($validated['authoritative_build_local_agent_id'])) {
            $agentId = (int) $validated['authoritative_build_local_agent_id'];
            $agent = LocalAgent::query()->findOrFail($agentId);
            if (!$agent->canBuildProductionArtifacts()) {
                throw ValidationException::withMessages([
                    'authoritative_build_local_agent_id' => 'The authoritative build Agent must be approved and have the production_build role.',
                ]);
            }
            if ($project === null || !$project->localProjectMappings()->where('local_agent_id', $agentId)->exists()) {
                throw ValidationException::withMessages([
                    'authoritative_build_local_agent_id' => 'The authoritative build Agent must have a mapping for this project.',
                ]);
            }
        }
        if (array_key_exists('artifact_preserve_paths', $validated)) {
            $validated['artifact_preserve_paths'] = $this->normalizeArtifactPreservePaths($validated['artifact_preserve_paths']);
        }
        if (array_key_exists('artifact_persistent_paths', $validated)) {
            $validated['artifact_persistent_paths'] = $this->normalizeArtifactPersistentPaths($validated['artifact_persistent_paths']);
        }

        // Mirrors the agent's fail-closed persistent contract
        // (ArtifactManager::preparePersistentReferences) so an ambiguous or
        // incomplete configuration is rejected here, before any deploy.
        if (($validated['artifact_persistent_paths'] ?? null) !== null && $validated['artifact_persistent_paths'] !== []) {
            $persistent = $validated['artifact_persistent_paths'];
            $preserve = $validated['artifact_preserve_paths'] ?? null;
            // The agent computes the effective preserve list the same way:
            // an empty/absent preserve list means the built-in defaults.
            $effectivePreserve = ($preserve === null || $preserve === []) ? self::DEFAULT_PRESERVE_PATHS : $preserve;
            $overlap = self::persistentOverlapError($effectivePreserve, $persistent);
            if ($overlap !== null) {
                throw ValidationException::withMessages(['artifact_persistent_paths' => $overlap]);
            }
            if (trim((string) ($validated['artifact_persistent_root'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'artifact_persistent_root' => 'artifact_persistent_root is required when artifact_persistent_paths are set.',
                ]);
            }
        }

        if ($project !== null) {
            $validated['project_key'] = $project->project_key;
        }

        return $validated;
    }

    /** @param array<string, mixed> $validated @return array<string, mixed>|null */
    private function normalizeHttpHealthCheck(Request $request, array $validated, ?Project $project): ?array
    {
        if (!$request->has('http_health_enabled')) {
            return $project?->http_health_check;
        }

        if (!$request->boolean('http_health_enabled')) {
            return null;
        }

        $baseUrl = rtrim((string) ($validated['http_health_base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            throw ValidationException::withMessages(['http_health_base_url' => 'A base URL is required when HTTP health verification is enabled.']);
        }

        $path = trim((string) ($validated['http_health_path'] ?? '/'));
        $path = '/' . ltrim($path === '' ? '/' : $path, '/');

        return [
            'enabled' => true,
            'base_url' => $baseUrl,
            'path' => $path,
            'url' => $baseUrl . ($path === '/' ? '/' : $path),
            'expected_status' => (int) ($validated['http_health_expected_status'] ?? 200),
            'timeout_seconds' => (int) ($validated['http_health_timeout_seconds'] ?? 15),
            'retries' => (int) ($validated['http_health_retries'] ?? 3),
            'retry_interval_seconds' => (int) ($validated['http_health_retry_interval_seconds'] ?? 5),
            'allowed_redirects' => $request->boolean('http_health_allowed_redirects'),
            'expected_content' => ($validated['http_health_expected_content'] ?? null) ?: null,
        ];
    }

    /** @param array<int, string>|null $paths @return array<int, string>|null */
    private function normalizeArtifactPreservePaths(?array $paths): ?array
    {
        return $this->normalizeArtifactRelativePaths($paths, 'artifact_preserve_paths');
    }

    /** @param array<int, string>|null $paths @return array<int, string>|null */
    private function normalizeArtifactPersistentPaths(?array $paths): ?array
    {
        return $this->normalizeArtifactRelativePaths($paths, 'artifact_persistent_paths');
    }

    /** @param array<int, string>|null $paths @return array<int, string>|null */
    private function normalizeArtifactRelativePaths(?array $paths, string $field): ?array
    {
        if ($paths === null) {
            return null;
        }

        $normalized = [];
        foreach ($paths as $path) {
            $path = rtrim(str_replace('\\', '/', trim($path)), '/');
            if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1
                || preg_match('#(^|/)\.\.(/|$)#', $path) === 1 || str_contains($path, "\0")) {
                throw ValidationException::withMessages([
                    $field => 'Paths must be relative paths without traversal segments.',
                ]);
            }
            if (in_array($path, $normalized, true)) {
                throw ValidationException::withMessages([
                    $field => 'Paths must not contain duplicates.',
                ]);
            }
            $normalized[] = $path;
        }

        return $normalized;
    }

    /**
     * Mirrors ArtifactManager::persistentOverlapError() so the Hub rejects an
     * ambiguous preserve+persistent contract before a deploy ever fails on the
     * server: an exact duplicate, or an ancestor/descendant relationship,
     * between a persistent path and an effective preserve path is ambiguous
     * (the same project path can never be both copied and symlinked).
     *
     * @param array<int, string> $preservePaths
     * @param array<int, string> $persistentPaths
     */
    private static function persistentOverlapError(array $preservePaths, array $persistentPaths): ?string
    {
        foreach ($persistentPaths as $persistent) {
            $p = rtrim(str_replace('\\', '/', trim($persistent)), '/');
            foreach ($preservePaths as $preserve) {
                $s = rtrim(str_replace('\\', '/', trim($preserve)), '/');
                if ($s === '' || $p === '') {
                    continue;
                }
                if ($p === $s) {
                    return "Persistent path '{$p}' exactly overlaps preserve path '{$s}' — remove it from one contract.";
                }
                if (str_starts_with($p . '/', $s . '/')) {
                    return "Persistent path '{$p}' is inside preserve path '{$s}' — overlapping contracts are ambiguous.";
                }
                if (str_starts_with($s . '/', $p . '/')) {
                    return "Preserve path '{$s}' is inside persistent path '{$p}' — overlapping contracts are ambiguous.";
                }
            }
        }

        return null;
    }

    /**
     * Task 7: "SaaS Platform" -> "saas-platform", with a numeric suffix
     * only if that slug is already taken. Never touches an existing
     * project's key — only used for a brand-new Project with no manual
     * project_key supplied.
     */
    private function generateUniqueProjectKey(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'project';
        }

        $candidate = $base;
        $suffix = 2;
        while (Project::where('project_key', $candidate)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function createEnvironmentAndTarget(Project $project, string $environmentName, int $serverId): void
    {
        $environment = $project->environments()->create([
            'name' => $environmentName,
            'key' => Str::slug($environmentName) ?: 'production',
            'status' => Environment::STATUS_ACTIVE,
        ]);

        $server = Server::findOrFail($serverId);

        $environment->deploymentTargets()->create([
            'server_id' => $server->id,
            'agent_id' => $this->soleApprovedAgentFor($server),
            'name' => $server->name,
            'is_active' => true,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: null,
            action: 'environment.created',
            subjectType: Environment::class,
            subjectId: $environment->id,
            metadata: ['project_id' => $project->id, 'source' => 'project_wizard'],
        );
    }

    private function soleApprovedAgentFor(Server $server): ?int
    {
        $approvedAgentIds = Agent::where('server_id', $server->id)
            ->where('status', Agent::STATUS_APPROVED)
            ->pluck('id');

        return $approvedAgentIds->count() === 1 ? $approvedAgentIds->first() : null;
    }

    /**
     * Task 8: never lets a GitHub API hiccup block project creation —
     * the webhook URL/secret are already generated and shown either way
     * (see the credentials card on projects.show), so a failure here just
     * means the operator configures it in GitHub's UI by hand, exactly
     * like every project created before this phase already had to.
     */
    private function tryAutoConfigureWebhook(Request $request, Project $project, int $githubAccountId, string $webhookSecret): bool
    {
        $account = GitHubAccount::find($githubAccountId);
        if ($account === null || !$account->isConnected()) {
            return false;
        }

        [$owner, $repo] = array_pad(explode('/', $project->repository, 2), 2, null);
        if ($owner === null || $repo === null) {
            return false;
        }

        try {
            $token = $this->github->createInstallationToken($account->installation_id);
            $this->github->createWebhook(
                $token['token'],
                $owner,
                $repo,
                route('webhooks.github', $project->project_key),
                $webhookSecret,
            );

            // Phase 4-L: the only durable record that this project's
            // webhook is actually configured — without it, the GitHub
            // Setup Wizard has no way to distinguish "already connected"
            // from "never tried" and would either nag about an already-
            // configured project or silently re-create a duplicate
            // webhook on GitHub.
            $project->update(['github_account_id' => $account->id]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $request->user()->email,
                action: 'project.github_webhook_auto_configured',
                subjectType: Project::class,
                subjectId: $project->id,
                metadata: ['github_account_id' => $account->id],
            );

            return true;
        } catch (GitHubApiException $e) {
            report($e);

            return false;
        }
    }

    /**
     * Phase 4-L Task 2: the retroactive counterpart to store()'s
     * tryAutoConfigureWebhook() call — for a Project created before any
     * GitHub account existed (or before one was connected), this is the
     * "connect this project's webhook now" action the GitHub Setup
     * Wizard offers, reusing the exact same webhook-creation logic and
     * the project's own already-generated webhook secret (no new
     * secret, no re-entry of repository/branch — both already known).
     */
    public function connectGithub(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('projects.update');
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'github_account_id' => 'required|exists:github_accounts,id',
        ]);

        $configured = $this->tryAutoConfigureWebhook(
            $request,
            $project,
            (int) $validated['github_account_id'],
            $project->github_webhook_secret,
        );

        return redirect()->route('projects.show', $project)->with('status', $configured
            ? __('messages.flash.github_webhook_connected', ['name' => $project->name])
            : __('messages.errors.github_connection_failed'));
    }
}
