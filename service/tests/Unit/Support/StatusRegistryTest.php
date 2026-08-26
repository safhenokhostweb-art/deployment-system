<?php

namespace Tests\Unit\Support;

use App\Support\Ui\StatusRegistry;
use Tests\TestCase;

class StatusRegistryTest extends TestCase
{
    public function test_required_ui_domains_and_statuses_are_registered(): void
    {
        $expected = [
            'deployment' => ['pending_approval', 'queued', 'running', 'building', 'migrating', 'transferring', 'activating', 'verifying', 'succeeded', 'failed', 'cancelled', 'partial_success', 'rolled_back'],
            'artifact' => ['uploading', 'available', 'failed', 'expired', 'missing'],
            'agent' => ['online', 'offline', 'pending', 'outdated', 'unhealthy'],
            'health' => ['pending', 'verified', 'failed', 'not_configured'],
        ];

        $this->assertSame(array_keys($expected), StatusRegistry::domains());

        foreach ($expected as $domain => $statuses) {
            $this->assertSame($statuses, array_keys(StatusRegistry::forDomain($domain)));

            foreach ($statuses as $status) {
                $definition = StatusRegistry::resolve($domain, $status);

                $this->assertContains($definition['level'], ['success', 'warning', 'danger', 'info', 'neutral']);
                $this->assertNotSame('', $definition['label']);
                $this->assertStringStartsWith('status.', $definition['translation_key']);
                $this->assertNotSame('', $definition['icon']);
                $this->assertIsBool($definition['actionable']);
                $this->assertIsBool($definition['terminal']);
            }
        }
    }

    public function test_unknown_status_has_a_safe_neutral_fallback(): void
    {
        $definition = StatusRegistry::resolve('deployment', 'future_state');

        $this->assertSame('Future State', $definition['label']);
        $this->assertSame('status.future_state', $definition['translation_key']);
        $this->assertSame('neutral', $definition['level']);
        $this->assertFalse($definition['actionable']);
        $this->assertFalse($definition['terminal']);
    }
}
