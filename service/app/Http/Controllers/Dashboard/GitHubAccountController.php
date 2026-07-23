<?php

namespace App\Http\Controllers\Dashboard;

use App\Events\GitHubConnected;
use App\Events\GitHubConnectionFailed;
use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\GitHubAccount;
use App\Models\Project;
use App\Services\GitHub\GitHubApiException;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Phase 4-E Task 3-5: GitHub App account management, and the two small
 * JSON endpoints the Project Wizard's repository/branch pickers call.
 * Never returns or renders an access token — every GitHub API call uses a
 * short-lived installation token that exists only for the duration of
 * one request (see GitHubAppClient).
 */
class GitHubAccountController extends Controller
{
    public function __construct(
        private readonly GitHubAppClient $client,
    ) {
    }

    /**
     * Phase 4-L Task 2: doubles as the GitHub Setup Wizard's landing
     * page — the existing account list/management actions below, plus
     * two read-only checks that turn "is GitHub set up?" from a guess
     * into an explicit checklist: whether the App itself is configured
     * (Task 1's audit found this is the one genuinely unavoidable manual
     * step — it requires the operator's own github.com login, which the
     * Hub can never automate), and which already-created Projects still
     * need their webhook connected (never asks again for anything the
     * Project already has — repository/branch/secret are all reused
     * exactly as they already exist).
     */
    public function index()
    {
        Gate::authorize('github.view');

        $accounts = GitHubAccount::latest()->get();

        $appConfigChecklist = [
            'id' => (bool) config('services.github_app.id'),
            'slug' => (bool) config('services.github_app.slug'),
            'client_id' => (bool) config('services.github_app.client_id'),
            'client_secret' => (bool) config('services.github_app.client_secret'),
            'private_key' => (bool) config('services.github_app.private_key'),
        ];
        $appConfigured = !in_array(false, $appConfigChecklist, true);

        // Only ever shown when there's an active project to connect and
        // at least one connected account to connect it to — an empty
        // list either way means nothing here for this checklist to say.
        $projectsNeedingGithub = $accounts->contains(fn (GitHubAccount $a) => $a->isConnected())
            ? Project::whereNull('github_account_id')->where('is_active', true)->get()
            : collect();

        return view('github.accounts.index', compact('accounts', 'appConfigChecklist', 'appConfigured', 'projectsNeedingGithub'));
    }

    /**
     * GitHub App installations happen on github.com itself, not on this
     * app's own form — this only redirects there.
     */
    public function connect(): RedirectResponse
    {
        Gate::authorize('github.connect');

        $slug = config('services.github_app.slug');

        if (!$slug) {
            return redirect()->route('github.accounts.index')
                ->with('status', __('messages.errors.github_app_not_configured'));
        }

        return redirect()->away("https://github.com/apps/{$slug}/installations/new");
    }

    /**
     * GitHub redirects here after the operator finishes installing the
     * App, with ?installation_id=... in the query string.
     */
    public function callback(Request $request): RedirectResponse
    {
        Gate::authorize('github.connect');

        $installationId = $request->integer('installation_id');

        if (!$installationId) {
            return redirect()->route('github.accounts.index')
                ->with('status', __('messages.errors.github_installation_id_missing'));
        }

        try {
            $installation = $this->client->getInstallation($installationId);
        } catch (GitHubApiException $e) {
            report($e);

            return redirect()->route('github.accounts.index')
                ->with('status', __('messages.errors.github_connection_failed'));
        }

        $account = GitHubAccount::updateOrCreate(
            ['installation_id' => $installationId],
            [
                'account_login' => $installation['account_login'],
                'account_type' => $installation['account_type'],
                'status' => GitHubAccount::STATUS_CONNECTED,
                'connected_by' => $request->user()->email,
                'connected_at' => now(),
            ],
        );

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'github_account.connected',
            subjectType: GitHubAccount::class,
            subjectId: $account->id,
            metadata: ['account_login' => $account->account_login],
        );

        // Phase 4-J: pure instrumentation for NotificationEventSubscriber.
        event(new GitHubConnected($account, $request->user()->email));

