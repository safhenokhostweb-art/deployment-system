<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\LocalAgent;
use App\Models\Project;
use App\Models\Release;
use App\Services\Security\RequestSigner;
use App\Services\Sentinel\AgentRuntimeEvidenceReader;
use App\Services\Sentinel\ReleaseExpectationProvider;
use App\Services\Sentinel\SentinelCollectionTarget;
use App\Services\Sentinel\SentinelReleaseExpectation;
use App\Services\Sentinel\SentinelRuntimeEvidenceContract;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

/**
 * Dedicated model-backed SQLite ledger. No application bootstrap, queue,
 * scheduler, Production services, existing identity or existing config.
 */
final class SentinelLocalHubAdapter implements ReleaseExpectationProvider
{
    private ?PDO $pdo = null;
    private ?Manager $models = null;
    private ?Container $container = null;
    private ?array $identity = null;

    private bool $inMemoryFixture = false;
    public function __construct(private readonly SentinelLocalProofFilesystem $filesystem) {}

    /** Unit-test backend only: it can never select an existing database. */
    public static function inMemoryFixture(SentinelLocalProofFilesystem $filesystem): self
    {
        $adapter = new self($filesystem);
        $adapter->inMemoryFixture = true;
        return $adapter;
    }

    public function initialize(bool $authorized = false): void
    {
        if (!$authorized) { throw new RuntimeException('proof_execution_not_authorized'); }
        $path = $this->filesystem->assertContained($this->filesystem->config->databasePath());
        if (!$this->inMemoryFixture && file_exists($path)) { throw new RuntimeException('proof_database_already_exists'); }
        $this->openDatabase(true);
        foreach ([
            'projects' => 'project_key TEXT, branch TEXT, environment TEXT, name TEXT, repository TEXT, requires_complete_artifact INTEGER',
            'releases' => 'project_id INTEGER, commit_hash TEXT, branch TEXT, status TEXT, source TEXT, readiness_policy TEXT',
            'artifacts' => 'project_id INTEGER, release_id INTEGER, commit_hash TEXT, branch TEXT, checksum_sha256 TEXT, size_bytes INTEGER, disk_path TEXT, status TEXT',
            'deployments' => 'project_id INTEGER, release_id INTEGER, artifact_id INTEGER, commit_hash TEXT, branch TEXT, status TEXT, action TEXT, deployed_at TEXT',
            'local_agents' => 'status TEXT, agent_version TEXT, runtime_snapshot TEXT, runtime_snapshot_sequence INTEGER, last_seen_at TEXT, agent_token_hash TEXT, signing_secret TEXT',
            'agents' => 'status TEXT, runtime_snapshot TEXT, runtime_snapshot_sequence INTEGER',
        ] as $table => $columns) {
            $this->pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE NOT NULL, $columns, created_at TEXT, updated_at TEXT)");
        }
        $this->pdo->exec('CREATE TABLE heartbeat_nonces (nonce TEXT PRIMARY KEY, observed_at INTEGER NOT NULL)');
        $token = bin2hex(random_bytes(32));
        $secret = bin2hex(random_bytes(32));
        $agent = $this->saveModel(LocalAgent::class, [
            'status' => LocalAgent::STATUS_APPROVED,
            'agent_token_hash' => hash('sha256', $token),
            // Raw isolated ledger value, never passed through encryption without an independent app key.
        ]);
        $statement = $this->pdo->prepare('UPDATE local_agents SET signing_secret=? WHERE id=?');
        $statement->execute([$secret, $agent['id']]);
        $this->identity = ['public_id' => $agent['public_id'], 'token' => $token, 'secret' => $secret];
    }

    public function attach(): void
    {
        $this->openDatabase(false);
    }

    private function openDatabase(bool $allowCreate): void
    {
        if ($this->pdo !== null) { return; }
        $path = $this->filesystem->assertContained($this->filesystem->config->databasePath());
        if (!$allowCreate && !$this->inMemoryFixture && !is_file($path)) { throw new RuntimeException('proof_database_missing'); }
        $database = $this->inMemoryFixture ? ':memory:' : $path;
        $this->pdo = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA busy_timeout=5000');
        $container = new Container();
        $container->instance('config', new Repository(['deployment' => ['runtime_snapshot_freshness_seconds' => 300]]));
        $this->models = new Manager($container);
        $this->models->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);
        $this->models->getConnection()->setPdo($this->pdo);
        $this->container = $container;
    }

    /** Only the newly created run identity; never an existing Agent credential. */
    public function identity(): array
    {
        if ($this->identity === null) { throw new RuntimeException('proof_identity_unavailable'); }
        return $this->identity;
    }

    public function withModels(callable $operation): mixed
    {
        $this->attach();
        $previous = Model::getConnectionResolver();
        $previousContainer = Container::getInstance();
        try {
            Model::setConnectionResolver($this->models->getDatabaseManager());
            Container::setInstance($this->container);
            return $operation();
        } finally {
            if ($previous !== null) { Model::setConnectionResolver($previous); } else { Model::unsetConnectionResolver(); }
            Container::setInstance($previousContainer);
        }
    }

    private function saveModel(string $class, array $attributes): array
    {
        try {
        return $this->withModels(function () use ($class, $attributes): array {
            // Isolated persistence adapter creates real identities, but deliberately runs no listeners/jobs.
            return Model::withoutEvents(function () use ($class, $attributes): array {
                $record = new $class();
                $record->forceFill(['public_id' => (string) Str::uuid()] + $attributes);
                $record->save();
                return $record->getAttributes();
            });
        });
        } catch (\Throwable $error) {
            throw new SentinelProofFailure('REGISTER_' . strtoupper(class_basename($class)), $error);
        }
    }

    public function register(array $artifact): array
    {
        $config = $this->filesystem->config;
        (new SentinelLocalArtifactAdapter($this->filesystem))->validateManifest($artifact['manifest']);
        $this->filesystem->assertContained($artifact['path']);
        if (($artifact['manifest']['project_key'] ?? null) !== $config->projectKey
            || ($artifact['manifest']['commit_sha'] ?? null) !== $config->commitSha
            || $artifact['size'] !== filesize($artifact['path'])
            || !hash_equals($artifact['checksum'], hash_file('sha256', $artifact['path']))) {
            throw new RuntimeException('proof_registration_mismatch');
        }
        $project = $this->saveModel(Project::class, ['project_key' => $config->projectKey,
            'name' => $config->projectKey, 'environment' => 'local', 'branch' => $config->branch,
            'repository' => $config->sourceRepository, 'requires_complete_artifact' => true]);
        $release = $this->saveModel(Release::class, ['project_id' => $project['id'],
            'commit_hash' => $config->commitSha, 'branch' => $config->branch,
            'status' => Release::STATUS_SOURCE_RECEIVED, 'source' => 'local_proof',
            'readiness_policy' => Release::READINESS_POLICY_EXACT_COMMIT]);
        $storedArtifact = $this->saveModel(Artifact::class, ['project_id' => $project['id'],
            'release_id' => $release['id'], 'commit_hash' => $config->commitSha,
            'branch' => $config->branch, 'checksum_sha256' => $artifact['checksum'],
            'size_bytes' => $artifact['size'], 'disk_path' => $artifact['path'],
            'status' => Artifact::STATUS_AVAILABLE]);
        $deployment = $this->saveModel(Deployment::class, ['project_id' => $project['id'],
            'release_id' => $release['id'], 'artifact_id' => $storedArtifact['id'],
            'commit_hash' => $config->commitSha, 'branch' => $config->branch,
            'action' => Deployment::ACTION_DEPLOY, 'status' => 'pending_local_activation']);
        return ['project' => $project, 'release' => $release, 'artifact' => $storedArtifact, 'deployment' => $deployment];
    }

    public function deployment(string $publicId): ?array
    {
        $this->attach();
        $query = $this->pdo->prepare('SELECT d.*, r.public_id AS release_public_id, a.disk_path, a.checksum_sha256, p.project_key, p.environment FROM deployments d JOIN releases r ON r.id=d.release_id JOIN artifacts a ON a.id=d.artifact_id JOIN projects p ON p.id=d.project_id WHERE d.public_id=?');
        $query->execute([$publicId]);
        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function expectation(SentinelCollectionTarget $target): ?SentinelReleaseExpectation
    {
        $this->attach();
        if ($target->projectKey !== $this->filesystem->config->projectKey || $target->environment !== 'local') { return null; }
        $query = $this->pdo->prepare('SELECT d.*, r.public_id AS release_public_id, a.public_id AS artifact_public_id, a.checksum_sha256, p.project_key FROM releases r JOIN deployments d ON d.release_id=r.id JOIN artifacts a ON a.id=d.artifact_id JOIN projects p ON p.id=d.project_id WHERE r.public_id=? AND p.project_key=? AND p.environment=?');
        $query->execute([$target->releaseId, $target->projectKey, $target->environment]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $agent = $this->pdo->query('SELECT public_id FROM local_agents LIMIT 1')->fetchColumn();
        if (!$row || !$agent) { return null; }
        return new SentinelReleaseExpectation($target->projectKey, 'local', $row['release_public_id'],
            $row['commit_hash'], $row['artifact_public_id'], $row['checksum_sha256'],
            'release:' . $row['public_id'] . '|commit:' . $row['commit_hash'], $row['public_id'],
            $this->filesystem->config->runtimeUrl() . '/health/release', 200, $agent);
    }

    public function ingest(string $method, string $path, array $headers, string $body): int
    {
        $this->attach();
        if ($method !== 'POST' || $path !== '/api/local-agent/heartbeat') { return 404; }
        try {
            $headers = array_change_key_case($headers, CASE_LOWER);
            $id = $headers['x-deployment-local-agent-id'] ?? '';
            $timestamp = $headers['x-deployment-timestamp'] ?? '';
            $nonce = $headers['x-deployment-nonce'] ?? '';
            $signature = $headers['x-deployment-signature'] ?? '';
            if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 60 || !preg_match('/^[0-9a-f]{32}$/D', $nonce)) { return 401; }
            $query = $this->pdo->prepare('SELECT * FROM local_agents WHERE public_id=? AND status=?');
            $query->execute([$id, LocalAgent::STATUS_APPROVED]);
            $agent = $query->fetch(PDO::FETCH_ASSOC);
            $token = $headers['authorization'] ?? '';
            if (!$agent || !str_starts_with($token, 'Bearer ')
                || !hash_equals($agent['agent_token_hash'], hash('sha256', substr($token, 7)))
                || !(new RequestSigner())->verify($method, $path, $timestamp, $nonce, $body, $agent['signing_secret'], $signature)) { return 401; }
            $input = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $contract = SentinelRuntimeEvidenceContract::fromArray($input['runtime_snapshot'] ?? []);
            $snapshot = $contract->toArray();
            if (!$contract->isSupportedVersion() || !$contract->isComplete() || $contract->missingRequiredFields() !== []
                || $snapshot['agent_id'] !== $id || $snapshot['project_key'] !== $this->filesystem->config->projectKey
                || $snapshot['environment'] !== 'local') { return 422; }
            $observed = new \DateTimeImmutable($snapshot['timestamp']);
            if (time() - $observed->getTimestamp() < 0 || time() - $observed->getTimestamp() > 300) { return 422; }
            $row = $this->deployment($snapshot['deployment_id']);
            if (!$row || $row['release_public_id'] !== $snapshot['release_id']
                || $row['commit_hash'] !== $snapshot['release_manifest_commit_sha']
                || $row['checksum_sha256'] !== $snapshot['artifact_checksum_sha256']
                || $snapshot['runtime_marker'] !== 'release:' . $row['public_id'] . '|commit:' . $row['commit_hash']) { return 422; }
            $this->pdo->beginTransaction();
            $nonceInsert = $this->pdo->prepare('INSERT INTO heartbeat_nonces VALUES (?,?)');
            $nonceInsert->execute([$nonce, time()]);
            $update = $this->pdo->prepare('UPDATE local_agents SET runtime_snapshot=?, runtime_snapshot_sequence=?, last_seen_at=?, agent_version=? WHERE id=? AND (runtime_snapshot_sequence IS NULL OR runtime_snapshot_sequence < ?)');
            $update->execute([$contract->toJson(), $snapshot['sequence'], gmdate('c'), $snapshot['agent_version'], $agent['id'], $snapshot['sequence']]);
            if ($update->rowCount() !== 1) { $this->pdo->rollBack(); return 409; }
            $this->pdo->commit();
            return 200;
        } catch (\Throwable) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return 422;
        }
    }

    public function readRuntime(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?\App\Services\Sentinel\SentinelRuntimeSnapshot
    {
        return $this->withModels(fn () => (new AgentRuntimeEvidenceReader())->read($target, $expectation));
    }

    public function persistedSnapshot(): ?array
    {
        $this->attach();
        $row = $this->pdo->query('SELECT runtime_snapshot, runtime_snapshot_sequence FROM local_agents LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['runtime_snapshot'] === null) { return null; }
        $snapshot = json_decode($row['runtime_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        return is_int($snapshot['sequence'] ?? null) && $snapshot['sequence'] === (int) $row['runtime_snapshot_sequence'] ? $snapshot : null;
    }
}
