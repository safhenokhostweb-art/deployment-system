<?php

namespace App\Services\AgentDashboard;

use Illuminate\Validation\ValidationException;

/**
 * Builds the canvas graph (agent nodes, the ReleasePreparer system node,
 * edges, live status) and applies canvas edits back to agents.json.
 */
class AgentGraphService
{
    public const STATUS_READY = 'READY';

    public const STATUS_ATTENTION = 'ATTENTION';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_IDLE = 'IDLE';

    public const STATUS_DISABLED = 'DISABLED';

    public function __construct(
        private readonly AgentRuntimeRepository $runtime,
        private readonly AgentRunManager $runs,
        private readonly int $journalTailBytes,
    ) {
    }

    /** @return array<string, mixed> */
    public function graph(): array
    {
        $registry = $this->runtime->readRegistry();
        $journal = $this->runtime->journal($this->journalTailBytes);
        $layout = $this->runtime->layout($registry);

        $nodes = [];
        $index = 0;
        foreach ($this->runtime->agents($registry) as $agentId => $entry) {
            $nodes[] = $this->agentNode($registry, $journal, $layout, $agentId, $entry, $index++);
        }
        $nodes[] = [
            'id' => AgentRuntimeRepository::RELEASE_PREPARER,
            'type' => 'system',
            'label' => 'ReleasePreparer',
            'description' => $this->runtime->releasePreparePath(),
            'enabled' => true,
            'tasks' => [],
            'inputs' => ['release-task.json'],
            'outputs' => [],
            'status' => 'SYSTEM',
            'last_outcome' => null,
            'run' => null,
            'position' => $this->position($layout, AgentRuntimeRepository::RELEASE_PREPARER, $index),
        ];

        $known = array_column($nodes, 'id');
        $edges = [];
        foreach ($layout['edges'] as $edge) {
            if (in_array($edge['source'] ?? null, $known, true) && in_array($edge['target'] ?? null, $known, true)) {
                $edges[] = [
                    'id' => $edge['source'].'->'.$edge['target'],
                    'source' => $edge['source'],
                    'target' => $edge['target'],
                    'label' => $edge['label'] ?? null,
                ];
            }
        }

        return [
            'schema' => 'agent-dashboard.graph/v1',
            'runtime' => [
                'available' => true,
                'registry_path' => $this->runtime->registryPath(),
                'journal_path' => $this->runtime->journalPath(),
                'layout_saved' => isset($registry['dashboard']),
            ],
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }

    /**
     * @param  array{nodes?: list<array<string, mixed>>, edges?: list<array<string, mixed>>}  $input  validated canvas state
     * @return array<string, mixed> the new graph
     */
    public function save(array $input): array
    {
        $registry = $this->runtime->readRegistry();
        $agents = $this->runtime->agents($registry);
        $known = array_merge(array_keys($agents), [AgentRuntimeRepository::RELEASE_PREPARER]);
        $layout = $this->runtime->layout($registry);

        $enabled = [];
        foreach ($input['nodes'] ?? [] as $i => $node) {
            $id = $node['id'];
            if (! in_array($id, $known, true)) {
                throw ValidationException::withMessages(["nodes.{$i}.id" => "Unknown node '{$id}'."]);
            }
            if (isset($node['position'])) {
                $layout['positions'][$id] = ['x' => (int) round($node['position']['x']), 'y' => (int) round($node['position']['y'])];
            }
            if (array_key_exists('enabled', $node) && isset($agents[$id])) {
                $enabled[$id] = (bool) $node['enabled'];
            }
        }

        if (array_key_exists('edges', $input)) {
            $layout['edges'] = [];
            $seen = [];
            foreach ($input['edges'] as $i => $edge) {
                [$source, $target] = [$edge['source'], $edge['target']];
                if (! in_array($source, $known, true) || ! in_array($target, $known, true)) {
                    throw ValidationException::withMessages(["edges.{$i}" => "Edge {$source} -> {$target} references an unknown node."]);
                }
                if ($source === $target) {
                    throw ValidationException::withMessages(["edges.{$i}" => 'An agent cannot be wired to itself.']);
                }
                if ($source === AgentRuntimeRepository::RELEASE_PREPARER) {
                    throw ValidationException::withMessages(["edges.{$i}" => 'ReleasePreparer has no output port.']);
                }
                if (isset($seen[$source.'->'.$target])) {
                    continue;
                }
                $seen[$source.'->'.$target] = true;
                $layout['edges'][] = ['source' => $source, 'target' => $target, 'label' => $edge['label'] ?? null];
            }
        }

        foreach ($registry['agents'] as $i => $entry) {
            if (isset($entry['id'], $enabled[$entry['id']])) {
                $registry['agents'][$i]['enabled'] = $enabled[$entry['id']];
            }
        }
        $registry['dashboard'] = array_merge((array) ($registry['dashboard'] ?? []), [
            'schema' => AgentRuntimeRepository::LAYOUT_SCHEMA,
            'positions' => $layout['positions'],
            'edges' => $layout['edges'],
        ]);
        $this->runtime->writeRegistry($registry);

        return $this->graph();
    }

    /** @return bool the agent's new enabled state */
    public function toggle(string $agentId, ?bool $enabled): bool
    {
        $registry = $this->runtime->readRegistry();
        foreach ($registry['agents'] as $i => $entry) {
            if (($entry['id'] ?? null) === $agentId) {
                $state = $enabled ?? ! (($entry['enabled'] ?? true) === true);
                $registry['agents'][$i]['enabled'] = $state;
                $this->runtime->writeRegistry($registry);

                return $state;
            }
        }
        abort(404, "Unknown agent '{$agentId}'.");
    }

    /** @return array<string, mixed> the agent's registry entry, or 404 */
    public function agent(string $agentId): array
    {
        $registry = $this->runtime->readRegistry();
        $entry = $this->runtime->agents($registry)[$agentId] ?? abort(404, "Unknown agent '{$agentId}'.");
        $entry['tasks'] = $this->runtime->tasksFor($registry, $agentId);

        return $entry;
    }

    /** @return array<string, mixed> */
    private function agentNode(array $registry, array $journal, array $layout, string $agentId, array $entry, int $index): array
    {
        $enabled = ($entry['enabled'] ?? true) === true;
        $outcome = $this->runtime->lastOutcome($journal, $agentId);
        $running = $this->runs->isRunning($agentId);
        $status = match (true) {
            $running => self::STATUS_RUNNING,
            ! $enabled => self::STATUS_DISABLED,
            $outcome === null => self::STATUS_IDLE,
            default => $outcome['verdict'],
        };
        $class = (string) ($entry['class'] ?? '');

        return [
            'id' => $agentId,
            'type' => 'agent',
            'label' => $this->label($agentId),
            'description' => $class,
            'enabled' => $enabled,
            'tasks' => $this->runtime->tasksFor($registry, $agentId),
            'inputs' => array_values(array_map(static fn (array $s): string => (string) ($s['topic'] ?? ''), $entry['subscriptions'] ?? [])),
            'outputs' => $this->runtime->emittedTopics($journal, $agentId),
            'status' => $status,
            'last_outcome' => $outcome,
            'run' => $this->runs->describe($agentId),
            'position' => $this->position($layout, $agentId, $index),
        ];
    }

    /** @return array{x: int, y: int} */
    private function position(array $layout, string $id, int $index): array
    {
        $saved = $layout['positions'][$id] ?? null;
        if (is_array($saved) && isset($saved['x'], $saved['y'])) {
            return ['x' => (int) $saved['x'], 'y' => (int) $saved['y']];
        }

        return ['x' => 80 + $index * 380, 'y' => 120 + ($index % 2) * 60];
    }

    private function label(string $agentId): string
    {
        return ucwords(str_replace('-', ' ', $agentId));
    }
}
