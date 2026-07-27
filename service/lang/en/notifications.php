<?php

/**
 * Phase 4-J: kept in parallel with lang/fa/notifications.php per this
 * app's existing convention of maintaining both locales even though only
 * 'fa' is ever actually rendered to a user today (see the 'fa' file's
 * own docblock for why notification text is always generated with an
 * explicit 'fa' locale regardless of the ambient app locale).
 */
return [
    'deployment_started_title' => 'Deployment started',
    'deployment_started_message' => 'Deployment :label for project :project has started.',
    'deployment_success_title' => 'Deployment succeeded',
    'deployment_success_message' => 'Deployment :label for project :project succeeded.',
    'deployment_failed_title' => 'Deployment failed',
    'deployment_failed_message' => 'Deployment :label for project :project failed.',
    'deployment_rollback_title' => 'Rollback completed',
    'deployment_rollback_message' => 'Rollback to :label for project :project completed.',
    'rollback_started_title' => 'Rollback started',
    'rollback_started_message' => 'Rollback to :label for project :project has started.',
    'rollback_failed_title' => 'Rollback failed',
    'rollback_failed_message' => 'Rollback to :label for project :project failed.',

    'agent_approval_required_title' => 'Agent needs approval',
    'agent_approval_required_message' => 'New agent ":name" on server :server needs approval.',
    'agent_connected_title' => 'Agent connected',
    'agent_connected_message' => 'Agent ":name" on server :server was approved and connected.',
    'agent_offline_title' => 'Agent went offline',
    'agent_offline_message' => 'Agent ":name" on server :server is no longer responding.',

    'github_connected_title' => 'GitHub connection established',
    'github_connected_message' => 'GitHub account ":login" connected successfully.',
    'github_failed_title' => 'GitHub connection failed',
    'github_failed_message' => 'Connection check for GitHub account ":login" failed.',

    'permission_changed_title' => 'User role changed',
    'permission_changed_message' => 'User :target\'s role changed from :from to :to.',
    'credential_rotated_title' => 'Server credential rotated',
    'credential_rotated_message' => 'Server :server\'s credentials were rotated by :actor.',

    'operation_started_title' => 'Operation started',
    'operation_started_message' => 'Operation :operation on server :server has started.',
    'operation_success_title' => 'Operation succeeded',
    'operation_success_message' => 'Operation :operation on server :server succeeded.',
    'operation_failed_title' => 'Operation failed',
    'operation_failed_message' => 'Operation :operation on server :server failed.',

    'local_agent_approval_required_title' => 'Local agent needs approval',
    'local_agent_approval_required_message' => 'New local agent ":name" needs approval.',
    'publish_awaiting_approval_title' => 'Publish awaiting approval',
    'publish_awaiting_approval_message' => 'Publishing :project with :count changed files is awaiting your approval.',
    'publish_success_title' => 'Publish succeeded',
    'publish_success_message' => ':action of :project completed successfully.',
    'publish_failed_title' => 'Publish failed',
    'publish_failed_message' => ':action of :project failed.',

    'change_detection_awaiting_approval_title' => 'Local change awaiting approval',
    'change_detection_awaiting_approval_message' => ':count file(s) changed in :project and are ready to publish.',
];
