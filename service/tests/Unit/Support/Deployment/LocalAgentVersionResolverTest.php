<?php

namespace Tests\Unit\Support\Deployment;

use App\Support\Deployment\LocalAgentVersionResolver;
use Tests\TestCase;

/**
 * LocalAgentVersionResolver::canonical()'s own parsing/validation
 * contract, exercised against synthetic marker fixtures rather than the
 * real repo checkout (see LocalAgentVersionSyncTest for the cross-file
 * "does the real marker match the real agent trees" contract).
 *
 * The point of this suite: canonical() must work from nothing but the
 * in-service marker file — a "clean production layout" (only service/
 * exists, no local-agent/ or deployment-local-agent/ next to it at all)
 * must resolve correctly, and every malformed-marker shape must fail
 * closed rather than silently falling back to something stale or wrong.
 */
class LocalAgentVersionResolverTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/local-agent-version-resolver-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tmp);
        parent::tearDown();
    }

    public function test_canonical_reads_the_version_from_a_well_formed_marker(): void
    {
        $path = $this->writeMarker(['version' => '1.5.4']);

        $this->assertSame('1.5.4', LocalAgentVersionResolver::canonical($path));
    }

    /**
     * The exact "clean production layout" a real Artifact-based deploy of
     * deployment-system produces: only a service/-shaped directory tree
     * exists at all — no local-agent/, no deployment-local-agent/, no
     * ".." above it that resolves to anything meaningful. canonical()
     * must never need to reach outside the marker's own directory.
     */
    public function test_canonical_resolves_from_a_clean_production_layout_with_no_sibling_agent_trees(): void
    {
        $serviceRoot = $this->tmp . '/only-service-exists';
        mkdir($serviceRoot . '/.deployment', 0777, true);
        file_put_contents($serviceRoot . '/.deployment/local-agent-version.json', json_encode(['version' => '1.5.4']));

        $this->assertDirectoryDoesNotExist(dirname($serviceRoot) . '/local-agent');
        $this->assertDirectoryDoesNotExist(dirname($serviceRoot) . '/deployment-local-agent');

        $this->assertSame(
            '1.5.4',
            LocalAgentVersionResolver::canonical($serviceRoot . '/.deployment/local-agent-version.json'),
        );
    }

    public function test_canonical_ignores_a_stale_sibling_agent_tree_even_when_one_happens_to_exist(): void
    {
        $serviceRoot = $this->tmp . '/service-with-stale-siblings';
        mkdir($serviceRoot . '/.deployment', 0777, true);
        file_put_contents($serviceRoot . '/.deployment/local-agent-version.json', json_encode(['version' => '1.5.4']));

        mkdir(dirname($serviceRoot) . '/local-agent', 0777, true);
        file_put_contents(dirname($serviceRoot) . '/local-agent/agent.php', "<?php\nconst LOCAL_AGENT_VERSION = '1.4.3';\n");
        mkdir(dirname($serviceRoot) . '/deployment-local-agent/agent', 0777, true);
        file_put_contents(dirname($serviceRoot) . '/deployment-local-agent/agent/agent.php', "<?php\nconst LOCAL_AGENT_VERSION = '1.4.3';\n");

        $this->assertSame(
            '1.5.4',
            LocalAgentVersionResolver::canonical($serviceRoot . '/.deployment/local-agent-version.json'),
        );
    }

    public function test_canonical_fails_closed_when_the_marker_is_missing(): void
    {
        $this->expectException(\RuntimeException::class);

        LocalAgentVersionResolver::canonical($this->tmp . '/does-not-exist.json');
    }

    public function test_canonical_fails_closed_on_invalid_json(): void
    {
        $path = $this->tmp . '/malformed.json';
        file_put_contents($path, '{not valid json');

        $this->expectException(\RuntimeException::class);

        LocalAgentVersionResolver::canonical($path);
    }

    public function test_canonical_fails_closed_when_the_version_key_is_missing(): void
    {
        $path = $this->writeMarker(['not_version' => '1.5.4']);

        $this->expectException(\RuntimeException::class);

        LocalAgentVersionResolver::canonical($path);
    }

    public function test_canonical_fails_closed_on_a_two_part_version(): void
    {
        $this->expectException(\RuntimeException::class);

        LocalAgentVersionResolver::canonical($this->writeMarker(['version' => '1.5']));
    }

    public function test_canonical_fails_closed_on_a_non_numeric_segment(): void
    {
        $this->expectException(\RuntimeException::class);

        LocalAgentVersionResolver::canonical($this->writeMarker(['version' => '1.5.x']));
    }

    public function test_canonical_fails_closed_on_a_v_prefixed_version(): void
    {
        $this->expectException(\RuntimeException::class);

        LocalAgentVersionResolver::canonical($this->writeMarker(['version' => 'v1.5.4']));
    }

    public function test_canonical_fails_closed_on_an_empty_version_string(): void
    {
        $this->expectException(\RuntimeException::class);

        LocalAgentVersionResolver::canonical($this->writeMarker(['version' => '']));
    }

    public function test_canonical_fails_closed_on_a_version_with_a_prerelease_suffix(): void
    {
        $this->expectException(\RuntimeException::class);

        LocalAgentVersionResolver::canonical($this->writeMarker(['version' => '1.5.4-beta']));
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
