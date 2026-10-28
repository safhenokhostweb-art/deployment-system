<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Services\AgentDashboard\AgentGraphService;
use App\Services\AgentDashboard\AgentRunManager;
use App\Services\AgentDashboard\AgentRuntimeRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Visual Agent Management Dashboard: a canvas over the workspace agent
 * runtime (agents/agents.json, agents/var/bus/journal.jsonl, bin/agent).
 *
 * Reading is workspace_agents.view. Everything that writes agents.json or
 * starts/stops a process is workspace_agents.manage (Admin only), and is
 * audit-logged: toggling an agent changes what the pre-push readiness gate
 * can evaluate, and run starts a process on the Hub host.
 */
class AgentDashboardController extends Controller
{
    public function __construct(
        private readonly AgentRuntimeRepository $runtime,
        private readonly AgentGraphService $graph,
        private readonly AgentRunManager $runs,
    ) {
    }

    public function index()
    {
        Gate::authorize('workspace_agents.view');

        return view('agent-dashboard.index', [
            'canManage' => Gate::allows('workspace_agents.manage'),
        ]);
    }

    public function graph(): JsonResponse
    {
        Gate::authorize('workspace_agents.view');
        $this->ensureRuntime();

        return response()->json($this->graph->graph());
    }

    public function save(Request $request): JsonResponse
    {
        Gate::authorize('workspace_agents.manage');
        $this->ensureRuntime();

        $input = $request->validate([
            'nodes' => ['sometimes', 'array', 'max:200'],
            'nodes.*.id' => ['required', 'string', 'max:64'],
            'nodes.*.enabled' => ['sometimes', 'boolean'],
            'nodes.*.position' => ['sometimes', 'array'],
            'nodes.*.position.x' => ['required_with:nodes.*.position', 'numeric', 'between:-100000,100000'],
            'nodes.*.position.y' => ['required_with:nodes.*.position', 'numeric', 'between:-100000,100000'],
            'edges' => ['sometimes', 'array', 'max:500'],
            'edges.*.source' => ['required', 'string', 'max:64'],
            'edges.*.target' => ['required', 'string', 'max:64'],
            'edges.*.label' => ['nullable', 'string', 'max:120'],
        ]);

        $graph = $this->graph->save($input);
        $this->audit($request, 'workspace_agent.graph_saved', [
            'edges' => array_map(static fn (array $e): string => $e['id'], $graph['edges']),
            'enabled' => array_column(array_filter($graph['nodes'], static fn (array $n): bool => $n['type'] === 'agent'), 'enabled', 'id'),
        ]);

        return response()->json($graph);
    }

    public function toggle(Request $request, string $id): JsonResponse
    {
        Gate::authorize('workspace_agents.manage');
        $this->ensureRuntime();
        $request->validate(['enabled' => ['sometimes', 'boolean']]);

        $enabled = $this->graph->toggle($id, $request->has('enabled') ? $request->boolean('enabled') : null);
        $this->audit($request, $enabled ? 'workspace_agent.enabled' : 'workspace_agent.disabled', ['agent' => $id]);

        return response()->json(['id' => $id, 'enabled' => $enabled]);
    }

    public function run(Request $request, string $id): JsonResponse
    {
        Gate::authorize('workspace_agents.manage');
        $this->ensureRuntime();

        $agent = $this->graph->agent($id);
        $input = $request->validate(['task' => ['sometimes', 'string', 'regex:/^[a-z][a-z0-9-]*(\.[a-z][a-z0-9-]*)+$/']]);
        $task = $input['task'] ?? ($agent['tasks'][0] ?? null);

        abort_if(($agent['enabled'] ?? true) !== true, 409, "Agent '{$id}' is disabled; enable it before running.");
        abort_if($task === null, 422, "Agent '{$id}' declares no task type to run.");
        abort_unless(in_array($task, $agent['tasks'], true), 422, "Agent '{$id}' does not handle '{$task}'.");
        abort_if($this->runs->isRunning($id), 409, "Agent '{$id}' is already running.");

        try {
            $run = $this->runs->start($id, $task);
        } catch (RuntimeException $exception) {
            abort(500, 'Could not start the agent: '.$exception->getMessage());
        }
        $this->audit($request, 'workspace_agent.run_started', ['agent' => $id, 'task' => $task, 'pid' => $run['pid']]);

        return response()->json(['id' => $id, 'run' => $this->runs->describe($id)], 202);
    }

    public function stop(Request $request, string $id): JsonResponse
    {
        Gate::authorize('workspace_agents.manage');
        $this->ensureRuntime();
        $this->graph->agent($id);

        $run = $this->runs->stop($id);
        abort_if($run === null, 409, "Agent '{$id}' is not running.");
        $this->audit($request, 'workspace_agent.run_stopped', ['agent' => $id, 'pid' => $run['pid']]);

        return response()->json(['id' => $id, 'run' => $this->runs->describe($id)]);
    }

    public function logs(string $id): JsonResponse
    {
        Gate::authorize('workspace_agents.view');
        $this->ensureRuntime();
        $agent = $this->graph->agent($id);

        $journal = $this->runtime->journal((int) config('agent_dashboard.journal_tail_bytes'));
        $artifact = $this->runtime->latestArtifact($journal, $id, (int) config('agent_dashboard.artifact_preview_bytes'));
        $findings = $artifact['findings'] ?? [];
        unset($artifact['findings']);
        $rank = ['blocker' => 0, 'warning' => 1, 'info' => 2];
        usort($findings, static fn (array $a, array $b): int => ($rank[$a['severity'] ?? 'info'] ?? 3) <=> ($rank[$b['severity'] ?? 'info'] ?? 3));

        return response()->json([
            'id' => $id,
            'enabled' => ($agent['enabled'] ?? true) === true,
            'tasks' => $agent['tasks'],
            'run' => $this->runs->describe($id, (int) config('agent_dashboard.log_tail_bytes')),
            'last_outcome' => $this->runtime->lastOutcome($journal, $id),
            'journal' => $this->runtime->journalFor($journal, $id, (int) config('agent_dashboard.logs_journal_limit')),
            'findings' => $findings,
            'artifact' => $artifact,
        ]);
    }

    private function ensureRuntime(): void
    {
        abort_unless($this->runtime->isAvailable(), 503, 'Workspace agent runtime not found at '.$this->runtime->runtimePath().'.');
    }

    private function audit(Request $request, string $action, array $metadata): void
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: (string) $request->user()->id,
            action: $action,
            metadata: $metadata,
            ipAddress: $request->ip(),
        );
    }
}
