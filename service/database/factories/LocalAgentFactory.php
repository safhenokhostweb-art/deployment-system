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
            'name' => $this->faker->userName() . '-laptop',
            'hostname' => $this->faker->domainWord() . '-pc',
            'operating_system' => 'windows',
            'capabilities' => ['git' => true, 'php' => '8.4'],
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
