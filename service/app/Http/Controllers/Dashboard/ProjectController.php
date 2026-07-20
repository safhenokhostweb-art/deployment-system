<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Models\Server;
use App\Services\Deployment\DeploymentDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    private const DEFAULT_LARAVEL_PIPELINE = [
        ['name' => 'git pull', 'command' => 'git fetch origin {branch} && git reset --hard {commit}'],
        ['name' => 'composer install', 'command' => 'composer install --no-dev --optimize-autoloader --no-interaction'],
        ['name' => 'migrate', 'command' => '{php_binary} artisan migrate --force'],
        ['name' => 'cache clear', 'command' => '{php_binary} artisan optimize:clear && {php_binary} artisan optimize'],
        ['name' => 'asset build', 'command' => 'npm ci && npm run build'],
    ];

    public function __construct(
        private readonly DeploymentDispatcher $dispatcher,
    ) {
    }

    public function index()
    {
        $projects = Project::with('server')->latest()->get();

        return view('projects.index', compact('projects'));
    }

    public function create()
    {
        $servers = Server::where('is_active', true)->get();
        $defaultPipeline = json_encode(self::DEFAULT_LARAVEL_PIPELINE, JSON_PRETTY_PRINT);

        return view('projects.create', compact('servers', 'defaultPipeline'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateProject($request);
        $plainWebhookSecret = Str::random(40);

        $project = Project::create([
            'project_key' => $validated['project_key'],
            'name' => $validated['name'],
            'repository' => $validated['repository'],
            'branch' => $validated['branch'],
            'environment' => $validated['environment'],
            'server_id' => $validated['server_id'],
            'pipeline' => $validated['pipeline'],
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

        return redirect()->route('projects.show', $project)
            ->with('generated_webhook_secret', $plainWebhookSecret)
            ->with('status', 'Project created. Configure the GitHub webhook URL and secret shown below — the secret is shown in full only once.');
    }

    public function show(Project $project)
    {
        $project->load('server');
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

        return view('projects.show', compact('project', 'deployments', 'webhookUrl', 'maskedWebhookSecret'));
    }

    public function rotateWebhookSecret(Request $request, Project $project): RedirectResponse
    {
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
        $servers = Server::where('is_active', true)->get();
        $pipelineJson = json_encode($project->pipeline, JSON_PRETTY_PRINT);

        return view('projects.edit', compact('project', 'servers', 'pipelineJson'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $validated = $this->validateProject($request, $project);

        $project->update([
            'name' => $validated['name'],
            'repository' => $validated['repository'],
            'branch' => $validated['branch'],
            'environment' => $validated['environment'],
            'server_id' => $validated['server_id'],
            'pipeline' => $validated['pipeline'],
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

    public function deployManual(Request $request, Project $project): RedirectResponse
    {
        $validated = $request->validate([
            'commit_hash' => 'required|string|size:40|regex:/^[0-9a-f]{40}$/',
        ]);

        $deployment = $this->dispatcher->createManual($project, $validated['commit_hash'], $request->user()->email);

        return redirect()->route('deployments.show', $deployment)->with('status', 'Deployment queued.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateProject(Request $request, ?Project $project = null): array
    {
        $projectKeyRule = 'required|string|max:255|regex:/^[a-z0-9-]+$/';
        if ($project === null) {
            $projectKeyRule .= '|unique:projects,project_key';
        }

        $validated = $request->validate([
            'project_key' => $project === null ? $projectKeyRule : 'sometimes',
            'name' => 'required|string|max:255',
            'repository' => 'required|string|regex:/^[\w.-]+\/[\w.-]+$/',
            'branch' => 'required|string|max:255',
            'environment' => 'required|string|max:100',
            'server_id' => 'required|exists:servers,id',
            'pipeline' => 'required|string',
        ]);

        $pipeline = json_decode($validated['pipeline'], true);

        if (!is_array($pipeline) || $pipeline === []) {
            abort(422, 'Pipeline must be a non-empty JSON array of {name, command} steps.');
        }

        foreach ($pipeline as $step) {
            if (!is_array($step) || !isset($step['name'], $step['command'])) {
                abort(422, 'Every pipeline step needs a name and a command.');
            }
        }

        $validated['pipeline'] = $pipeline;

        if ($project !== null) {
            $validated['project_key'] = $project->project_key;
        }

        return $validated;
    }
}
