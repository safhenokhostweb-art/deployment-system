<?php

namespace Tests\Unit\Services\Security;

use App\Services\Security\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new SecretRedactor();
    }

    public function test_redacts_key_value_secret_assignments_but_keeps_the_key(): void
    {
        $result = $this->redactor->redact('export API_KEY=sk_live_abcdef123456');

        $this->assertStringNotContainsString('sk_live_abcdef123456', $result);
        $this->assertStringContainsString('API_KEY=[REDACTED]', $result);
    }

    public function test_redacts_github_tokens(): void
    {
        $result = $this->redactor->redact('Authenticating with ghp_' . str_repeat('a', 36));

        $this->assertStringNotContainsString('ghp_' . str_repeat('a', 36), $result);
        $this->assertStringContainsString('[REDACTED]', $result);
    }

    public function test_redacts_aws_access_key_ids(): void
    {
        $result = $this->redactor->redact('AKIAABCDEFGHIJKLMNOP found in env');

        $this->assertStringNotContainsString('AKIAABCDEFGHIJKLMNOP', $result);
    }

    public function test_redacts_bearer_tokens_but_keeps_the_word_bearer(): void
    {
        $result = $this->redactor->redact('curl -H "Authorization: Bearer abcd1234efgh5678ijkl"');

        $this->assertStringNotContainsString('abcd1234efgh5678ijkl', $result);
        $this->assertStringContainsString('Bearer [REDACTED]', $result);
    }

    public function test_leaves_ordinary_output_untouched(): void
    {
        $output = "Compiling assets...\n132 modules transformed.\nBuild finished in 4.2s";

        $this->assertSame($output, $this->redactor->redact($output));
    }

    public function test_handles_null_and_empty_input(): void
    {
        $this->assertNull($this->redactor->redact(null));
        $this->assertSame('', $this->redactor->redact(''));
    }
}
