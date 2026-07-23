<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\OperationJob;
use App\Models\Server;
use App\Services\Operations\OperationJobService;
use App\Support\Operations\OperationRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-K Task 6-8: the Operations Command Center. Every write path
 * (store()) goes through OperationJobService, which is the only place
 * that validates operation_type against OperationRegistry and queues a
 * job — this controller adds no execution logic of its own.
 */
class OperationController extends Controller
{
    public function __construct(
        private readonly OperationJobService $operations,
    ) {
    }

    public function index()
    {
        Gate::authorize('operations.view');

        $servers = Server::with(['agents' => fn ($query) => $query->where('status', Agent::STATUS_APPROVED)])
            ->get()
            ->map(function (Server $server) {
                $agent = $server->agents->first();

                return [
                    'server' => $server,
                    'agent' => $agent,
                    'project' => $agent ? $server->soleProject() : null,
                    'latest' => $server->operationJobs()->latest()->first(),
                ];
            });

        $laravelTypes = OperationRegistry::laravelGroupTypes();
        $serverTypes = OperationRegistry::serverGroupTypes();

        return view('operations.index', compact('servers', 'laravelTypes', 'serverTypes'));
    }

    public function store(Request $request, Server $server): RedirectResponse
    {
        Gate::authorize('operations.execute');

        // Task 4/12: a plain string read, not a validated-then-trusted
        // value — abort_if() below (matching every other check in this
        // method, and OperationJobService::queue()'s own independent
        // check) is what actually keeps this to registry-known types.
        // Deliberately not Laravel's `in:` validation rule: that throws
        // ValidationException, which redirects back on a non-JSON request
        // instead of the immediate 422 every other guard here returns —
        // this keeps all of this method's failure modes consistent.
        $operationType = (string) $request->input('operation_type');
        abort_unless(OperationRegistry::isAllowed($operationType), 422, "Unknown operation type '{$operationType}'.");

        $agent = $server->agents()->where('status', Agent::STATUS_APPROVED)->first();
        abort_if($agent === null, 422, 'This server needs an approved Agent before an operation can run.');

        $project = null;
        if (OperationRegistry::requiresProject($operationType)) {
            $project = $server->soleProject();
            abort_if($project === null, 422, 'This operation needs exactly one project on this server to run against.');
        }

        $this->operations->queue($server, $agent, $operationType, $project, $request->user()->email);

        return redirect()->route('operations.index')->with('status', __('messages.flash.operation_queued'));
    }

    public function show(OperationJob $operationJob)
    {
        Gate::authorize('operations.view');

        $operationJob->load(['server', 'agent', 'project']);

        return view('operations.show', ['job' => $operationJob]);
    }
}
