<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Services\Publish\StalePublishJobRecoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Root-cause fix, section 7: "بازیابی انتشارهای معلق" — deliberately
 * separate from LocalAgentController::restart() ("بازیابی عامل محلی"):
 * that one asks the agent process itself to restart; this one cleans up
 * PublishJob/Project lock STATE on the Hub regardless of whether any
 * agent restart happens at all. Calls the exact same
 * StalePublishJobRecoveryService deployments:recover-stale uses — no
 * duplicated "what counts as stale" logic here.
 */
class PublishRecoveryController extends Controller
{
    public function __construct(
        private readonly StalePublishJobRecoveryService $recovery,
    ) {
    }

    public function recover(Request $request): RedirectResponse
    {
        Gate::authorize('local_agents.manage');

        $result = $this->recovery->recover(false);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'publish_job.manual_recovery_triggered',
            metadata: $result,
        );

        $status = sprintf(
            'بازیابی انجام شد: %d Job بررسی شد — %d اتمام‌زمان، %d رهاشده، %d Deployment نهایی‌شد، %d Lock آزاد شد.',
            $result['checked'],
            $result['timed_out'],
            $result['abandoned'],
            $result['deployments_finalized'],
            $result['locks_released'],
        );

        return redirect()->route('local-agents.index')->with('status', $status);
    }
}
