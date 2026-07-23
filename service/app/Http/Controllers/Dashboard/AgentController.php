<?php

namespace App\Http\Controllers\Dashboard;

use App\Events\AgentConnected;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLogEntry;
use App\Models\Server;
use App\Services\Security\AgentCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AgentController extends Controller
{
    public function __construct(
        private readonly AgentCredentialService $credentials,
    ) {
    }

    public function index()
    {
        Gate::authorize('agents.view');

        $agents = Agent::with('server')
            ->where('status', '!=', Agent::STATUS_PENDING_APPROVAL)
            ->latest()
            ->get();

        return view('agents.index', compact('agents'));
    }

    public function pending()
    {
        Gate::authorize('agents.view');

        $pending = Agent::with('server')
            ->where('status', Agent::STATUS_PENDING_APPROVAL)
            ->latest()
            ->get();

        return view('agents.pending', compact('pending'));
    }

    public function show(Agent $agent)
    {
        Gate::authorize('agents.view');

        $agent->load('server');

        return view('agents.show', compact('agent'));
    }

    /**
     * Starts the Register -> Pending Approval -> Approved lifecycle for a
     * new agent process on an existing Server. Creates the Agent row
     * immediately in pending_approval status holding only the one-time
     * registration token; name/os/architecture/capabilities are filled in
     * later by the agent's own POST /api/agent/register call.
     */
    public function createRegistration(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('agents.approve');

        $plainRegistrationToken = $this->credentials->generateRegistrationToken();

        $agent = Agent::create([
            'server_id' => $server->id,
            'status' => Agent::STATUS_PENDING_APPROVAL,
            'registration_token' => $plainRegistrationToken,
            'registration_token_expires_at' => now()->addMinutes(
                (int) config('deployment.agent_registration_token_ttl_minutes')
            ),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'agent.registration_token_issued',
            subjectType: Agent::class,
            subjectId: $agent->id,
            metadata: ['server_id' => $server->id],
        );

        return redirect()
            ->route('agents.show', $agent)
            ->with('generated_registration_id', $agent->public_id)
            ->with('generated_registration_token', $plainRegistrationToken)
            ->with('status', 'Registration token issued. Copy both values now — shown only once, expires in '
                . config('deployment.agent_registration_token_ttl_minutes') . ' minutes.');
    }

    public function approve(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('agents.approve');

        abort_unless($agent->status === Agent::STATUS_PENDING_APPROVAL, 409, 'Only a pending agent can be approved.');
        abort_if($agent->name === null, 409, 'This agent has not completed its registration call yet — approving now would issue credentials it has no way to receive.');

        $plainToken = $this->credentials->generateToken();
        $plainSecret = $this->credentials->generateSigningSecret();

        $agent->update([
            'status' => Agent::STATUS_APPROVED,
            'agent_token_hash' => $this->credentials->hashToken($plainToken),
            'signing_secret' => $plainSecret,
            'approved_by' => $request->user()->email,
            'approved_at' => now(),
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'agent.approved',
            subjectType: Agent::class,
            subjectId: $agent->id,
        );

        // Phase 4-J: pure instrumentation for NotificationEventSubscriber.
        event(new AgentConnected($agent));

        return redirect()
            ->route('agents.show', $agent)
            ->with('generated_token', $plainToken)
            ->with('generated_secret', $plainSecret)
            ->with('status', 'Agent approved. Copy the credentials below now — shown only once.');
    }

    public function reject(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('agents.approve');

        abort_unless($agent->status === Agent::STATUS_PENDING_APPROVAL, 409, 'Only a pending agent can be rejected.');

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'agent.rejected',
            subjectType: Agent::class,
            subjectId: $agent->id,
            metadata: ['server_id' => $agent->server_id],
        );

        $agent->delete();

        return redirect()->route('agents.pending')->with('status', 'Pending agent rejected and removed.');
    }

    public function disable(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('agents.disable');

        abort_unless($agent->status === Agent::STATUS_APPROVED, 409, 'Only an approved agent can be disabled.');

        $agent->update(['status' => Agent::STATUS_DISABLED]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'agent.disabled',
            subjectType: Agent::class,
            subjectId: $agent->id,
        );

        return redirect()->route('agents.show', $agent)->with('status', 'Agent disabled — it can no longer authenticate.');
    }

    public function reenable(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('agents.disable');

        abort_unless($agent->status === Agent::STATUS_DISABLED, 409, 'Only a disabled agent can be re-enabled.');

        $agent->update(['status' => Agent::STATUS_APPROVED]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'agent.reenabled',
            subjectType: Agent::class,
            subjectId: $agent->id,
        );

        return redirect()->route('agents.show', $agent)->with('status', 'Agent re-enabled.');
    }

    /**
     * A pending agent is removed via reject() above instead — this covers
     * the other case: an approved agent must be disabled first (same
     * "stop it, then remove it" shape as disable()/reenable()), so a hard
     * delete never silently revokes a still-live credential. agent_jobs
     * ->restrictOnDelete()'s this agent at the schema level; discovery_jobs/
     * operation_jobs cascadeOnDelete and deployment_targets.agent_id
     * nullOnDelete need no such check.
     */
    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('agents.delete');

        abort_unless($agent->status === Agent::STATUS_DISABLED, 409, __('messages.errors.agent_delete_requires_disabled'));
        abort_if($agent->agentJobs()->exists(), 409, __('messages.errors.agent_has_job_history'));

        $serverId = $agent->server_id;

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'agent.deleted',
            subjectType: Agent::class,
            subjectId: $agent->id,
            metadata: ['server_id' => $serverId],
        );

        $agent->delete();

        return redirect()->route('servers.show', $serverId)->with('status', __('messages.flash.agent_deleted'));
    }
}
