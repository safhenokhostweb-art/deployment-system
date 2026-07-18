<?php

namespace Database\Factories;

use App\Services\Security\AgentCredentialService;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerFactory extends Factory
{
    /**
     * Plain-text credentials for the most recently created server, since
     * only the hash/encrypted form is persisted. Tests read this to build
     * signed requests.
     */
    public static ?string $lastPlainToken = null;
    public static ?string $lastPlainSecret = null;

    public function definition(): array
    {
        $credentials = app(AgentCredentialService::class);

        self::$lastPlainToken = $credentials->generateToken();
        self::$lastPlainSecret = $credentials->generateSigningSecret();

        return [
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => $this->faker->domainWord() . '-server',
            'description' => $this->faker->sentence(),
            'agent_token_hash' => $credentials->hashToken(self::$lastPlainToken),
            'signing_secret' => self::$lastPlainSecret,
            'is_active' => true,
        ];
    }
}
