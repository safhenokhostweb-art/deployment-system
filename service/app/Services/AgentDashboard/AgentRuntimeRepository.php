<?php

namespace App\Services\AgentDashboard;

use JsonException;
use RuntimeException;

/**
 * Reads and writes the workspace agent runtime's files for the Visual Agent
 * Management Dashboard: the registry (agents.json), the bus journal
 * (journal.jsonl) and the sealed artifacts under var/artifacts.
 *
 * The runtime's AgentRegistry only reads `schema` and each entry's
 * `id`/`class`/`enabled`/`subscriptions`, so the dashboard keeps its canvas
 * layout under a separate top-level `dashboard` key and never writes
 * subscriptions: a canvas edge is a documented hand-off, not a routing rule.
 */
class AgentRuntimeRepository
{
    public const REGISTRY_SCHEMA = 'agents.registry/v1';

    public const LAYOUT_SCHEMA = 'agent-dashboard.layout/v1';

    public const RELEASE_PREPARER = 'release-preparer';

    /**
     * ReleasePreparer's output ports: topics announced into the runtime
     * (`bin/agent publish <topic>`) once a release it prepared is deployed.
     * Only agents subscribed to one of them can be wired out of it.
     */
    public const RELEASE_PREPARER_OUTPUTS = ['deployment.completed'];

    private ?array $workspace = null;

    public function __construct(private readonly string $runtimePath)
    {
    }

    public function runtimePath(): string
    {
        return $this->runtimePath;
    }

    public function registryPath(): string
    {
        return $this->runtimePath.'/agents.json';
    }

    public function agentBinary(): string
    {
        return $this->runtimePath.'/bin/agent';
    }

    public function workspaceRoot(): string
    {
        return $this->normalize($this->runtimePath.'/'.($this->workspace()['root'] ?? '..'));
    }

    public function journalPath(): string
    {
        return $this->workspaceRoot().'/'.($this->workspace()['runtime']['journal'] ?? 'agents/var/bus/journal.jsonl');
    }

    public function artifactsPath(): string
    {
        return $this->workspaceRoot().'/'.($this->workspace()['runtime']['artifacts'] ?? 'agents/var/artifacts');
    }

    public function releasePreparePath(): string
    {
        return $this->workspace()['deployment_system']['release_prepare'] ?? 'deployment-system/deployment/release-prepare.php';
    }

    public function isAvailable(): bool
    {
        return is_file($this->registryPath()) && is_file($this->agentBinary());
    }

    /** @return array<string, mixed> */
    public function readRegistry(): array
    {
        if (! is_file($this->registryPath())) {
            throw new RuntimeException('Agent registry not found: '.$this->registryPath());
        }
        $registry = $this->decode((string) file_get_contents($this->registryPath()), 'agents.json');
        if (($registry['schema'] ?? null) !== self::REGISTRY_SCHEMA) {
            throw new RuntimeException('Unsupported agent registry schema in agents.json.');
        }

        return $registry;
    }

