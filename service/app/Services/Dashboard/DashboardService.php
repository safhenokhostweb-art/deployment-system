<?php

namespace App\Services\Dashboard;

use App\Models\Agent;
use App\Models\AgentJob;
use App\Models\Deployment;
use App\Models\DeploymentTask;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Support\Collection;

/**
 * Read-only projections for the operations-center dashboard
 * (docs/PHASE_4C_DASHBOARD_IMPLEMENTATION_REPORT.md). Every method is a
 * query or a grouping of existing model state — nothing here writes, and
 * nothing here is a new business rule. Kept out of DashboardController so
 * the query shapes stay independently testable (see the N+1 guard test).
 *
 * "Stalled"/"long-running" detection deliberately mirrors
 * App\Console\Commands\DetectStalledDeployments's own query shape (same
 * config threshold, same legacy-vs-new-domain split) but never mutates
 * anything — this is a read-only preview of what that command would act
 * on, not a replacement for it.
 */
class DashboardService
{
    /**
     * Early-warning threshold for "long running", deliberately shorter
     * than config('deployment.stalled_deployment_timeout_minutes') (30 by
     * default) so an operator sees a slow deployment before the scheduled
     * command would fail it as stalled. Not exposed as config — it's a
     * presentation cutoff, not a behavioral one.
     */
    private const LONG_RUNNING_MINUTES = 10;

    private const RECENT_DEPLOYMENTS_LIMIT = 10;

    private const AGENT_WIDGET_LIMIT = 20;

    private const PROBLEM_LIST_LIMIT = 10;

    public function summaryCards(): array
    {
        return [
            'servers' => $this->serverCounts(),
            'agents' => $this->agentCounts(),
            'projects' => $this->projectCounts(),
            'deployments' => $this->deploymentCounts(),
        ];
    }

    public function healthOverview(): array
    {
        return [
            'problemAgents' => $this->problemAgents(),
            'problemDeployments' => $this->problemDeployments(),
            'recentErrors' => $this->recentErrors(),
        ];
    }

    /**
     * @return Collection<int, Deployment>
     */
    public function recentDeployments(int $limit = self::RECENT_DEPLOYMENTS_LIMIT): Collection
    {
        return Deployment::with(['project', 'server', 'tasks.deploymentTarget.environment'])
            ->latest('id')
            ->take($limit)
            ->get();
    }

    /**
     * Phase 4-I Task 9: distinct from recentDeployments() above — succeeded
     * only, so this reads as "what's actually live now" rather than every
     * attempt regardless of outcome. Deployment::displayLabel() already
     * falls back to a short commit hash, so this looks sensible even for
     * every deployment that predates the release-label column (Task 7).
     *
     * @return Collection<int, Deployment>
     */
    public function recentReleases(int $limit = self::RECENT_DEPLOYMENTS_LIMIT): Collection
    {
        return Deployment::with('project')
            ->where('status', Deployment::STATUS_SUCCEEDED)
            ->latest('finished_at')
            ->take($limit)
            ->get();
    }

    /**
     * @return Collection<int, Agent>
     */
    public function agentStatusWidget(int $limit = self::AGENT_WIDGET_LIMIT): Collection
    {
        return Agent::with('server')
            ->orderByRaw('last_seen_at IS NULL, last_seen_at DESC')
            ->take($limit)
            ->get();
    }

    /**
     * @return Collection<int, array{server: Server, type: string, connectedAgent: ?Agent, isOnline: bool}>
     */
    public function serverOverviewWidget(): Collection
    {
        return Server::withCount('agents')
            ->with(['agents' => fn ($query) => $query->where('status', Agent::STATUS_APPROVED)->latest('last_seen_at')->limit(1)])
            ->get()
            ->map(fn (Server $server) => [
                'server' => $server,
                // No dedicated "server type" column exists (servers table
                // is credentials + name/description only — confirmed in
                // the Task 1 audit). Read-only-derived from whether any
                // Agent has ever been registered for it, which is exactly
                // the Phase 3-B "Old Agent vs New Agent" distinction the
                // rest of the app already treats as meaningful.
                'type' => $server->agents_count > 0 ? 'agent_based' : 'legacy',
                'connectedAgent' => $server->agents->first(),
                'isOnline' => $server->agents->contains(fn (Agent $agent) => $agent->isOnline())
                    || ($server->last_seen_at !== null && $server->last_seen_at->greaterThan(now()->subSeconds((int) config('deployment.server_offline_threshold_seconds')))),
            ]);
    }

    private function serverCounts(): array
    {
        $servers = Server::with(['agents:id,server_id,status,last_seen_at'])->get(['id', 'last_seen_at']);
        $online = $servers->filter(fn (Server $server) => $server->isOnline())->count();

        return [
            'total' => $servers->count(),
            'online' => $online,
            'offline' => $servers->count() - $online,
        ];
    }

