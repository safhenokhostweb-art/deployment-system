<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\LocalAgent;
use App\Models\LocalAgentControlJob;
use App\Models\Project;
use App\Services\Publish\LocalAgentControlJobService;
use App\Services\Security\AgentCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 5: mirrors AgentController's Register -> Pending Approval ->
 * Approved lifecycle exactly, minus anything Server-specific — a Local
 * Agent has no Server to attach to.
 */
class LocalAgentController extends Controller
{
    public function __construct(
        private readonly AgentCredentialService $credentials,
        private readonly LocalAgentControlJobService $controlJobs,
    ) {
    }

    public function index()
    {
        Gate::authorize('local_agents.view');

        $localAgents = LocalAgent::with([
                'projectMappings.project',
                'publishJobs' => fn ($query) => $query->latest()->limit(5),
            ])
            ->where('status', '!=', LocalAgent::STATUS_PENDING_APPROVAL)
            ->latest()
            ->get();

        return view('local-agents.index', compact('localAgents'));
    }

    public function pending()
    {
        Gate::authorize('local_agents.view');

        $pending = LocalAgent::where('status', LocalAgent::STATUS_PENDING_APPROVAL)
            ->latest()
            ->get();

        return view('local-agents.pending', compact('pending'));
    }

    /**
     * Phase 5.4/5.5: a guided entry point tying together registration,
     * approval, and project-mapping — all of which already exist and are
     * unchanged here. This page just gives a first-time admin one
     * obvious place to start instead of reconstructing the sequence from
     * README-FA.md by hand. The readiness banner (Phase 5.5) is computed
     * live from real rows, never a stored flag — an agent that goes
     * offline or loses its mapping simply stops counting as ready.
     */
    public function setup()
    {
        Gate::authorize('local_agents.manage');

        $isReady = LocalAgent::where('status', LocalAgent::STATUS_APPROVED)
            ->whereHas('projectMappings')
            ->whereHas('publishJobs', fn ($query) => $query->where('status', 'succeeded'))
            ->get()
            ->contains(fn (LocalAgent $agent) => $agent->isOnline());

        return view('local-agents.setup', compact('isReady'));
    }

    public function show(LocalAgent $localAgent)
    {
        Gate::authorize('local_agents.view');

        $localAgent->load([
            'projectMappings.project.artifacts' => fn ($query) => $query->latest(),
            'publishJobs' => fn ($query) => $query->latest()->limit(20),
        ]);

        $availableProjects = Project::whereNotIn('id', $localAgent->projectMappings->pluck('project_id'))
            ->orderBy('name')
            ->get();

        $latestLogFetch = $localAgent->controlJobs()
            ->where('action', LocalAgentControlJob::ACTION_FETCH_LOGS)
            ->where('status', LocalAgentControlJob::STATUS_SUCCEEDED)
            ->latest()
            ->first();

        $latestUpgrade = $localAgent->controlJobs()
            ->where('action', LocalAgentControlJob::ACTION_UPGRADE)
            ->latest()
            ->first();

        return view('local-agents.show', compact('localAgent', 'availableProjects', 'latestLogFetch', 'latestUpgrade'));
    }

    public function createRegistration(Request $request): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        $plainRegistrationToken = $this->credentials->generateRegistrationToken();

        $localAgent = LocalAgent::create([
            'status' => LocalAgent::STATUS_PENDING_APPROVAL,
            'registration_token' => $plainRegistrationToken,
            'registration_token_expires_at' => now()->addMinutes(
                (int) config('deployment.agent_registration_token_ttl_minutes')
            ),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'local_agent.registration_token_issued',
            subjectType: LocalAgent::class,
            subjectId: $localAgent->id,
        );

        return redirect()
            ->route('local-agents.show', $localAgent)
            ->with('generated_registration_id', $localAgent->public_id)
            ->with('generated_registration_token', $plainRegistrationToken)
            ->with('status', 'Registration token issued. Copy both values now — shown only once, expires in '
                . config('deployment.agent_registration_token_ttl_minutes') . ' minutes.');
    }

    public function approve(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($localAgent->status === LocalAgent::STATUS_PENDING_APPROVAL, 409, 'Only a pending local agent can be approved.');
        abort_if($localAgent->name === null, 409, 'This local agent has not completed its registration call yet — approving now would issue credentials it has no way to receive.');

        $plainToken = $this->credentials->generateToken();
        $plainSecret = $this->credentials->generateSigningSecret();

        $localAgent->update([
            'status' => LocalAgent::STATUS_APPROVED,
            'agent_token_hash' => $this->credentials->hashToken($plainToken),
            'signing_secret' => $plainSecret,
            'approved_by' => $request->user()->email,
            'approved_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'local_agent.approved',
            subjectType: LocalAgent::class,
            subjectId: $localAgent->id,
        );

        return redirect()
            ->route('local-agents.show', $localAgent)
            ->with('generated_token', $plainToken)
            ->with('generated_secret', $plainSecret)
            ->with('status', 'Local agent approved. Copy the credentials below now — shown only once.');
    }

    public function reject(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($localAgent->status === LocalAgent::STATUS_PENDING_APPROVAL, 409, 'Only a pending local agent can be rejected.');

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'local_agent.rejected',
            subjectType: LocalAgent::class,
            subjectId: $localAgent->id,
        );

        $localAgent->delete();

        return redirect()->route('local-agents.pending')->with('status', 'Pending local agent rejected and removed.');
    }

