<?php

namespace App\Listeners;

use App\Events\AgentApprovalRequired;
use App\Events\AgentConnected;
use App\Events\AgentOffline;
use App\Events\CredentialRotated;
use App\Events\DeploymentFailed;
use App\Events\DeploymentStarted;
use App\Events\DeploymentSucceeded;
use App\Events\GitHubConnected;
use App\Events\GitHubConnectionFailed;
use App\Events\LocalAgentApprovalRequired;
use App\Events\LocalChangeDetectionAwaitingApproval;
use App\Events\OperationFailed;
use App\Events\OperationStarted;
use App\Events\OperationSucceeded;
use App\Events\PermissionChanged;
use App\Events\PublishJobAbandoned;
use App\Events\PublishJobAwaitingApproval;
use App\Events\PublishJobFailed;
use App\Events\PublishJobSucceeded;
use App\Events\RollbackCompleted;
use App\Events\RollbackStarted;
use App\Models\Deployment;
use App\Models\Notification;
use App\Models\OperationJob;
use App\Models\PublishJob;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Illuminate\Events\Dispatcher;

/**
 * Phase 4-J Task 7: the single place mapping "System Event" to "User
 * Notification". Every method here only ever calls NotificationService —
 * no business logic, no writes to any Deployment/Agent/Server/User row.
 * All eleven events already existed or were added purely as
 * instrumentation at their existing call sites (see
 * docs/PHASE_4J_NOTIFICATION_OPERATIONS_REPORT.md §5); this class is the
 * only thing that reacts to them.
 */
