<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Server;
use App\Services\Security\AgentCredentialService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /**
     * Plain-text credentials for the most recently created *approved*
     * agent, since only the hash/encrypted form is persisted.
     */
    public static ?string $lastPlainToken = null;
    public static ?string $lastPlainSecret = null;
    public static ?string $lastPlainRegistrationToken = null;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'server_id' => Server::factory(),
            'status' => Agent::STATUS_PENDING_APPROVAL,
            'name' => $this->faker->domainWord() . '-agent',
            'os' => 'linux',
            'architecture' => 'x86_64',
            'capabilities' => ['php' => '8.4', 'composer' => true, 'docker' => true],
        ];
    }

    public function approved(): self
    {
        return $this->state(function () {
            $credentials = app(AgentCredentialService::class);
            self::$lastPlainToken = $credentials->generateToken();
            self::$lastPlainSecret = $credentials->generateSigningSecret();

            return [
                'status' => Agent::STATUS_APPROVED,
                'agent_token_hash' => $credentials->hashToken(self::$lastPlainToken),
                'signing_secret' => self::$lastPlainSecret,
                'approved_by' => 'test-admin@example.test',
                'approved_at' => now(),
            ];
        });
    }

    public function disabled(): self
    {
        return $this->approved()->state(['status' => Agent::STATUS_DISABLED]);
    }

    public function pendingRegistration(): self
    {
        return $this->state(function () {
            $credentials = app(AgentCredentialService::class);
            self::$lastPlainRegistrationToken = $credentials->generateRegistrationToken();

            return [
                'name' => null,
                'os' => null,
                'architecture' => null,
                'capabilities' => null,
                'registration_token' => self::$lastPlainRegistrationToken,
                'registration_token_expires_at' => now()->addMinutes(30),
            ];
        });
    }
}
