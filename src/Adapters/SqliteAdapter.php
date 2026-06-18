<?php

declare(strict_types=1);

namespace SimpleDB\Adapters;

use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Exceptions\StorageException;

/**
 * SQLite-backed storage adapter.
 *
 * Replaces the one-file-per-document model with a single SQLite database file,
 * dramatically reducing filesystem overhead:
 *
 *  - WAL journal mode: concurrent readers never block each other, and readers
 *    do not block writers.
 *  - NORMAL synchronous mode: safe data durability with WAL (data survives app
 *    crashes; only an OS/power failure with no WAL checkpoint could lose the
 *    last transaction).
 *  - 64 MB in-memory page cache: hot pages served from RAM.
 *  - batchWrite() wraps multiple upserts in a single transaction, reducing
 *    fsync overhead to one commit.
 *  - count() uses SELECT COUNT(*) — O(1) with the index, no document parsing.
 *
 * Usage:
 *
 *   $adapter = new SqliteAdapter('/path/to/store.sqlite');
 *   $db      = new SimpleDB('cars', $adapter);
 *
 * Use ':memory:' for a transient in-memory database (testing / ephemeral data):
 *
 *   $adapter = new SqliteAdapter(':memory:');
 */
class SqliteAdapter implements StorageInterface
{
    private readonly \PDO $pdo;

    /**
     * @param string $databasePath  Filesystem path to the SQLite file, or ':memory:'.
     * @param int    $maxDocumentSize  Maximum JSON byte size per document (default 5 MiB).
     */
    public function __construct(
        string $databasePath,
        private readonly int $maxDocumentSize = 5 * 1024 * 1024,
    ) {
        try {
            $this->pdo = new \PDO('sqlite:' . $databasePath, options: [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\PDOException $e) {
            throw new StorageException('Cannot open SQLite database: ' . $e->getMessage(), 0, $e);
        }

        $this->applyPragmas();
        $this->createSchema();
    }

    // -------------------------------------------------------------------------
    // StorageInterface
    // -------------------------------------------------------------------------

    public function read(string $collection, string $id): array|null
    {
        $this->sanitise($collection);
        $this->sanitise($id);

        $stmt = $this->pdo->prepare(
            'SELECT data FROM documents WHERE collection = :col AND id = :id'
        );
        $stmt->execute([':col' => $collection, ':id' => $id]);

        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return $this->decode($row['data'], $id, $collection);
    }

    public function readAll(string $collection): array
    {
        $this->sanitise($collection);

        $stmt = $this->pdo->prepare(
            'SELECT id, data FROM documents WHERE collection = :col'
        );
        $stmt->execute([':col' => $collection]);

        $output = [];

        while ($row = $stmt->fetch()) {
            $output[$row['id']] = $this->decode($row['data'], $row['id'], $collection);
        }

        return $output;
    }

    /** @return \Generator<string, array> */
    public function stream(string $collection): \Generator
    {
        $this->sanitise($collection);

        $stmt = $this->pdo->prepare(
            'SELECT id, data FROM documents WHERE collection = :col'
        );
        $stmt->execute([':col' => $collection]);

        while ($row = $stmt->fetch()) {
            yield $row['id'] => $this->decode($row['data'], $row['id'], $collection);
        }
    }

    public function write(string $collection, string $id, array $data): void
    {
        $this->sanitise($collection);
        $this->sanitise($id);

        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new StorageException(
                "Failed to encode document '{$id}': " . $e->getMessage(), 0, $e
            );
        }

        if (strlen($json) > $this->maxDocumentSize) {
            throw new StorageException(
                "Document '{$id}' exceeds the maximum allowed size of {$this->maxDocumentSize} bytes."
            );
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO documents (collection, id, data, updated_at)
                 VALUES (:col, :id, :data, :ts)
            ON CONFLICT (collection, id)
          DO UPDATE SET data       = excluded.data,
                        updated_at = excluded.updated_at
        ');

        $stmt->execute([
            ':col'  => $collection,
            ':id'   => $id,
            ':data' => $json,
            ':ts'   => time(),
        ]);
    }

    /** @param array<string, array> $documents */
    public function batchWrite(string $collection, array $documents): void
    {
        // Wrap all upserts in one transaction: one fsync instead of N.
        $inTransaction = $this->pdo->inTransaction();

        if (!$inTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            foreach ($documents as $id => $data) {
                $this->write($collection, (string) $id, $data);
            }

            if (!$inTransaction) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e instanceof StorageException ? $e : new StorageException($e->getMessage(), 0, $e);
        }
    }

    public function delete(string $collection, string $id): void
    {
        $this->sanitise($collection);
        $this->sanitise($id);

        $stmt = $this->pdo->prepare(
            'DELETE FROM documents WHERE collection = :col AND id = :id'
        );
        $stmt->execute([':col' => $collection, ':id' => $id]);
    }

    public function exists(string $collection, string $id): bool
    {
        try {
            $this->sanitise($collection);
            $this->sanitise($id);
        } catch (StorageException) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM documents WHERE collection = :col AND id = :id'
        );
        $stmt->execute([':col' => $collection, ':id' => $id]);

        return $stmt->fetch() !== false;
    }

    public function listIds(string $collection): array
    {
        $this->sanitise($collection);

        $stmt = $this->pdo->prepare(
            'SELECT id FROM documents WHERE collection = :col'
        );
        $stmt->execute([':col' => $collection]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

    public function count(string $collection): int
    {
        $this->sanitise($collection);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM documents WHERE collection = :col'
        );
        $stmt->execute([':col' => $collection]);

        return (int) $stmt->fetchColumn();
    }

    public function timestamp(string $collection, string $id): int|null
    {
        try {
            $this->sanitise($collection);
            $this->sanitise($id);
        } catch (StorageException) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT updated_at FROM documents WHERE collection = :col AND id = :id'
        );
        $stmt->execute([':col' => $collection, ':id' => $id]);

        $row = $stmt->fetch();

        return $row !== false ? (int) $row['updated_at'] : null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function applyPragmas(): void
    {
        // WAL: readers and the writer run concurrently without blocking each other.
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        // NORMAL: safe with WAL; avoids an extra fsync per write.
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        // 64 MB page cache kept in RAM.
        $this->pdo->exec('PRAGMA cache_size = -65536');
        // Temp tables and indices in memory.
        $this->pdo->exec('PRAGMA temp_store = MEMORY');
        // Wait up to 5 s when another writer holds the lock.
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
    }

    private function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS documents (
                collection TEXT    NOT NULL,
                id         TEXT    NOT NULL,
                data       TEXT    NOT NULL,
                updated_at INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (collection, id)
            )
        ');

        $this->pdo->exec('
            CREATE INDEX IF NOT EXISTS idx_documents_collection
            ON documents (collection)
        ');
    }

    private function sanitise(string $value): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $value)) {
            throw new StorageException(
                "Invalid name '{$value}': only alphanumeric characters, hyphens and underscores are allowed."
            );
        }

        return $value;
    }

    private function decode(string $json, string $id, string $collection): array
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new StorageException(
                "Corrupt document '{$id}' in collection '{$collection}': invalid JSON."
            );
        }

        return $data;
    }
}