    public function disable(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($localAgent->status === LocalAgent::STATUS_APPROVED, 409, 'Only an approved local agent can be disabled.');

        $localAgent->update(['status' => LocalAgent::STATUS_DISABLED]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'local_agent.disabled',
            subjectType: LocalAgent::class,
            subjectId: $localAgent->id,
        );

        return redirect()->route('local-agents.show', $localAgent)->with('status', 'Local agent disabled — it can no longer authenticate.');
    }

    public function reenable(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($localAgent->status === LocalAgent::STATUS_DISABLED, 409, 'Only a disabled local agent can be re-enabled.');

        $localAgent->update(['status' => LocalAgent::STATUS_APPROVED]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'local_agent.reenabled',
            subjectType: LocalAgent::class,
            subjectId: $localAgent->id,
        );

        return redirect()->route('local-agents.show', $localAgent)->with('status', 'Local agent re-enabled.');
    }

    /**
     * Phase 5.3: the Hub has no inbound path to a developer's machine —
     * this only queues a LocalAgentControlJob the agent picks up on its
     * next poll (see local-agent/agent.php's pollAndHandleControl()).
     */
    public function restart(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($localAgent->status === LocalAgent::STATUS_APPROVED, 409, 'Only an approved local agent can be restarted.');

        $this->controlJobs->queue($localAgent, LocalAgentControlJob::ACTION_RESTART, $request->user()->email);

        return redirect()->route('local-agents.show', $localAgent)
            ->with('status', 'درخواست Restart ثبت شد — در بعدی Poll کارگزار اجرا می‌شود.');
    }

    public function fetchLogs(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($localAgent->status === LocalAgent::STATUS_APPROVED, 409, 'Only an approved local agent can be asked for its logs.');

        $this->controlJobs->queue($localAgent, LocalAgentControlJob::ACTION_FETCH_LOGS, $request->user()->email);

        return redirect()->route('local-agents.show', $localAgent)
            ->with('status', 'درخواست دریافت Log ثبت شد — در بعدی Poll کارگزار پاسخ می‌دهد.');
    }

    /**
     * Project-config sync: forces an immediate re-sync on the agent's
     * very next poll instead of waiting for the TTL to expire — the
     * "manual reload" mechanism, since there is no other inbound path to
     * a developer's machine. Same queue/poll/report channel as
     * restart/fetchLogs above, just a different `action`.
     */
    public function reloadConfig(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($localAgent->status === LocalAgent::STATUS_APPROVED, 409, 'Only an approved local agent can be asked to reload its configuration.');

        $this->controlJobs->queue($localAgent, LocalAgentControlJob::ACTION_RELOAD_CONFIG, $request->user()->email);

        return redirect()->route('local-agents.show', $localAgent)
            ->with('status', 'درخواست همگام‌سازی مجدد ثبت شد — در Poll بعدی کارگزار اجرا می‌شود.');
    }

    public function upgrade(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');
        abort_unless($localAgent->status === LocalAgent::STATUS_APPROVED, 409, 'Only an approved Local Agent can be upgraded.');

        $job = $this->controlJobs->queueUpgrade($localAgent, $request->user()->email);

        return redirect()->route('local-agents.show', $localAgent)
            ->with('status', "Linux Local Agent upgrade to {$job->expected_version} queued. Completion requires a verified post-restart heartbeat.");
    }

    public function rotateToken(Request $request, LocalAgent $localAgent): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        abort_unless($localAgent->status === LocalAgent::STATUS_APPROVED, 409, 'Only an approved local agent can have its credentials rotated.');

        $plainToken = $this->credentials->generateToken();
        $plainSecret = $this->credentials->generateSigningSecret();

        $localAgent->update([
            'agent_token_hash' => $this->credentials->hashToken($plainToken),
            'signing_secret' => $plainSecret,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'local_agent.token_rotated',
            subjectType: LocalAgent::class,
            subjectId: $localAgent->id,
        );

        return redirect()
            ->route('local-agents.show', $localAgent)
            ->with('generated_token', $plainToken)
            ->with('generated_secret', $plainSecret)
            ->with('status', 'اعتبارنامه کارگزار محلی چرخانده شد — این مقادیر را همین حالا در config.php جایگزین کنید، فقط یک‌بار نمایش داده می‌شوند.');
    }
}