        return redirect()->route('github.accounts.index')
            ->with('status', __('messages.flash.github_account_connected', ['login' => $account->account_login]));
    }

    public function disconnect(Request $request, GitHubAccount $account): RedirectResponse
    {
        Gate::authorize('github.disconnect');

        $account->update(['status' => GitHubAccount::STATUS_DISCONNECTED]);

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'github_account.disconnected',
            subjectType: GitHubAccount::class,
            subjectId: $account->id,
        );

        return redirect()->route('github.accounts.index')
            ->with('status', __('messages.flash.github_account_disconnected', ['login' => $account->account_login]));
    }

    /**
     * Hard delete, distinct from disconnect() above — disconnect() only
     * flips status (recoverable by reconnecting the same installation);
     * this removes the row entirely. projects.github_account_id
     * ->nullOnDelete()'s this at the schema level, so any project that
     * was using this account simply loses that one link (its webhook
     * keeps working — GitHub still calls the same URL — it just shows as
     * "needs GitHub connection" again on the Readiness/Setup pages).
     */
    public function destroy(Request $request, GitHubAccount $account): RedirectResponse
    {
        Gate::authorize('github.disconnect');

        $login = $account->account_login;

        AuditLogEntry::record(
            actorType: AuditLogEntry::ACTOR_USER,
            actorIdentifier: $request->user()->email,
            action: 'github_account.deleted',
            subjectType: GitHubAccount::class,
            subjectId: $account->id,
            metadata: ['account_login' => $login],
        );

        $account->delete();

        return redirect()->route('github.accounts.index')
            ->with('status', __('messages.flash.github_account_deleted', ['login' => $login]));
    }

    public function testConnection(Request $request, GitHubAccount $account): RedirectResponse
    {
        Gate::authorize('github.connect');

        try {
            $token = $this->client->createInstallationToken($account->installation_id);
            $this->client->listRepositories($token['token']);
            $account->update(['last_tested_at' => now()]);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $request->user()->email,
                action: 'github_account.connection_tested',
                subjectType: GitHubAccount::class,
                subjectId: $account->id,
                metadata: ['result' => 'success'],
            );

            return redirect()->route('github.accounts.index')
                ->with('status', __('messages.flash.github_connection_ok', ['login' => $account->account_login]));
        } catch (GitHubApiException $e) {
            report($e);

            AuditLogEntry::record(
                actorType: AuditLogEntry::ACTOR_USER,
                actorIdentifier: $request->user()->email,
                action: 'github_account.connection_tested',
                subjectType: GitHubAccount::class,
                subjectId: $account->id,
                metadata: ['result' => 'failed'],
            );

            // Phase 4-J: pure instrumentation for NotificationEventSubscriber.
            event(new GitHubConnectionFailed($account));

            return redirect()->route('github.accounts.index')
                ->with('status', __('messages.errors.github_connection_failed'));
        }
    }

    /**
     * JSON, consumed by the Project Wizard's repository picker (vanilla
     * JS fetch — no new frontend dependency).
     */
    public function repositories(GitHubAccount $account): JsonResponse
    {
        Gate::authorize('github.view');

        try {
            $token = $this->client->createInstallationToken($account->installation_id);

            return response()->json(['repositories' => $this->client->listRepositories($token['token'])]);
        } catch (GitHubApiException $e) {
            report($e);

            return response()->json(['message' => __('messages.errors.github_connection_failed')], 502);
        }
    }

    public function branches(Request $request, GitHubAccount $account): JsonResponse
    {
        Gate::authorize('github.view');

        $validated = $request->validate([
            'owner' => 'required|string',
            'repo' => 'required|string',
        ]);

        try {
            $token = $this->client->createInstallationToken($account->installation_id);
            $branches = $this->client->listBranches($token['token'], $validated['owner'], $validated['repo']);

            return response()->json(['branches' => $branches]);
        } catch (GitHubApiException $e) {
            report($e);

            return response()->json(['message' => __('messages.errors.github_connection_failed')], 502);
        }
    }
}
