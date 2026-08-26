<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\LocalAgentControlJob;
use App\Models\PublishJob;
use App\Services\Operations\QueueReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class QueueReconciliationController extends Controller
{
    public function __construct(private readonly QueueReconciliationService $reconciliation) {}

    public function index()
    {
        Gate::authorize('local_agents.manage');
        $deployments = Deployment::with('project')->whereIn('status', [Deployment::STATUS_PENDING_APPROVAL, Deployment::STATUS_QUEUED])->latest()->get();
        $publishes = PublishJob::with(['project','localAgent'])->whereIn('status', [PublishJob::STATUS_QUEUED, PublishJob::STATUS_APPROVED, PublishJob::STATUS_AWAITING_APPROVAL, PublishJob::STATUS_RUNNING])->latest()->get();
        $controls = LocalAgentControlJob::with('localAgent')->whereIn('status', [LocalAgentControlJob::STATUS_QUEUED, LocalAgentControlJob::STATUS_RUNNING, LocalAgentControlJob::STATUS_AWAITING_HEARTBEAT])->latest()->get();
        return view('operations.queue-reconciliation', compact('deployments', 'publishes', 'controls'));
    }

    public function reconcile(Request $request): \Illuminate\Http\RedirectResponse
    {
        Gate::authorize('local_agents.manage');
        $v = $request->validate(['record_type' => 'required|in:deployment,publish,agent_control', 'record_id' => 'required|integer', 'reason' => 'required|string|max:1000']);
        $this->reconciliation->reconcile($v['record_type'], (int) $v['record_id'], $request->user()->email, $v['reason']);
        return back()->with('status', 'Queue record reconciled.');
    }
}
