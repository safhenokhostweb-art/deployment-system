<?php

namespace Tests\Unit\Support\Deployment;

use App\Support\Deployment\ServerAgentVersionResolver;
use Tests\TestCase;

/**
 * Mirrors LocalAgentVersionResolverTest exactly, for the independent
 * Server Agent version marker — see ServerAgentVersionResolver's own
 * docblock for why this is a separate class/marker rather than a shared
 * one.
 */
class ServerAgentVersionResolverTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/server-agent-version-resolver-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmp);
        parent::tearDown();
    }

    public function test_canonical_reads_the_real_marker(): void
    {
        $this->assertSame('1.4.3', ServerAgentVersionResolver::canonical());
    }

    public function test_canonical_reads_the_version_from_a_well_formed_marker(): void
    {
        $path = $this->writeMarker(['version' => '1.4.1']);

        $this->assertSame('1.4.1', ServerAgentVersionResolver::canonical($path));
    }

    public function test_canonical_resolves_with_no_sibling_agent_directory_present(): void
    {
        $serviceRoot = $this->tmp . '/only-service-exists';
        mkdir($serviceRoot . '/.deployment', 0777, true);
        file_put_contents($serviceRoot . '/.deployment/server-agent-version.json', json_encode(['version' => '1.4.1']));

        $this->assertDirectoryDoesNotExist(dirname($serviceRoot) . '/agent');

        $this->assertSame('1.4.1', ServerAgentVersionResolver::canonical($serviceRoot . '/.deployment/server-agent-version.json'));
    }

    public function test_canonical_fails_closed_when_the_marker_is_missing(): void
    {
        $this->expectException(\RuntimeException::class);

        ServerAgentVersionResolver::canonical($this->tmp . '/does-not-exist.json');
    }

    public function test_canonical_fails_closed_on_invalid_json(): void
    {
        $path = $this->tmp . '/malformed.json';
        file_put_contents($path, '{not valid json');

        $this->expectException(\RuntimeException::class);

        ServerAgentVersionResolver::canonical($path);
    }

    public function test_canonical_fails_closed_when_the_version_key_is_missing(): void
    {
        $path = $this->writeMarker(['not_version' => '1.4.1']);

        $this->expectException(\RuntimeException::class);

        ServerAgentVersionResolver::canonical($path);
    }

    public function test_canonical_fails_closed_on_a_malformed_version(): void
    {
        $this->expectException(\RuntimeException::class);

        ServerAgentVersionResolver::canonical($this->writeMarker(['version' => 'v1.4.1']));
    }

    private function writeMarker(array $contents): string
    {
        $path = $this->tmp . '/marker-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($contents));

        return $path;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