    private function agentCounts(): array
    {
        $counts = Agent::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'approved' => (int) ($counts[Agent::STATUS_APPROVED] ?? 0),
            'pending_approval' => (int) ($counts[Agent::STATUS_PENDING_APPROVAL] ?? 0),
            'disabled' => (int) ($counts[Agent::STATUS_DISABLED] ?? 0),
        ];
    }

    private function projectCounts(): array
    {
        return [
            'total' => Project::count(),
            'active' => Project::where('is_active', true)->count(),
        ];
    }

    private function deploymentCounts(): array
    {
        $counts = Deployment::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'succeeded' => (int) ($counts[Deployment::STATUS_SUCCEEDED] ?? 0),
            'failed' => (int) ($counts[Deployment::STATUS_FAILED] ?? 0),
            'running' => (int) ($counts[Deployment::STATUS_RUNNING] ?? 0),
        ];
    }

    /**
     * @return Collection<int, array{agent: Agent, reason: string}>
     */
    private function problemAgents(): Collection
    {
        $pending = Agent::where('status', Agent::STATUS_PENDING_APPROVAL)
            ->with('server')
            ->latest()
            ->take(self::PROBLEM_LIST_LIMIT)
            ->get()
            ->map(fn (Agent $agent) => ['agent' => $agent, 'reason' => 'pending_approval']);

        // "Offline" and "stale heartbeat" collapse into one signal here —
        // Agent::isOnline() already IS the heartbeat-staleness check
        // (docs/PHASE_4C_DASHBOARD_IMPLEMENTATION_REPORT.md §1 explains
        // why a second, separate threshold wasn't invented for this).
        $offline = Agent::where('status', Agent::STATUS_APPROVED)
            ->with('server')
            ->get()
            ->reject(fn (Agent $agent) => $agent->isOnline())
            ->take(self::PROBLEM_LIST_LIMIT)
            ->map(fn (Agent $agent) => ['agent' => $agent, 'reason' => 'offline']);

        return $pending->concat($offline)->values();
    }

    /**
     * @return Collection<int, array{deployment: Deployment, reason: string}>
     */
    private function problemDeployments(): Collection
    {
        $failed = Deployment::with('project')
            ->where('status', Deployment::STATUS_FAILED)
            ->latest('finished_at')
            ->take(self::PROBLEM_LIST_LIMIT)
            ->get()
            ->map(fn (Deployment $deployment) => ['deployment' => $deployment, 'reason' => 'failed']);

        $stalled = $this->stalledDeployments()
            ->map(fn (Deployment $deployment) => ['deployment' => $deployment, 'reason' => 'stalled']);

        $longRunning = $this->longRunningDeployments()
            ->map(fn (Deployment $deployment) => ['deployment' => $deployment, 'reason' => 'long_running']);

        return $failed->concat($stalled)->concat($longRunning)->values();
    }

    /**
     * @return Collection<int, Deployment>
     */
    private function stalledDeployments(): Collection
    {
        $cutoff = now()->subMinutes((int) config('deployment.stalled_deployment_timeout_minutes'));

        $stalledLegacy = Deployment::with('project')
            ->where('status', Deployment::STATUS_RUNNING)
            ->whereDoesntHave('tasks')
            ->where(fn ($query) => $query->where('last_activity_at', '<', $cutoff)->orWhereNull('last_activity_at'))
            ->take(self::PROBLEM_LIST_LIMIT)
            ->get();

        $stalledNewDomain = Deployment::with('project')
            ->whereHas('tasks', function ($query) use ($cutoff) {
                $query->where('status', DeploymentTask::STATUS_RUNNING)
                    ->whereHas('agentJobs', function ($jobQuery) use ($cutoff) {
                        $jobQuery->where('status', AgentJob::STATUS_RUNNING)
                            ->where(fn ($q) => $q->where('last_activity_at', '<', $cutoff)->orWhereNull('last_activity_at'));
                    });
            })
            ->take(self::PROBLEM_LIST_LIMIT)
            ->get();

        return $stalledLegacy->concat($stalledNewDomain)->unique('id')->values();
    }

    /**
     * @return Collection<int, Deployment>
     */
    private function longRunningDeployments(): Collection
    {
        $stalledCutoff = now()->subMinutes((int) config('deployment.stalled_deployment_timeout_minutes'));
        $longRunningCutoff = now()->subMinutes(self::LONG_RUNNING_MINUTES);

        $legacy = Deployment::with('project')
            ->where('status', Deployment::STATUS_RUNNING)
            ->whereDoesntHave('tasks')
            ->where('started_at', '<', $longRunningCutoff)
            ->where(fn ($query) => $query->where('last_activity_at', '>=', $stalledCutoff)->orWhere('last_activity_at', '>=', $longRunningCutoff))
            ->take(self::PROBLEM_LIST_LIMIT)
            ->get();

        $newDomain = Deployment::with('project')
            ->whereHas('tasks', function ($query) use ($longRunningCutoff, $stalledCutoff) {
                $query->where('status', DeploymentTask::STATUS_RUNNING)
                    ->whereHas('agentJobs', function ($jobQuery) use ($longRunningCutoff, $stalledCutoff) {
                        $jobQuery->where('status', AgentJob::STATUS_RUNNING)
                            ->where('started_at', '<', $longRunningCutoff)
                            ->where('last_activity_at', '>=', $stalledCutoff);
                    });
            })
            ->take(self::PROBLEM_LIST_LIMIT)
            ->get();

        return $legacy->concat($newDomain)->unique('id')->values();
    }

    /**
     * "نوع خطا / پروژه / زمان / وضعیت" (Task 3) — the most recent failed
     * deployments across both domains, newest first.
     *
     * @return Collection<int, Deployment>
     */
    private function recentErrors(): Collection
    {
        return Deployment::with('project')
            ->where('status', Deployment::STATUS_FAILED)
            ->latest('finished_at')
            ->take(self::PROBLEM_LIST_LIMIT)
            ->get();
    }
}
