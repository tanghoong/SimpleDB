<?php

declare(strict_types=1);

namespace SimpleDB\Adapters;

use SimpleDB\Contracts\NativeQueryInterface;
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
 *  - NativeQueryInterface: QueryBuilder conditions are pushed down to SQL via
 *    json_extract(), bypassing PHP-side document streaming entirely.
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
class SqliteAdapter implements StorageInterface, NativeQueryInterface
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
    // NativeQueryInterface
    // -------------------------------------------------------------------------

    /** @return array<string, array> */
    public function executeNativeQuery(
        string $collection,
        array $conditions,
        array $orders,
        int $limit,
        int $offset,
    ): array {
        $this->sanitise($collection);

        [$whereClauses, $whereParams] = $this->buildConditionClauses($conditions);
        [$orderClauses, $orderParams] = $this->buildOrderClauses($orders);

        $sql    = 'SELECT id, data FROM documents WHERE collection = ?';
        $params = [[$collection, \PDO::PARAM_STR]];

        array_push($params, ...$whereParams);

        if (!empty($whereClauses)) {
            $sql .= ' AND ' . implode(' AND ', $whereClauses);
        }

        if (!empty($orderClauses)) {
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
            array_push($params, ...$orderParams);
        }

        if ($limit > 0 && $offset > 0) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = [$limit,  \PDO::PARAM_INT];
            $params[] = [$offset, \PDO::PARAM_INT];
        } elseif ($limit > 0) {
            $sql .= ' LIMIT ?';
            $params[] = [$limit, \PDO::PARAM_INT];
        } elseif ($offset > 0) {
            $sql .= ' LIMIT -1 OFFSET ?';
            $params[] = [$offset, \PDO::PARAM_INT];
        }

        $stmt   = $this->executeTyped($sql, $params);
        $output = [];

        while ($row = $stmt->fetch()) {
            $output[$row['id']] = $this->decode($row['data'], $row['id'], $collection);
        }

        return $output;
    }

    public function executeNativeFirst(
        string $collection,
        array $conditions,
        array $orders,
    ): array|null {
        $results = $this->executeNativeQuery($collection, $conditions, $orders, 1, 0);

        return !empty($results) ? reset($results) : null;
    }

    public function executeNativeCount(string $collection, array $conditions): int
    {
        $this->sanitise($collection);

        [$whereClauses, $whereParams] = $this->buildConditionClauses($conditions);

        $sql    = 'SELECT COUNT(*) FROM documents WHERE collection = ?';
        $params = [[$collection, \PDO::PARAM_STR]];

        array_push($params, ...$whereParams);

        if (!empty($whereClauses)) {
            $sql .= ' AND ' . implode(' AND ', $whereClauses);
        }

        return (int) $this->executeTyped($sql, $params)->fetchColumn();
    }

    public function executeNativeExists(string $collection, array $conditions): bool
    {
        $this->sanitise($collection);

        [$whereClauses, $whereParams] = $this->buildConditionClauses($conditions);

        $sql    = 'SELECT 1 FROM documents WHERE collection = ?';
        $params = [[$collection, \PDO::PARAM_STR]];

        array_push($params, ...$whereParams);

        if (!empty($whereClauses)) {
            $sql .= ' AND ' . implode(' AND ', $whereClauses);
        }

        $sql .= ' LIMIT 1';

        return $this->executeTyped($sql, $params)->fetch() !== false;
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
        // Case-sensitive LIKE to match PHP's str_contains/str_starts_with/str_ends_with.
        $this->pdo->exec('PRAGMA case_sensitive_like = ON');
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

    /**
     * Prepare and execute a statement, binding each parameter with an explicit PDO type.
     *
     * PDO's execute($array) binds every value as PARAM_STR.  SQLite's json_extract()
     * returns natively typed values (INTEGER, REAL, TEXT), so a TEXT-bound PHP integer
     * would cause type-affinity mismatches (e.g. INTEGER < TEXT is always true in
     * SQLite, regardless of numeric value).  Explicit bindValue() calls preserve types.
     *
     * @param list<array{0: mixed, 1: int}> $typedParams  Each element: [value, PDO::PARAM_*]
     */
    private function executeTyped(string $sql, array $typedParams): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);

        foreach ($typedParams as $i => [$value, $type]) {
            $stmt->bindValue($i + 1, $value, $type);
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * Convert a dot-notation field name to a SQLite json_extract path.
     * Numeric segments become array indices: 'items.0.name' → '$.items[0].name'
     */
    private function toJsonPath(string $field): string
    {
        $parts = explode('.', $field);
        $path  = '$';

        foreach ($parts as $part) {
            $path .= ctype_digit($part) ? '[' . $part . ']' : '.' . $part;
        }

        return $path;
    }

    /**
     * Escape special LIKE characters using '!' as the escape character.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Return [sql_fragment, typed_param] for a scalar comparison value.
     *
     * Integers → PARAM_INT (SQLite INTEGER comparison).
     * Floats   → CAST(? AS REAL) with PARAM_STR (no PDO::PARAM_FLOAT exists).
     * Strings  → PARAM_STR.
     * Bools    → PARAM_INT cast to 0/1.
     *
     * @return array{0: string, 1: array{0: mixed, 1: int}}
     */
    private function sqlValueFragment(mixed $value): array
    {
        if (is_bool($value)) {
            return ['?', [(int) $value, \PDO::PARAM_INT]];
        }

        if (is_int($value)) {
            return ['?', [$value, \PDO::PARAM_INT]];
        }

        if (is_float($value)) {
            // json_encode serialises floats locale-independently (always '.' decimal point).
            // (string) $value would use LC_NUMERIC and break on e.g. de_DE locales.
            return ['CAST(? AS REAL)', [json_encode($value), \PDO::PARAM_STR]];
        }

        return ['?', [(string) $value, \PDO::PARAM_STR]];
    }

    /**
     * Build the WHERE fragment and typed positional param list for a set of conditions.
     *
     * @param  list<array{field: string, operator: string, value: mixed}> $conditions
     * @return array{list<string>, list<array{0: mixed, 1: int}>}
     */
    private function buildConditionClauses(array $conditions): array
    {
        $clauses = [];
        $params  = [];

        foreach ($conditions as ['field' => $field, 'operator' => $op, 'value' => $expected]) {
            $path = $this->toJsonPath($field);
            [$clause, $clauseParams] = $this->buildConditionClause($path, $op, $expected);
            $clauses[] = $clause;
            array_push($params, ...$clauseParams);
        }

        return [$clauses, $params];
    }

    /**
     * Build a single SQL condition fragment and its typed bound parameters.
     *
     * @return array{string, list<array{0: mixed, 1: int}>}
     */
    private function buildConditionClause(string $path, string $op, mixed $expected): array
    {
        $pathParam = [$path, \PDO::PARAM_STR];
        $ex        = 'json_extract(data, ?)';

        switch ($op) {
            case '=':
                if ($expected === null) {
                    return ["{$ex} IS NULL", [$pathParam]];
                }
                [$frag, $valParam] = $this->sqlValueFragment($expected);
                return ["{$ex} = {$frag}", [$pathParam, $valParam]];

            case '!=':
                if ($expected === null) {
                    return ["{$ex} IS NOT NULL", [$pathParam]];
                }
                [$frag, $valParam] = $this->sqlValueFragment($expected);
                // Rows where the field is null/missing should also be included (null != anything)
                return ["({$ex} IS NULL OR {$ex} != {$frag})", [$pathParam, $pathParam, $valParam]];

            case '>':
                [$frag, $valParam] = $this->sqlValueFragment($expected);
                return ["{$ex} > {$frag}", [$pathParam, $valParam]];

            case '>=':
                [$frag, $valParam] = $this->sqlValueFragment($expected);
                return ["{$ex} >= {$frag}", [$pathParam, $valParam]];

            case '<':
                [$frag, $valParam] = $this->sqlValueFragment($expected);
                return ["{$ex} < {$frag}", [$pathParam, $valParam]];

            case '<=':
                [$frag, $valParam] = $this->sqlValueFragment($expected);
                return ["{$ex} <= {$frag}", [$pathParam, $valParam]];

            case 'in':
                return $this->buildInClause($path, (array) $expected, negate: false);

            case 'not_in':
                return $this->buildInClause($path, (array) $expected, negate: true);

            case 'contains':
                $pattern = '%' . $this->escapeLike((string) $expected) . '%';
                return [
                    "(json_type(data, ?) = 'text' AND {$ex} LIKE ? ESCAPE '!')",
                    [$pathParam, $pathParam, [$pattern, \PDO::PARAM_STR]],
                ];

            case 'starts_with':
                $pattern = $this->escapeLike((string) $expected) . '%';
                return [
                    "(json_type(data, ?) = 'text' AND {$ex} LIKE ? ESCAPE '!')",
                    [$pathParam, $pathParam, [$pattern, \PDO::PARAM_STR]],
                ];

            case 'ends_with':
                $pattern = '%' . $this->escapeLike((string) $expected);
                return [
                    "(json_type(data, ?) = 'text' AND {$ex} LIKE ? ESCAPE '!')",
                    [$pathParam, $pathParam, [$pattern, \PDO::PARAM_STR]],
                ];

            case 'null':
                return ["{$ex} IS NULL", [$pathParam]];

            case 'not_null':
                return ["{$ex} IS NOT NULL", [$pathParam]];

            default:
                throw new StorageException("Unsupported native operator: {$op}");
        }
    }

    /**
     * Build an IN / NOT IN clause, handling empty lists and null values correctly.
     *
     * @param  list<mixed>  $expected
     * @return array{string, list<array{0: mixed, 1: int}>}
     */
    private function buildInClause(string $path, array $expected, bool $negate): array
    {
        $pathParam = [$path, \PDO::PARAM_STR];
        $ex        = 'json_extract(data, ?)';

        if (!$negate) {
            if (empty($expected)) {
                return ['(0=1)', []];
            }

            $nulls    = array_values(array_filter($expected, fn($v) => $v === null));
            $nonNulls = array_values(array_filter($expected, fn($v) => $v !== null));

            if (!empty($nulls) && !empty($nonNulls)) {
                [$frags, $valParams] = $this->inValueFragments($nonNulls);
                $ph = implode(',', $frags);
                return [
                    "({$ex} IS NULL OR {$ex} IN ({$ph}))",
                    [$pathParam, $pathParam, ...$valParams],
                ];
            }

            if (!empty($nulls)) {
                return ["{$ex} IS NULL", [$pathParam]];
            }

            [$frags, $valParams] = $this->inValueFragments($nonNulls);
            $ph = implode(',', $frags);
            return ["{$ex} IN ({$ph})", [$pathParam, ...$valParams]];
        }

        // not_in: PHP → !in_array($actual, $expected, strict: true)  (no $fieldExists guard)
        if (empty($expected)) {
            return ['(1=1)', []];
        }

        $nulls    = array_values(array_filter($expected, fn($v) => $v === null));
        $nonNulls = array_values(array_filter($expected, fn($v) => $v !== null));

        if (!empty($nulls) && !empty($nonNulls)) {
            [$frags, $valParams] = $this->inValueFragments($nonNulls);
            $ph = implode(',', $frags);
            return [
                "({$ex} IS NOT NULL AND {$ex} NOT IN ({$ph}))",
                [$pathParam, $pathParam, ...$valParams],
            ];
        }

        if (!empty($nulls)) {
            return ["{$ex} IS NOT NULL", [$pathParam]];
        }

        [$frags, $valParams] = $this->inValueFragments($nonNulls);
        $ph = implode(',', $frags);
        return [
            "({$ex} IS NULL OR {$ex} NOT IN ({$ph}))",
            [$pathParam, $pathParam, ...$valParams],
        ];
    }

    /**
     * Build SQL fragments and typed params for a list of IN/NOT IN values.
     *
     * @param  list<mixed>  $values  Non-null values only.
     * @return array{list<string>, list<array{0: mixed, 1: int}>}
     */
    private function inValueFragments(array $values): array
    {
        $frags     = [];
        $params    = [];

        foreach ($values as $value) {
            [$frag, $param] = $this->sqlValueFragment($value);
            $frags[]  = $frag;
            $params[] = $param;
        }

        return [$frags, $params];
    }

    /**
     * Build ORDER BY fragments and their typed bound parameters.
     *
     * @param  list<array{field: string, direction: string}>   $orders
     * @return array{list<string>, list<array{0: mixed, 1: int}>}
     */
    private function buildOrderClauses(array $orders): array
    {
        $clauses = [];
        $params  = [];

        foreach ($orders as ['field' => $field, 'direction' => $dir]) {
            $normalized = strtoupper($dir);
            if ($normalized !== 'ASC' && $normalized !== 'DESC') {
                throw new StorageException("Invalid sort direction '{$dir}'; must be 'asc' or 'desc'.");
            }
            $clauses[] = 'json_extract(data, ?) ' . $normalized;
            $params[]  = [$this->toJsonPath($field), \PDO::PARAM_STR];
        }

        return [$clauses, $params];
    }
}
