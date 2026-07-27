<?php

namespace App\Services\GitHub;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the GitHub App API (docs/PHASE_4E_GITHUB_PROJECT_WIZARD_REPORT.md
 * §3). Deliberately holds no long-lived credential in memory beyond the
 * App's own private key (env-only, never persisted) — every call to the
 * GitHub API on behalf of an installation first exchanges a freshly
 * signed JWT for a short-lived (1 hour) installation access token.
 *
 * No new Composer dependency: the JWT (RS256) is built by hand with
 * PHP's built-in openssl_sign, and API calls use Laravel's own Http
 * facade — both already present in every Laravel app.
 */
class GitHubAppClient
{
    private const API_BASE = 'https://api.github.com';

    private readonly ?string $appId;

    private readonly ?string $privateKey;

    public function __construct(?string $appId = null, ?string $privateKey = null)
    {
        $this->appId = $appId ?? (string) config('services.github_app.id');
        $this->privateKey = $privateKey ?? (string) config('services.github_app.private_key');
    }

    public function isConfigured(): bool
    {
        return $this->appId !== '' && $this->appId !== null
            && $this->privateKey !== '' && $this->privateKey !== null;
    }

    /**
     * @return array{token: string, expires_at: Carbon}
     */
    public function createInstallationToken(int $installationId): array
    {
        $this->assertConfigured();

        $response = Http::withToken($this->generateAppJwt())
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->post(self::API_BASE . "/app/installations/{$installationId}/access_tokens");

        if ($response->failed()) {
            throw new GitHubApiException(
                'Failed to create installation access token: ' . $this->summarizeError($response)
            );
        }

        return [
            'token' => (string) $response->json('token'),
            'expires_at' => Carbon::parse($response->json('expires_at')),
        ];
    }

    /**
     * @return array{account_login: string, account_type: string}
     */
    public function getInstallation(int $installationId): array
    {
        $this->assertConfigured();

        $response = Http::withToken($this->generateAppJwt())
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->get(self::API_BASE . "/app/installations/{$installationId}");

        if ($response->failed()) {
            throw new GitHubApiException('Failed to look up installation: ' . $this->summarizeError($response));
        }

        return [
            'account_login' => (string) $response->json('account.login'),
            'account_type' => (string) $response->json('account.type'),
        ];
    }

    /**
     * @return array<int, array{name: string, full_name: string, owner: string, private: bool, default_branch: string}>
     */
    public function listRepositories(string $installationToken): array
    {
        $response = Http::withToken($installationToken)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->get(self::API_BASE . '/installation/repositories', ['per_page' => 100]);

        if ($response->failed()) {
            throw new GitHubApiException('Failed to list repositories: ' . $this->summarizeError($response));
        }

        return array_map(
            static fn (array $repo) => [
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'owner' => $repo['owner']['login'] ?? '',
                'private' => (bool) ($repo['private'] ?? false),
                'default_branch' => $repo['default_branch'] ?? 'main',
            ],
            $response->json('repositories', []),
        );
    }

    /**
     * @return array<int, string>
     */
    public function listBranches(string $installationToken, string $owner, string $repo): array
    {
        $response = Http::withToken($installationToken)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->get(self::API_BASE . "/repos/{$owner}/{$repo}/branches", ['per_page' => 100]);

        if ($response->failed()) {
            throw new GitHubApiException('Failed to list branches: ' . $this->summarizeError($response));
        }

        return array_map(static fn (array $branch) => $branch['name'], $response->json() ?? []);
    }

    /**
     * Registers the project's existing per-project webhook (URL + secret
     * are unchanged — see App\Http\Controllers\Api\GitHubWebhookController)
     * on the repository via the API, instead of asking the operator to
     * paste them into GitHub's UI by hand.
     */
    public function createWebhook(string $installationToken, string $owner, string $repo, string $webhookUrl, string $secret): void
    {
        $response = Http::withToken($installationToken)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->post(self::API_BASE . "/repos/{$owner}/{$repo}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => ['push'],
                'config' => [
                    'url' => $webhookUrl,
                    'content_type' => 'json',
                    'secret' => $secret,
                    'insecure_ssl' => '0',
                ],
            ]);

        if ($response->failed()) {
            throw new GitHubApiException('Failed to create webhook: ' . $this->summarizeError($response));
        }
    }

    /**
     * @return array<int, array{id: int, url: string}>
     */
    public function listWebhooks(string $token, string $owner, string $repo): array
    {
        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->get(self::API_BASE . "/repos/{$owner}/{$repo}/hooks", ['per_page' => 100]);

        if ($response->failed()) {
            throw new GitHubApiException('Failed to list webhooks: ' . $this->summarizeError($response));
        }

        return array_map(
            static fn (array $hook) => ['id' => (int) $hook['id'], 'url' => (string) ($hook['config']['url'] ?? '')],
            $response->json() ?? [],
        );
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new GitHubApiException('GitHub App is not configured (GITHUB_APP_ID / GITHUB_APP_PRIVATE_KEY missing).');
        }
    }

    /**
     * RS256 JWT authenticating as the App itself (not an installation) —
     * required to look up installations and mint installation tokens.
     * Max lifetime GitHub accepts is 10 minutes; kept short deliberately.
     */
    private function generateAppJwt(): string
    {
        $this->assertConfigured();

        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => $this->appId,
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signingInput = implode('.', $segments);

        $privateKey = openssl_pkey_get_private($this->privateKey);
        if ($privateKey === false) {
            throw new GitHubApiException('GITHUB_APP_PRIVATE_KEY is not a valid PEM private key.');
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new GitHubApiException('Failed to sign GitHub App JWT.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function summarizeError(\Illuminate\Http\Client\Response $response): string
    {
        return $response->status() . ' ' . (string) ($response->json('message') ?? $response->reason());
    }
}
