# SimpleDB

![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)
![License](https://img.shields.io/badge/license-MIT-green)

**JSON document storage for PHP — no database server required.**

Store, query, and cache structured data using only PHP and the filesystem. No MongoDB, no MySQL, no Redis, no infrastructure to provision. Works on any server, shared hosting, or local machine that runs PHP.

---

## The Problem

You need to persist structured data — user settings, feature flags, queue jobs, content, session metadata — but the options feel wrong:

- **A full SQL database** is too heavy for a small app or tool. Schema migrations for a `config` table feel ridiculous.
- **Plain JSON files** work for a single record but break down when you need queries, atomic writes, or cache coherence.
- **MongoDB / Redis** require a running server you don't have on shared hosting or in a dev environment.
- **An ORM** forces you to define schemas up front, run migrations, and think in tables — not in documents.

SimpleDB fills that gap: a document store that starts with just a directory, supports fluent queries, scales to SQLite with WAL-mode concurrency, and adds shared-memory caching — all without changing a line of application code.

---

## Why SimpleDB

- **Zero infrastructure** — `FileAdapter` needs only a writable directory. No servers, no daemons, no config files.
- **Swap adapters as you grow** — change one constructor call to move from files → SQLite → APCu-cached SQLite. Your queries stay identical.
- **Fluent queries that push to SQL** — write `.where('price', '<', 500).orderBy('name').limit(10)`. When the backend is SQLite, that runs as a single `json_extract`-powered SQL query — no PHP-level streaming.
- **Works on shared hosting** — `pdo_sqlite` ships with PHP. APCu is a single `pecl install`. No root access needed.
- **Schema-free documents** — store whatever shape you need. Add or remove fields per-document. No migrations.
- **Layered caching with no code changes** — wrap any adapter with `ApcuCacheAdapter` to cache reads across all PHP-FPM workers. The query builder automatically finds and uses SQL push-down through the cache layer.

---

## Installation

```bash
composer require tanghoong/simpledb
```

**Requires:** PHP ≥ 8.2. No mandatory extensions — optional extensions unlock faster adapters:
- `ext-pdo_sqlite` — for `SqliteAdapter` (ships with PHP)
- `ext-apcu` — for `ApcuCacheAdapter` (`pecl install apcu`)

---

## Quick Start

```php
use SimpleDB\Adapters\FileAdapter;
use SimpleDB\SimpleDB;

$db = new SimpleDB('products', new FileAdapter('/var/data/myapp'));

// Create with auto-generated ID
$id = $db->post(['name' => 'Widget', 'price' => 9.99, 'stock' => 100]);

// Read — returns null when missing, never throws
$product = $db->get($id);

// Update
$db->put($id, ['name' => 'Widget Pro', 'price' => 14.99, 'stock' => 100]);

// Delete
$db->delete($id);

// Query
$affordable = $db->where('price', '<', 10.00)
                 ->where('stock', '>', 0)
                 ->orderBy('name')
                 ->get();
```

That's the entire API surface you need for 80% of use cases. No connection strings, no schema definitions, no migrations.

---

## Scaling Up: The Adapter Story

The adapter pattern is SimpleDB's core design principle. You pick the adapter that matches your workload — and if your needs change, you swap one line:

### Stage 1 — Files (zero config)

```php
$db = new SimpleDB('orders', new FileAdapter('/var/data'));
```

Best for: prototypes, CLIs, tools, configs, up to ~50k documents. Works everywhere.

### Stage 2 — SQLite (concurrent reads, fast queries)

```php
use SimpleDB\Adapters\SqliteAdapter;

$db = new SimpleDB('orders', new SqliteAdapter('/var/data/myapp.sqlite'));
// ↑ One line change. All your queries, hooks, and timestamps work unchanged.
```

Best for: high read volume, complex queries, multiple collections in one file. Uses PHP's built-in `pdo_sqlite` — no server required.

**What you get over FileAdapter:**
- Multiple concurrent readers never block (WAL journal mode)
- Full-collection reads in a single SQL query instead of N file opens
- `batchPut()` commits N documents in one `fsync`
- `count()` runs as `SELECT COUNT(*)` — no document parsing
- Queries push down to SQL — `WHERE`, `ORDER BY`, `LIMIT/OFFSET` run in the database

### Stage 3 — APCu caching (shared-memory, cross-worker)

```php
use SimpleDB\Adapters\ApcuCacheAdapter;
use SimpleDB\Adapters\SqliteAdapter;

$db = new SimpleDB('orders',
    new ApcuCacheAdapter(new SqliteAdapter('/var/data/myapp.sqlite'), ttl: 60)
);
// ↑ Wrap with ApcuCacheAdapter. Nothing else changes.
```

Best for: high-traffic apps where the same documents are read by many PHP-FPM workers. APCu shared memory is visible to every worker in the pool — a cache hit costs a single memory lookup.

**What you get over SqliteAdapter alone:**
- Document reads served from shared memory — zero I/O
- ID lists cached per collection — `getAll()` skips the `listIds()` query
- Missing documents tombstoned — repeated `get('ghost-id')` skips the database
- SQL query push-down still works — `where()->get()` runs as SQL, individual `get()` reads from APCu

---

## Query Builder

A fluent interface for filtering, sorting, and paginating any collection.

```php
// Simple equality
$results = $db->where('status', 'active')->get();

// Comparison operators
$results = $db->where('price', '>=', 10.00)
              ->where('price', '<', 100.00)
              ->get();

// String matching (case-sensitive)
$results = $db->where('name', 'contains', 'pro')->get();
$results = $db->where('email', 'ends_with', '@example.com')->get();

// Set membership
$results = $db->where('role', 'in', ['admin', 'moderator'])->get();
$results = $db->where('status', 'not_in', ['banned', 'deleted'])->get();

// Null checks
$results = $db->newQuery()->whereNull('deleted_at')->get();
$results = $db->newQuery()->whereNotNull('verified_at')->get();

// Nested fields with dot-notation
// Document: {"shipping": {"country": "MY", "method": "express"}}
$results = $db->where('shipping.country', 'MY')
              ->where('shipping.method', 'express')
              ->get();

// Sort + paginate
$page = $db->where('category', 'books')
           ->orderBy('published_at', 'desc')
           ->limit(20)
           ->offset(40)
           ->get();

// Count without loading documents
$n = $db->where('status', 'pending')->count();

// First match
$admin = $db->where('role', 'admin')->orderBy('name')->first();

// Existence check
if ($db->where('email', $email)->exists()) { ... }
```

#### All supported operators

| Operator      | Matches when…                                        |
|---------------|------------------------------------------------------|
| `=` (default) | strict equality (`===`)                              |
| `!=`          | strict inequality                                    |
| `>` `>=` `<` `<=` | numeric / string comparison                   |
| `in`          | value is in the given array                          |
| `not_in`      | value is not in the given array                      |
| `contains`    | string field contains the substring (case-sensitive) |
| `starts_with` | string field starts with the prefix                  |
| `ends_with`   | string field ends with the suffix                    |
| `null`        | field is missing or explicitly `null`                |
| `not_null`    | field exists and is not `null`                       |

> **With `SqliteAdapter`** (directly or under `ApcuCacheAdapter`), every terminal method (`get`, `first`, `count`, `exists`) executes as a single SQL statement with `json_extract()`-based predicates. No documents are loaded into PHP.

---

## More Features

### Works like a native PHP collection

```php
// ArrayAccess
$product       = $db['abc123'];
$db['abc123']  = $data;
$db[]          = $data;          // auto-generated ID
unset($db['abc123']);
isset($db['abc123']);

// Countable
$total = count($db);

// IteratorAggregate — lazy Generator, one document at a time
foreach ($db as $id => $document) {
    process($document);
}
```

### Auto-timestamps

```php
$db = new SimpleDB('posts', $adapter, timestamps: true);

$id = $db->post(['title' => 'Hello']);
// stored: {title: 'Hello', _created_at: 1700000000, _updated_at: 1700000000}

$db->put($id, ['title' => 'Hello World']);
// stored: {title: 'Hello World', _updated_at: 1700000001}
// _created_at is preserved unchanged by put()
```

### Lifecycle hooks

```php
// Validate and transform before every write
$db->beforeWrite(function (string $id, array $data, bool $isNew): array {
    if (empty($data['email'])) {
        throw new \InvalidArgumentException('email is required');
    }
    $data['email'] = strtolower($data['email']);
    return $data; // must return the (possibly modified) data
});

// Side-effects after a successful write
$db->afterWrite(function (string $id, array $data, bool $isNew): void {
    $searchIndex->upsert($id, $data);
});

// Audit before deletion
$db->beforeDelete(function (string $id): void {
    auditLog("deleting {$id}");
});

$db->afterDelete(function (string $id): void {
    $searchIndex->remove($id);
});
```

### Debug logging

```php
$db = new SimpleDB('cars', $adapter, logger: function (string $level, string $msg, array $ctx): void {
    error_log("[{$level}] {$msg}");
});
```

### Batch operations

```php
// Create many with auto-generated IDs
$ids = $db->batchPost([
    ['sku' => 'A1', 'qty' => 10],
    ['sku' => 'A2', 'qty' => 20],
]);

// Upsert many with explicit IDs (single transaction on SqliteAdapter)
$db->batchPut([
    'user-1' => ['name' => 'Alice', 'role' => 'admin'],
    'user-2' => ['name' => 'Bob',   'role' => 'user'],
]);
```

---

## Common Use Cases

| Use case | Recommended adapter stack |
|---|---|
| Local tool / CLI app | `FileAdapter` |
| Small web app, shared hosting | `FileAdapter` or `SqliteAdapter` |
| Config / feature flags store | `FileAdapter` |
| Session / token storage | `SqliteAdapter` |
| Product catalog, content | `SqliteAdapter` |
| High-traffic read-heavy app | `ApcuCacheAdapter` wrapping `SqliteAdapter` |
| Testing / ephemeral data | `SqliteAdapter(':memory:')` |
| Custom backend (Redis, S3…) | Implement `StorageInterface` |

---

## API Reference

### `SimpleDB` constructor

```php
new SimpleDB(
    string           $collection,  // alphanumeric, _ and - only
    StorageInterface $storage,
    callable|null    $logger     = null,
    bool             $timestamps = false,
)
```

### Core methods

| Method | Returns | Description |
|---|---|---|
| `get(string $id)` | `array\|null` | Read one document; `null` if missing |
| `put(string $id, array $data)` | `void` | Create or overwrite |
| `post(array $data)` | `string` | Create with auto-generated ID; returns ID |
| `delete(string $id)` | `void` | Delete; throws `DocumentNotFoundException` if missing |
| `exists(string $id)` | `bool` | Check existence (cache-aware) |
| `getAll()` | `array` | All documents as `[id => data]` |
| `stream()` | `Generator` | Lazy document stream |
| `count()` | `int` | Document count |
| `timestamp(string $id)` | `int\|null` | Last-modified Unix timestamp |
| `batchPost(array $docs)` | `string[]` | Bulk create; returns IDs |
| `batchPut(array $docs)` | `void` | Bulk upsert with explicit IDs |
| `query(array $criteria, int $limit, int $offset)` | `array` | Simple equality filter |
| `where(string $field, …)` | `QueryBuilder` | Start a fluent query |
| `newQuery()` | `QueryBuilder` | Fresh query builder |
| `clearCache()` | `void` | Invalidate in-process cache |

### `ApcuCacheAdapter` additional methods

| Method | Description |
|---|---|
| `evict(string $collection, string $id)` | Remove one document from APCu |
| `evictCollection(string $collection)` | Remove all APCu entries for a collection |
| `flushAll()` | Clear entire APCu cache (all PHP keys, use carefully) |

---

## Safety Features

| Feature | Details |
|---|---|
| Atomic writes | Temp file + `rename()` — no partial-write corruption |
| Exclusive file locking | `flock(LOCK_EX)` per document under `FileAdapter` |
| ID sanitisation | Only `[a-zA-Z0-9_-]` — blocks path-traversal attacks |
| Path verification | `realpath()` check ensures paths stay inside the storage root |
| Restrictive permissions | Directories created with mode `0750` |
| Document size limit | Configurable per adapter (default 5 MiB); throws `StorageException` |
| Typed exceptions | `StorageException` / `DocumentNotFoundException` — no silent failures |
| Strict types | `declare(strict_types=1)` throughout |

---

## Error Handling

```php
use SimpleDB\Exceptions\DocumentNotFoundException;
use SimpleDB\Exceptions\StorageException;

try {
    $db->delete('missing-id');
} catch (DocumentNotFoundException $e) {
    // ID did not exist
}

try {
    $db->put('bad/id', $data);
} catch (StorageException $e) {
    // Invalid ID, I/O error, document too large, etc.
}
```

Both extend `SimpleDBException` for catch-all handling.

---

## Running Tests

```bash
composer install
vendor/bin/phpunit          # 172 tests (14 APCu tests skipped if ext-apcu absent)
vendor/bin/phpstan analyse src --level=5
```

---

## Project Structure

```
src/
├── SimpleDB.php                # Main class — ArrayAccess, Countable, IteratorAggregate
├── Contracts/
│   ├── StorageInterface.php    # Implement this to add your own backend
│   ├── NativeQueryInterface.php # SQL/native push-down queries
│   └── DecoratorInterface.php  # Lets QueryBuilder peek through cache layers
├── Adapters/
│   ├── FileAdapter.php         # One JSON file per document
│   ├── SqliteAdapter.php       # SQLite with WAL + NativeQueryInterface
│   └── ApcuCacheAdapter.php    # APCu shared-memory decorator
├── Query/
│   └── QueryBuilder.php        # Fluent query builder; auto-uses SQL push-down
└── Exceptions/
    ├── SimpleDBException.php
    ├── DocumentNotFoundException.php
    └── StorageException.php
```

---

## Migrating from `Simple_DB` (v1)

| Old | New |
|---|---|
| `new Simple_DB('cars')` | `new SimpleDB('cars', new FileAdapter($dir))` |
| `$db->get($id)` | `$db->get($id)` — returns `null` instead of `false` |
| `$db->get()` | `$db->getAll()` |
| `$db->post($array)` | `$db->post($array)` |
