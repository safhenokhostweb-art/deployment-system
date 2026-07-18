<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Server;
use App\Services\Security\AgentCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function __construct(
        private readonly AgentCredentialService $credentials,
    ) {
    }

    public function index()
    {
        $servers = Server::withCount('projects')->latest()->get();

        return view('servers.index', compact('servers'));
    }

    public function create()
    {
        return view('servers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
        ]);

        $plainToken = $this->credentials->generateToken();
        $plainSecret = $this->credentials->generateSigningSecret();

        $server = Server::create([
            ...$validated,
            'agent_token_hash' => $this->credentials->hashToken($plainToken),
            'signing_secret' => $plainSecret,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'server.registered',
            subjectType: Server::class,
            subjectId: $server->id,
        );

        return redirect()
            ->route('servers.show', $server)
            ->with('generated_token', $plainToken)
            ->with('generated_secret', $plainSecret)
            ->with('status', 'Server registered. Copy the credentials below now — the token and secret are shown only once.');
    }

    public function show(Server $server)
    {
        return view('servers.show', compact('server'));
    }

    public function rotateToken(Request $request, Server $server): RedirectResponse
    {
        $plainToken = $this->credentials->generateToken();
        $plainSecret = $this->credentials->generateSigningSecret();

        $server->update([
            'agent_token_hash' => $this->credentials->hashToken($plainToken),
            'signing_secret' => $plainSecret,
        ]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'server.credentials_rotated',
            subjectType: Server::class,
            subjectId: $server->id,
        );

        return redirect()
            ->route('servers.show', $server)
            ->with('generated_token', $plainToken)
            ->with('generated_secret', $plainSecret)
            ->with('status', 'Credentials rotated. Update config.php on that server now — the old credentials stop working immediately.');
    }
}