class NotificationEventSubscriber
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(DeploymentStarted::class, [self::class, 'onDeploymentStarted']);
        $events->listen(DeploymentSucceeded::class, [self::class, 'onDeploymentSucceeded']);
        $events->listen(DeploymentFailed::class, [self::class, 'onDeploymentFailed']);
        $events->listen(RollbackCompleted::class, [self::class, 'onRollbackCompleted']);
        $events->listen(RollbackStarted::class, [self::class, 'onRollbackStarted']);
        $events->listen(AgentApprovalRequired::class, [self::class, 'onAgentApprovalRequired']);
        $events->listen(AgentConnected::class, [self::class, 'onAgentConnected']);
        $events->listen(AgentOffline::class, [self::class, 'onAgentOffline']);
        $events->listen(GitHubConnected::class, [self::class, 'onGitHubConnected']);
        $events->listen(GitHubConnectionFailed::class, [self::class, 'onGitHubConnectionFailed']);
        $events->listen(PermissionChanged::class, [self::class, 'onPermissionChanged']);
        $events->listen(CredentialRotated::class, [self::class, 'onCredentialRotated']);
        $events->listen(OperationStarted::class, [self::class, 'onOperationStarted']);
        $events->listen(OperationSucceeded::class, [self::class, 'onOperationSucceeded']);
        $events->listen(OperationFailed::class, [self::class, 'onOperationFailed']);
        $events->listen(LocalAgentApprovalRequired::class, [self::class, 'onLocalAgentApprovalRequired']);
        $events->listen(PublishJobAwaitingApproval::class, [self::class, 'onPublishJobAwaitingApproval']);
        $events->listen(PublishJobSucceeded::class, [self::class, 'onPublishJobSucceeded']);
        $events->listen(PublishJobFailed::class, [self::class, 'onPublishJobFailed']);
        $events->listen(PublishJobAbandoned::class, [self::class, 'onPublishJobAbandoned']);
        $events->listen(LocalChangeDetectionAwaitingApproval::class, [self::class, 'onLocalChangeDetectionAwaitingApproval']);
    }

    public function onDeploymentStarted(DeploymentStarted $event): void
    {
        $deployment = $event->deployment;

        $this->notifyDeploymentAudience(
            $deployment,
            type: Notification::TYPE_DEPLOYMENT_STARTED,
            title: __('notifications.deployment_started_title', [], 'fa'),
            message: $this->deploymentMessage('notifications.deployment_started_message', $deployment),
            severity: Notification::SEVERITY_INFO,
        );
    }

    public function onDeploymentSucceeded(DeploymentSucceeded $event): void
    {
        $deployment = $event->deployment;

        $this->notifyDeploymentAudience(
            $deployment,
            type: Notification::TYPE_DEPLOYMENT_SUCCESS,
            title: __('notifications.deployment_success_title', [], 'fa'),
            message: $this->deploymentMessage('notifications.deployment_success_message', $deployment),
            severity: Notification::SEVERITY_SUCCESS,
        );
    }

    public function onDeploymentFailed(DeploymentFailed $event): void
    {
        $deployment = $event->deployment;

        // Task 2 (completion): a failed rollback reused the generic
        // "deployment failed" copy, which read as if a normal deploy had
        // failed. Same event, same type (rollback stays part of the
        // "deployment" preference group), just clearer text when the
        // failing action was actually a rollback.
        $isRollback = $deployment->action === Deployment::ACTION_ROLLBACK;

        $this->notifyDeploymentAudience(
            $deployment,
            type: Notification::TYPE_DEPLOYMENT_FAILED,
            title: __($isRollback ? 'notifications.rollback_failed_title' : 'notifications.deployment_failed_title', [], 'fa'),
            message: $this->deploymentMessage($isRollback ? 'notifications.rollback_failed_message' : 'notifications.deployment_failed_message', $deployment),
            severity: Notification::SEVERITY_CRITICAL,
        );
    }

    public function onRollbackStarted(RollbackStarted $event): void
    {
        $deployment = $event->deployment;

        $this->notifyDeploymentAudience(
            $deployment,
            type: Notification::TYPE_DEPLOYMENT_ROLLBACK_STARTED,
            title: __('notifications.rollback_started_title', [], 'fa'),
            message: $this->deploymentMessage('notifications.rollback_started_message', $deployment),
            severity: Notification::SEVERITY_INFO,
        );
    }

    public function onRollbackCompleted(RollbackCompleted $event): void
    {
        $deployment = $event->deployment;

        $this->notifyDeploymentAudience(
            $deployment,
            type: Notification::TYPE_DEPLOYMENT_ROLLBACK,
            title: __('notifications.deployment_rollback_title', [], 'fa'),
            message: $this->deploymentMessage('notifications.deployment_rollback_message', $deployment),
            severity: Notification::SEVERITY_WARNING,
        );
    }

    public function onAgentApprovalRequired(AgentApprovalRequired $event): void
    {
        $agent = $event->agent;

        $this->notifications->notifyAdmins(
            type: Notification::TYPE_AGENT_APPROVAL_REQUIRED,
            title: __('notifications.agent_approval_required_title', [], 'fa'),
            message: __('notifications.agent_approval_required_message', [
                'name' => $agent->name ?? $agent->public_id,
                'server' => $agent->server->name,
            ], 'fa'),
            severity: Notification::SEVERITY_WARNING,
            related: $agent,
            actorType: 'agent',
            actorIdentifier: $agent->public_id,
        );
    }

    public function onAgentConnected(AgentConnected $event): void
    {
        $agent = $event->agent;

        $this->notifications->notifyAdmins(
            type: Notification::TYPE_AGENT_CONNECTED,
            title: __('notifications.agent_connected_title', [], 'fa'),
            message: __('notifications.agent_connected_message', [
                'name' => $agent->name ?? $agent->public_id,
                'server' => $agent->server->name,
            ], 'fa'),
            severity: Notification::SEVERITY_SUCCESS,
            related: $agent,
            actorType: 'user',
            actorIdentifier: $agent->approved_by,
        );
    }

    public function onAgentOffline(AgentOffline $event): void
    {
        $agent = $event->agent;

        $this->notifications->notifyAdmins(
            type: Notification::TYPE_AGENT_OFFLINE,
            title: __('notifications.agent_offline_title', [], 'fa'),
            message: __('notifications.agent_offline_message', [
                'name' => $agent->name ?? $agent->public_id,
                'server' => $agent->server->name,
            ], 'fa'),
            severity: Notification::SEVERITY_CRITICAL,
            related: $agent,
            actorType: 'system',
        );
    }

    public function onGitHubConnected(GitHubConnected $event): void
    {
        $account = $event->account;

        $this->notifications->notifyAdmins(
            type: Notification::TYPE_GITHUB_CONNECTED,
            title: __('notifications.github_connected_title', [], 'fa'),
            message: __('notifications.github_connected_message', ['login' => $account->account_login], 'fa'),
            severity: Notification::SEVERITY_SUCCESS,
            related: $account,
            actorType: 'user',
            actorIdentifier: $event->connectedBy,
        );
    }

    public function onGitHubConnectionFailed(GitHubConnectionFailed $event): void
    {
        $account = $event->account;

        $this->notifications->notifyAdmins(
            type: Notification::TYPE_GITHUB_FAILED,
            title: __('notifications.github_failed_title', [], 'fa'),
            message: __('notifications.github_failed_message', ['login' => $account->account_login], 'fa'),
            severity: Notification::SEVERITY_WARNING,
            related: $account,
            actorType: 'system',
        );
    }

    public function onPermissionChanged(PermissionChanged $event): void
    {
        $this->notifications->notifyAdmins(
            type: Notification::TYPE_PERMISSION_CHANGED,
            title: __('notifications.permission_changed_title', [], 'fa'),
            message: __('notifications.permission_changed_message', [
                'target' => $event->targetUser->name,
                'from' => __('ui.users.role_' . $event->previousRole, [], 'fa'),
                'to' => __('ui.users.role_' . $event->newRole, [], 'fa'),
            ], 'fa'),
            severity: Notification::SEVERITY_WARNING,
            related: $event->targetUser,
            actorType: 'user',
            actorIdentifier: $event->changedBy,
        );
    }

    public function onCredentialRotated(CredentialRotated $event): void
    {
        $server = $event->server;

        // Task 11: literally nothing credential-shaped is ever
        // interpolated here — only the server's own name and the
        // rotating user's email.
        $this->notifications->notifyAdmins(
            type: Notification::TYPE_CREDENTIAL_ROTATED,
            title: __('notifications.credential_rotated_title', [], 'fa'),
            message: __('notifications.credential_rotated_message', [
                'server' => $server->name,
                'actor' => $event->rotatedBy,
            ], 'fa'),
            severity: Notification::SEVERITY_WARNING,
            related: $server,
            actorType: 'user',
            actorIdentifier: $event->rotatedBy,
        );
    }

    public function onOperationStarted(OperationStarted $event): void
    {
        $this->notifyOperationAudience(
            $event->operationJob,
            type: Notification::TYPE_OPERATION_STARTED,
            titleKey: 'notifications.operation_started_title',
            messageKey: 'notifications.operation_started_message',
            severity: Notification::SEVERITY_INFO,
        );
    }

    public function onOperationSucceeded(OperationSucceeded $event): void
    {
        $this->notifyOperationAudience(
            $event->operationJob,
            type: Notification::TYPE_OPERATION_SUCCESS,
            titleKey: 'notifications.operation_success_title',
            messageKey: 'notifications.operation_success_message',
            severity: Notification::SEVERITY_SUCCESS,
        );
    }

    public function onOperationFailed(OperationFailed $event): void
    {
        $this->notifyOperationAudience(
            $event->operationJob,
            type: Notification::TYPE_OPERATION_FAILED,
            titleKey: 'notifications.operation_failed_title',
            messageKey: 'notifications.operation_failed_message',
            severity: Notification::SEVERITY_CRITICAL,
        );
    }

    public function onLocalAgentApprovalRequired(LocalAgentApprovalRequired $event): void
    {
        $localAgent = $event->localAgent;

        $this->notifications->notifyAdmins(
            type: Notification::TYPE_LOCAL_AGENT_APPROVAL_REQUIRED,
            title: __('notifications.local_agent_approval_required_title', [], 'fa'),
            message: __('notifications.local_agent_approval_required_message', [
                'name' => $localAgent->name ?? $localAgent->public_id,
            ], 'fa'),
            severity: Notification::SEVERITY_WARNING,
            related: $localAgent,
            actorType: 'agent',
            actorIdentifier: $localAgent->public_id,
        );
    }

    public function onPublishJobAwaitingApproval(PublishJobAwaitingApproval $event): void
    {
        $job = $event->publishJob;

        $this->notifyPublishAudience(
            $job,
            type: Notification::TYPE_PUBLISH_AWAITING_APPROVAL,
            title: __('notifications.publish_awaiting_approval_title', [], 'fa'),
            message: __('notifications.publish_awaiting_approval_message', [
                'project' => $job->project->name,
                'count' => (string) $job->files_changed_count,
            ], 'fa'),
            severity: Notification::SEVERITY_INFO,
        );
    }

    public function onPublishJobSucceeded(PublishJobSucceeded $event): void
    {
        $job = $event->publishJob;

        $this->notifyPublishAudience(
            $job,
            type: Notification::TYPE_PUBLISH_SUCCESS,
            title: __('notifications.publish_success_title', [], 'fa'),
            message: __('notifications.publish_success_message', [
                'action' => $this->publishActionLabel($job),
                'project' => $job->project->name,
            ], 'fa'),
            severity: Notification::SEVERITY_SUCCESS,
        );
    }

    public function onPublishJobFailed(PublishJobFailed $event): void
    {
        $job = $event->publishJob;

        $this->notifyPublishAudience(
            $job,
            type: Notification::TYPE_PUBLISH_FAILED,
            title: __('notifications.publish_failed_title', [], 'fa'),
            message: __('notifications.publish_failed_message', [
                'action' => $this->publishActionLabel($job),
                'project' => $job->project->name,
            ], 'fa'),
            severity: Notification::SEVERITY_CRITICAL,
        );
    }

    /**
     * Root-cause fix: distinct from onPublishJobFailed — this is
     * deployments:recover-stale finalizing a job whose claiming agent
     * went silent (crashed/killed/lost connectivity), not the agent
     * itself reporting a failure it observed.
     */
    public function onPublishJobAbandoned(PublishJobAbandoned $event): void
    {
        $job = $event->publishJob;

        $this->notifyPublishAudience(
            $job,
            type: Notification::TYPE_PUBLISH_ABANDONED,
            title: __('notifications.publish_abandoned_title', [], 'fa'),
            message: __('notifications.publish_abandoned_message', [
                'action' => $this->publishActionLabel($job),
                'project' => $job->project->name,
            ], 'fa'),
            severity: Notification::SEVERITY_CRITICAL,
        );
    }

    private function publishActionLabel(PublishJob $job): string
    {
        return $job->action === PublishJob::ACTION_ROLLBACK ? 'Rollback' : 'Publish';
    }

    /**
     * Phase 5.2: unlike a PublishJob, a detected change has no
     * "requesting user" (the agent's watcher found it, no one asked for
     * it yet) — so this notifies Admins only, same shape as
     * onLocalAgentApprovalRequired().
     */
    public function onLocalChangeDetectionAwaitingApproval(LocalChangeDetectionAwaitingApproval $event): void
    {
        $job = $event->changeDetectionJob;

        $this->notifications->notifyAdmins(
            type: Notification::TYPE_CHANGE_DETECTION_AWAITING_APPROVAL,
            title: __('notifications.change_detection_awaiting_approval_title', [], 'fa'),
            message: __('notifications.change_detection_awaiting_approval_message', [
                'project' => $job->project->name,
                'count' => (string) $job->files_changed_count,
            ], 'fa'),
            severity: Notification::SEVERITY_INFO,
            related: $job,
            actorType: 'agent',
        );
    }

    /**
     * Same "Admins + the requesting user" audience as a Deployment event —
     * operations.view/execute are already role-gated (Task 9), so this
     * doesn't widen who could ever plausibly have requested one.
     */
    private function notifyOperationAudience(OperationJob $job, string $type, string $titleKey, string $messageKey, string $severity): void
    {
        $operationLabel = __('ui.operations.type_' . $job->operation_type, [], 'fa');
        $title = __($titleKey, [], 'fa');
        $message = __($messageKey, ['operation' => $operationLabel, 'server' => $job->server->name], 'fa');

        $this->notifications->notifyAdmins(
            type: $type,
            title: $title,
            message: $message,
            severity: $severity,
            related: $job,
            actorType: 'system',
        );

        $requestingUser = $job->requested_by
            ? User::where('email', $job->requested_by)->where('role', '!=', User::ROLE_ADMIN)->first()
            : null;

        if ($requestingUser !== null) {
            $this->notifications->notify(
                $requestingUser,
                type: $type,
                title: $title,
                message: $message,
                severity: $severity,
                related: $job,
                actorType: 'system',
            );
        }
    }

    /**
     * Same "Admins + the requesting user" audience as
     * notifyOperationAudience() — local_agents.view/publish are already
     * role-gated, so this doesn't widen who could plausibly have
     * requested a publish/rollback.
     */
    private function notifyPublishAudience(PublishJob $job, string $type, string $title, string $message, string $severity): void
    {
        $this->notifications->notifyAdmins(
            type: $type,
            title: $title,
            message: $message,
            severity: $severity,
            related: $job,
            actorType: 'system',
        );

        $requestingUser = $job->requested_by
            ? User::where('email', $job->requested_by)->where('role', '!=', User::ROLE_ADMIN)->first()
            : null;

        if ($requestingUser !== null) {
            $this->notifications->notify(
                $requestingUser,
                type: $type,
                title: $title,
                message: $message,
                severity: $severity,
                related: $job,
                actorType: 'system',
            );
        }
    }

    private function deploymentMessage(string $key, Deployment $deployment): string
    {
        return __($key, [
            'label' => $deployment->displayLabel(),
            'project' => $deployment->project->name,
        ], 'fa');
    }

    /**
     * Every Admin (operational oversight) plus the user who actually
     * triggered this deployment, if their triggered_by field resolves to
     * a real account (webhook/auto_deploy triggers don't, and simply add
     * no extra recipient).
     */
    private function notifyDeploymentAudience(Deployment $deployment, string $type, string $title, string $message, string $severity): void
    {
        $this->notifications->notifyAdmins(
            type: $type,
            title: $title,
            message: $message,
            severity: $severity,
            related: $deployment,
            actorType: 'system',
        );

        $triggeringUser = $deployment->triggered_by
            ? User::where('email', $deployment->triggered_by)->where('role', '!=', User::ROLE_ADMIN)->first()
            : null;

        if ($triggeringUser !== null) {
            $this->notifications->notify(
                $triggeringUser,
                type: $type,
                title: $title,
                message: $message,
                severity: $severity,
                related: $deployment,
                actorType: 'system',
            );
        }
    }
}
