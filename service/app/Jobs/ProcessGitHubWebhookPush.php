<?php

namespace App\Jobs;

use App\Models\AuditLogEntry;
use App\Models\Project;
use App\Services\Deployment\DeploymentDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Signature verification and event/branch filtering happen synchronously in
 * GitHubWebhookController (they're cheap and GitHub's UI benefits from an
 * immediate, specific response). Only the actual deployment-creation write
 * is queued, so a transient DB hiccup gets retried instead of silently
 * dropping a push, and the webhook response time doesn't depend on DB load.
 */
class ProcessGitHubWebhookPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public int $timeout = 30;

    public function __construct(
        public readonly Project $project,
        public readonly string $commitHash,
        public readonly string $branch,
        public readonly ?string $pusher,
    ) {
    }

    public function handle(DeploymentDispatcher $dispatcher): void
    {
        $dispatcher->createFromWebhook($this->project, $this->commitHash, $this->branch, $this->pusher);
    }

    public function failed(Throwable $exception): void
    {
        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_SYSTEM,
            actorIdentifier: null,
            action: 'webhook.deployment_dispatch_failed',
            subjectType: Project::class,
            subjectId: $this->project->id,
            metadata: [
                'commit_hash' => $this->commitHash,
                'branch' => $this->branch,
                'error' => $exception->getMessage(),
            ],
        );
    }
}
