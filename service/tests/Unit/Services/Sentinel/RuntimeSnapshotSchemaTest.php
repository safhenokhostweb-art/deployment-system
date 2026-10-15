<?php

namespace Tests\Unit\Services\Sentinel;

use App\Models\Agent;
use App\Models\LocalAgent;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

class RuntimeSnapshotSchemaTest extends TestCase
{
    public function test_pending_migrations_and_model_casts_on_isolated_sqlite(): void
    {
        $previousApp = Facade::getFacadeApplication();
        $previousResolver = Agent::getConnectionResolver();
        $container = new Container();
        $database = new Manager($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $database->bootEloquent();
        $schema = $database->getConnection()->getSchemaBuilder();
        $container->instance('db.schema', $schema);
        Facade::clearResolvedInstance('db.schema');
        Facade::setFacadeApplication($container);

        try {
            foreach (['local_agents', 'agents', 'unrelated'] as $table) {
                $schema->create($table, function (Blueprint $blueprint): void {
                    $blueprint->id();
                    $blueprint->timestamp('last_seen_at')->nullable();
                });
            }
            $root = dirname(__DIR__, 4);
            $migrations = [
                'local_agents' => require $root . '/database/migrations/2026_10_06_100000_add_runtime_snapshot_to_local_agents_table.php',
                'agents' => require $root . '/database/migrations/2026_10_06_100001_add_runtime_snapshot_to_agents_table.php',
            ];
            foreach ($migrations as $table => $migration) {
                $other = $table === 'agents' ? 'local_agents' : 'agents';
                $before = $schema->getColumns($other);
                $migration->up();
                self::assertSame($before, $schema->getColumns($other));
                $columns = array_column($schema->getColumns($table), null, 'name');
                self::assertEqualsCanonicalizing(['id', 'last_seen_at', 'runtime_snapshot', 'runtime_snapshot_sequence'], array_keys($columns));
                foreach (['runtime_snapshot', 'runtime_snapshot_sequence'] as $column) {
                    self::assertTrue($columns[$column]['nullable']);
                    self::assertNull($columns[$column]['default']);
                }
                self::assertStringContainsString('int', $columns['runtime_snapshot_sequence']['type_name']);
            }
            self::assertSame(['id', 'last_seen_at'], $schema->getColumnListing('unrelated'));
            self::assertEqualsCanonicalizing(['main.agents', 'main.local_agents', 'main.unrelated'], $schema->getTableListing());

            foreach (['local_agents' => LocalAgent::class, 'agents' => Agent::class] as $table => $modelClass) {
                $database->getConnection()->table($table)->insert([
                    'runtime_snapshot' => json_encode(['current_release_path' => 'releases/dep-0001'], JSON_THROW_ON_ERROR),
                    'runtime_snapshot_sequence' => 4294967296,
                ]);
                $model = $modelClass::query()->firstOrFail();
                self::assertSame(['current_release_path' => 'releases/dep-0001'], $model->runtime_snapshot);
                self::assertSame(4294967296, $model->runtime_snapshot_sequence);
                $model->setRawAttributes(['runtime_snapshot_sequence' => '42']);
                self::assertSame(42, $model->runtime_snapshot_sequence);
                $database->getConnection()->table($table)->insert(['runtime_snapshot' => null, 'runtime_snapshot_sequence' => null]);
                $empty = $modelClass::query()->orderByDesc('id')->firstOrFail();
                self::assertNull($empty->runtime_snapshot);
                self::assertNull($empty->runtime_snapshot_sequence);
            }
            foreach ($migrations as $table => $migration) {
                $migration->down();
                self::assertSame(['id', 'last_seen_at'], $schema->getColumnListing($table));
            }
        } finally {
            $database->getConnection()->disconnect();
            if ($previousResolver !== null) {
                Agent::setConnectionResolver($previousResolver);
            } else {
                Agent::unsetConnectionResolver();
            }
            Facade::clearResolvedInstance('db.schema');
            Facade::setFacadeApplication($previousApp);
        }
    }
}
