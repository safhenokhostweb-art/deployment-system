<?php

namespace Database\Factories;

use App\Models\LocalAgent;
use App\Services\Security\AgentCredentialService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class LocalAgentFactory extends Factory
{
    protected $model = LocalAgent::class;

    /**
     * Plain-text credentials for the most recently created *approved*
     * local agent, since only the hash/encrypted form is persisted.
     */
    public static ?string $lastPlainToken = null;
    public static ?string $lastPlainSecret = null;
    public static ?string $lastPlainRegistrationToken = null;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'status' => LocalAgent::STATUS_PENDING_APPROVAL,
            'role' => LocalAgent::ROLE_DEVELOPMENT,
            'name' => $this->faker->userName() . '-laptop',
            'hostname' => $this->faker->domainWord() . '-pc',
            'operating_system' => 'windows',
            'capabilities' => ['git' => true, 'php' => '8.4', 'assigned_artifact_publish_job_id' => true],
            // Phase: Laravel Release Completeness — an approved agent in
            // tests is a current agent; the artifact-upload gate rejects
            // older/unknown versions, so the factory mirrors the current
            // minimum from config instead of leaving it null.
            'agent_version' => (string) config('deployment.latest_local_agent_version'),
        ];
    }

    public function approved(): self
    {
        return $this->state(function () {
            $credentials = app(AgentCredentialService::class);
            self::$lastPlainToken = $credentials->generateToken();
            self::$lastPlainSecret = $credentials->generateSigningSecret();

            return [
                'status' => LocalAgent::STATUS_APPROVED,
                'agent_token_hash' => $credentials->hashToken(self::$lastPlainToken),
                'signing_secret' => self::$lastPlainSecret,
                'approved_by' => 'test-admin@example.test',
                'approved_at' => now(),
            ];
        });
    }

    public function productionBuild(): self
    {
        return $this->approved()->state([
            'role' => LocalAgent::ROLE_PRODUCTION_BUILD,
            'last_seen_at' => now(),
            'capabilities' => [
                'git' => true,
                'php' => '8.4',
                'laravel_release_manifest' => true,
                'assigned_artifact_publish_job_id' => true,
                // Root-cause fix (commit 9f7adfd shipped this capability
                // without a version bump, leaving a real Production
                // build job unclaimable): a factory-created
                // production_build agent is a CURRENT agent by
                // convention (see agent_version above, already pinned to
                // config('deployment.latest_local_agent_version')) — see
                // LocalAgent::supportsServerAgentPackageBuild() for the
                // version+capability pair this backs.
                'server_agent_package_build' => true,
            ],
        ]);
    }

    public function disabled(): self
    {
        return $this->approved()->state(['status' => LocalAgent::STATUS_DISABLED]);
    }

    public function pendingRegistration(): self
    {
        return $this->state(function () {
            $credentials = app(AgentCredentialService::class);
            self::$lastPlainRegistrationToken = $credentials->generateRegistrationToken();

            return [
                'name' => null,
                'hostname' => null,
                'operating_system' => null,
                'capabilities' => null,
                'registration_token' => self::$lastPlainRegistrationToken,
                'registration_token_expires_at' => now()->addMinutes(30),
            ];
        });
    }
}
