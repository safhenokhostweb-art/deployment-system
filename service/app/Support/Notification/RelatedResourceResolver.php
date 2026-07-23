<?php

namespace App\Support\Notification;

use App\Models\Agent;
use App\Models\Deployment;
use App\Models\GitHubAccount;
use App\Models\Notification;
use App\Models\Server;
use App\Models\User;
use Closure;

/**
 * Phase 4-J Task 5: "مشاهده Resource مرتبط" — a pure, read-only URL
 * builder for a Notification's related_type/related_id. Passing the
 * fetched model instance (not its raw id) to route() lets Laravel's own
 * binding-field resolution do the right thing for a custom-keyed route
 * (e.g. agents.show binds on public_id, not id) automatically — the same
 * route($model) pattern already used throughout this app's own views.
 * Returns null whenever the related record no longer exists (deleted
 * since the notification was created) or there's no dedicated detail
 * page for that type — the view simply renders no link in that case.
 */
class RelatedResourceResolver
{
    public function urlFor(Notification $notification): ?string
    {
        if ($notification->related_type === null || $notification->related_id === null) {
            return null;
        }

        return match ($notification->related_type) {
            Deployment::class => $this->ifExists(Deployment::class, $notification->related_id, fn ($m) => route('deployments.show', $m)),
            Agent::class => $this->ifExists(Agent::class, $notification->related_id, fn ($m) => route('agents.show', $m)),
            Server::class => $this->ifExists(Server::class, $notification->related_id, fn ($m) => route('servers.show', $m)),
            // Neither GitHubAccount nor User has a dedicated single-record
            // detail route (only an index + inline actions) — link to the
            // closest existing list view.
            GitHubAccount::class => $this->ifExists(GitHubAccount::class, $notification->related_id, fn () => route('github.accounts.index')),
            User::class => $this->ifExists(User::class, $notification->related_id, fn () => route('users.index')),
            default => null,
        };
    }

    private function ifExists(string $modelClass, int $id, Closure $urlBuilder): ?string
    {
        $model = $modelClass::find($id);

        return $model ? $urlBuilder($model) : null;
    }
}