    /**
     * Atomic replace (temp file + rename) so the CLI never reads a
     * half-written registry while a run is booting.
     *
     * @param  array<string, mixed>  $registry
     */
    public function writeRegistry(array $registry): void
    {
        if (($registry['schema'] ?? null) !== self::REGISTRY_SCHEMA) {
            throw new RuntimeException('Refusing to write a registry without the agents.registry/v1 schema.');
        }
        $bytes = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        $temporary = $this->registryPath().'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($temporary, $bytes, LOCK_EX) === false || ! rename($temporary, $this->registryPath())) {
            @unlink($temporary);
            throw new RuntimeException('Cannot write agent registry: '.$this->registryPath());
        }
    }

    /** @return array<string, array<string, mixed>> agent entries keyed by id */
    public function agents(array $registry): array
    {
        $agents = [];
        foreach ($registry['agents'] ?? [] as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $agents[$entry['id']] = $entry;
            }
        }

        return $agents;
    }

    /**
     * Task types an agent is known to handle. The registry only names the
     * types it subscribes with; the dashboard layout may list more.
     *
     * @return list<string>
     */
    public function tasksFor(array $registry, string $agentId): array
    {
        $entry = $this->agents($registry)[$agentId] ?? [];
        $tasks = array_map(static fn (array $s): string => (string) ($s['task'] ?? ''), $entry['subscriptions'] ?? []);
        $tasks = array_merge($tasks, (array) ($registry['dashboard']['tasks'][$agentId] ?? []));

        return array_values(array_unique(array_filter($tasks, static fn ($t): bool => is_string($t) && $t !== '')));
    }

    /** @return array{positions: array<string, array{x: int, y: int}>, edges: list<array{source: string, target: string, label: ?string}>} */
    public function layout(array $registry): array
    {
        $layout = $registry['dashboard'] ?? null;
        if (! is_array($layout)) {
            return ['positions' => [], 'edges' => $this->defaultEdges($registry)];
        }

        return [
            'positions' => (array) ($layout['positions'] ?? []),
            'edges' => array_values((array) ($layout['edges'] ?? [])),
        ];
    }

    /**
     * Until the canvas is first saved: every agent that produces release
     * readiness drafts is wired to release-prepare, which consumes them, and
     * release-prepare is wired to every agent subscribed to its outputs.
     *
     * @return list<array{source: string, target: string, label: ?string}>
     */
    public function defaultEdges(array $registry): array
    {
        $edges = [];
        foreach (array_keys($this->agents($registry)) as $agentId) {
            if (in_array('deployment.readiness', $this->tasksFor($registry, $agentId), true)) {
                $edges[] = ['source' => $agentId, 'target' => self::RELEASE_PREPARER, 'label' => 'release-task drafts'];
            }
        }
        foreach ($this->agents($registry) as $agentId => $entry) {
            $topics = $this->releasePreparerTopicsFor($entry);
            if ($topics !== []) {
                $edges[] = ['source' => self::RELEASE_PREPARER, 'target' => $agentId, 'label' => implode(', ', $topics)];
            }
        }

        return $edges;
    }

    /**
     * The ReleasePreparer outputs an agent entry subscribes to, by the
     * runtime's Message::matches() rule: `*` matches everything, `a.b.*`
     * matches `a.b.` and deeper, otherwise exact.
     *
     * @return list<string>
     */
    public function releasePreparerTopicsFor(array $entry): array
    {
        $patterns = array_map(static fn (array $s): string => (string) ($s['topic'] ?? ''), $entry['subscriptions'] ?? []);

        return array_values(array_filter(self::RELEASE_PREPARER_OUTPUTS, static function (string $topic) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if ($pattern === '*' || $pattern === $topic || (str_ends_with($pattern, '.*') && str_starts_with($topic, substr($pattern, 0, -1)))) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * The tail of the journal, oldest first. Only the last
     * $maxBytes are read; a partial first line is dropped, and a corrupt
     * line is skipped rather than failing the whole dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function journal(int $maxBytes): array
    {
        $path = $this->journalPath();
        if (! is_file($path)) {
            return [];
        }
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $offset = max(0, $size - $maxBytes);
        fseek($handle, $offset);
        $raw = (string) stream_get_contents($handle);
        fclose($handle);

        $lines = preg_split('/\r?\n/', $raw) ?: [];
        if ($offset > 0) {
            array_shift($lines);
        }
        $messages = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $message = json_decode($line, true);
            if (is_array($message) && isset($message['topic'])) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /** Whether a journal message was produced by, or about, the agent. */
    public function concerns(array $message, string $agentId): bool
    {
        return ($message['source'] ?? null) === $agentId || ($message['payload']['agent'] ?? null) === $agentId;
    }

    /**
     * Messages of the agent's correlation chains, newest last.
     *
     * @param  list<array<string, mixed>>  $journal
     * @return list<array<string, mixed>>
     */
    public function journalFor(array $journal, string $agentId, int $limit): array
    {
        $correlations = [];
        foreach ($journal as $message) {
            if ($this->concerns($message, $agentId) && isset($message['correlation_id'])) {
                $correlations[$message['correlation_id']] = true;
            }
        }
        $related = array_values(array_filter(
            $journal,
            fn (array $m): bool => $this->concerns($m, $agentId) || isset($correlations[$m['correlation_id'] ?? '']),
        ));

        return array_slice($related, -$limit);
    }

    /**
     * The last completed task of the agent with the agent's verdict derived
     * from its finding counts (same rule as the runtime: any blocker →
     * BLOCKED, any warning → ATTENTION, else READY).
     *
     * @param  list<array<string, mixed>>  $journal
     * @return array<string, mixed>|null
     */
    public function lastOutcome(array $journal, string $agentId): ?array
    {
        for ($i = count($journal) - 1; $i >= 0; $i--) {
            $message = $journal[$i];
            if (($message['topic'] ?? null) !== 'task.completed' || ($message['payload']['agent'] ?? null) !== $agentId) {
                continue;
            }
            $payload = $message['payload'];
            $counts = (array) ($payload['findings'] ?? []);
            $status = (string) ($payload['status'] ?? 'FAILED');
            $verdict = match (true) {
                $status !== 'PASS' => 'BLOCKED',
                ($counts['blocker'] ?? 0) > 0 => 'BLOCKED',
                ($counts['warning'] ?? 0) > 0 => 'ATTENTION',
                default => 'READY',
            };

            return [
                'task_type' => $payload['task_type'] ?? null,
                'status' => $status,
                'verdict' => $verdict,
                'summary' => $payload['summary'] ?? null,
                'error' => $payload['error'] ?? null,
                'counts' => $counts,
                'duration_ms' => $payload['duration_ms'] ?? null,
                'correlation_id' => $message['correlation_id'] ?? null,
                'occurred_at' => $message['occurred_at'] ?? null,
            ];
        }

        return null;
    }

    /**
     * The agent's output ports: the events its registry entry declares under
     * `produces.events` (shown even before it has ever run), then any other
     * topic it has published to the journal.
     *
     * @param  list<array<string, mixed>>  $journal
     * @return list<string>
     */
    public function outputTopics(array $entry, array $journal, string $agentId): array
    {
        $declared = array_filter((array) ($entry['produces']['events'] ?? []), static fn ($t): bool => is_string($t) && $t !== '');

        return array_values(array_unique(array_merge($declared, $this->emittedTopics($journal, $agentId))));
    }

    /**
     * Topics the agent itself published.
     *
     * @param  list<array<string, mixed>>  $journal
     * @return list<string>
     */
    public function emittedTopics(array $journal, string $agentId): array
    {
        $topics = [];
        foreach ($journal as $message) {
            if (($message['source'] ?? null) === $agentId) {
                $topics[(string) $message['topic']] = true;
            }
        }

        return array_keys($topics);
    }

    /**
     * The agent's newest sealed artifact, re-verified here exactly as
     * `bin/agent verify` does. Its path comes from the journal, so it is
     * only read when it resolves inside the runtime's artifacts directory.
     *
     * @param  list<array<string, mixed>>  $journal
     * @return array<string, mixed>|null
     */
    public function latestArtifact(array $journal, string $agentId, int $previewBytes): ?array
    {
        for ($i = count($journal) - 1; $i >= 0; $i--) {
            $message = $journal[$i];
            if (($message['topic'] ?? null) === 'artifact.created' && ($message['payload']['agent'] ?? null) === $agentId) {
                return $this->inspectArtifact($message['payload'], $previewBytes);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function inspectArtifact(array $reference, int $previewBytes): array
    {
        $result = [
            'artifact_id' => $reference['artifact_id'] ?? null,
            'kind' => $reference['kind'] ?? null,
            'path' => $reference['path'] ?? null,
            'digest' => $reference['digest'] ?? null,
            'computed_digest' => null,
            'verified' => false,
            'errors' => [],
            'created_at' => null,
            'findings' => [],
            'content' => null,
            'truncated' => false,
        ];

        $root = realpath($this->artifactsPath());
        $path = is_string($reference['path'] ?? null) ? realpath($reference['path']) : false;
        if ($root === false || $path === false || ! str_starts_with($this->normalize($path), $this->normalize($root).'/')) {
            $result['errors'][] = 'Artifact file is missing or outside the runtime artifacts directory.';

            return $result;
        }

        $raw = (string) file_get_contents($path);
        $result['content'] = strlen($raw) > $previewBytes ? substr($raw, 0, $previewBytes) : $raw;
        $result['truncated'] = strlen($raw) > $previewBytes;

        try {
            $envelope = $this->decode($raw, 'artifact.json');
        } catch (RuntimeException $exception) {
            $result['errors'][] = $exception->getMessage();

            return $result;
        }

        $integrity = (array) ($envelope['integrity'] ?? []);
        unset($envelope['integrity']);
        $result['computed_digest'] = hash('sha256', $this->canonical($envelope));
        $result['created_at'] = $envelope['created_at'] ?? null;
        $result['findings'] = array_values((array) ($envelope['payload']['findings'] ?? []));

        if (($envelope['schema'] ?? null) !== 'agents.artifact/v1') {
            $result['errors'][] = 'Unknown artifact schema.';
        }
        if (($integrity['digest'] ?? null) !== $result['computed_digest']) {
            $result['errors'][] = 'Envelope digest mismatch: artifact.json was modified after it was sealed.';
        }
        if ($result['digest'] !== null && $result['digest'] !== ($integrity['digest'] ?? null)) {
            $result['errors'][] = 'Journal digest does not match the artifact envelope.';
        }
        foreach ((array) ($envelope['attachments'] ?? []) as $name => $meta) {
            $file = dirname($path).'/'.$name;
            if (! is_file($file)) {
                $result['errors'][] = "Attachment missing: {$name}";
            } elseif (hash_file('sha256', $file) !== ($meta['sha256'] ?? null)) {
                $result['errors'][] = "Attachment digest mismatch: {$name}";
            }
        }
        $result['verified'] = $result['errors'] === [];

        return $result;
    }

    /** Byte-identical to Workspace\Agents\Support\Json::canonical(). */
    public function canonical(mixed $value): string
    {
        return json_encode($this->sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->sortKeys(...), $value);
    }

    /** @return array<string, mixed> */
    private function workspace(): array
    {
        if ($this->workspace === null) {
            $file = $this->runtimePath.'/workspace.json';
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $this->workspace = is_array($decoded) ? $decoded : [];
        }

        return $this->workspace;
    }

    /** @return array<string, mixed> */
    private function decode(string $raw, string $label): array
    {
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid JSON in {$label}: ".$exception->getMessage());
        }
        if (! is_array($value)) {
            throw new RuntimeException("Expected a JSON object in {$label}.");
        }

        return $value;
    }

    /** Forward slashes and resolved `..` segments, without requiring the path to exist. */
    private function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = preg_match('#^([A-Za-z]:)?/#', $path, $m) === 1 ? $m[0] : '';
        $parts = [];
        foreach (explode('/', substr($path, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..' && $parts !== [] && end($parts) !== '..') {
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }

        return rtrim($prefix.implode('/', $parts), '/');
    }
}
