# SimpleDB

![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)
![License](https://img.shields.io/badge/license-MIT-green)

A lightweight, zero-dependency **NoSQL JSON Document Storage** library for PHP 8.2+.

SimpleDB stores documents as JSON on the local filesystem. Three interchangeable storage adapters let you choose the right I/O trade-off for your workload — and you can swap them without changing a single line of application code.

| Adapter | Best for | I/O model |
|---|---|---|
| `FileAdapter` | Small collections, simple deployments | One JSON file per document |
| `SqliteAdapter` | Larger collections, concurrent readers | Single SQLite file, WAL mode |
| `ApcuCacheAdapter` | High read throughput (wraps any adapter) | APCu shared-memory L1 cache |

---

## Installation

```bash
composer require tanghoong/simpledb
```

**Requirements:** PHP ≥ 8.2

Optional extensions (suggested in `composer.json`):
- `ext-pdo_sqlite` — for `SqliteAdapter` (ships with PHP; verify with `php -m | grep pdo_sqlite`)
- `ext-apcu` — for `ApcuCacheAdapter` (`pecl install apcu`; set `apc.enable_cli=1` for CLI)

---

## Quick Start

```php
use SimpleDB\Adapters\FileAdapter;
use SimpleDB\SimpleDB;

$adapter = new FileAdapter('/path/to/storage');
$db      = new SimpleDB('cars', $adapter);

// Create — returns auto-generated ID
$id = $db->post(['make' => 'Honda', 'model' => 'Civic', 'color' => 'blue']);

// Read
$car = $db->get($id);

// Update
$db->put($id, ['make' => 'Honda', 'model' => 'Civic', 'color' => 'red']);

// Delete
$db->delete($id);
```

---

## Choosing a Storage Adapter

### `FileAdapter` — default, zero extra dependencies

One JSON file per document.  Simple, portable, works everywhere.

```php
$adapter = new FileAdapter('/path/to/storage');
```

**Bottleneck:** each `get()` opens, reads, and closes a separate file; `getAll()` / `query()` do this N times.  Fine for tens of thousands of small documents; gets slower above that.

---

### `SqliteAdapter` — overcomes I/O limits, no extra dependencies

Stores every collection in a single SQLite database file using PHP's built-in `pdo_sqlite`.  Key benefits over `FileAdapter`:

- **WAL journal mode** — multiple readers run concurrently without blocking each other or the writer.
- **One I/O round-trip** for full collection reads (`SELECT … WHERE collection = ?`).
- **`batchWrite()` uses a single transaction** — one `fsync` for N documents instead of N.
- **`count()` is a cheap `SELECT COUNT(*)`** — no document parsing.
- **64 MB in-memory page cache** keeps hot pages in RAM.
- **5-second busy timeout** instead of immediate `SQLITE_BUSY` failure under contention.
- **Native query push-down** — QueryBuilder conditions are translated to SQL via `json_extract()`, bypassing PHP-level streaming entirely (see below).

```php
use SimpleDB\Adapters\SqliteAdapter;

// File-backed (persists across requests)
$adapter = new SqliteAdapter('/var/data/myapp.sqlite');

// In-memory (testing / ephemeral data — lost when the process ends)
$adapter = new SqliteAdapter(':memory:');

// Custom document size limit
$adapter = new SqliteAdapter('/var/data/myapp.sqlite', maxDocumentSize: 1 * 1024 * 1024);

$db = new SimpleDB('cars', $adapter);
```

Multiple collections share the same SQLite file:

```php
$cars   = new SimpleDB('cars',   $adapter);
$trucks = new SimpleDB('trucks', $adapter);
```

**Requirement:** `ext-pdo_sqlite` (ships with PHP; verify with `php -m | grep pdo_sqlite`).

#### Native Query Push-Down

`SqliteAdapter` implements `NativeQueryInterface`.  When the QueryBuilder detects this, it generates and executes a single SQL statement instead of streaming every document through PHP:

```php
// This translates to a single SQL query:
//   SELECT id, data FROM documents
//    WHERE collection = ?
//      AND json_extract(data, '$.type') = ?
//      AND json_extract(data, '$.price') < ?
//    ORDER BY json_extract(data, '$.name') ASC
//    LIMIT 10
$results = $db->where('type', 'sedan')
              ->where('price', '<', 28000)
              ->orderBy('name')
              ->limit(10)
              ->get();
```

All 13 QueryBuilder operators are supported natively, including `contains` / `starts_with` / `ends_with` (case-sensitive LIKE), `in` / `not_in`, and `null` / `not_null`. Dot-notation nested fields map to `json_extract` paths:

| PHP field notation | SQL json_extract path |
|---|---|
| `'name'` | `$.name` |
| `'address.city'` | `$.address.city` |
| `'items.0.sku'` | `$.items[0].sku` |

---

### `ApcuCacheAdapter` — shared-memory L1 cache (wraps any adapter)

A transparent decorator that keeps individual document reads in APCu — PHP's shared memory store, accessible to every worker in a PHP-FPM pool simultaneously.  A cache hit is a single in-process memory lookup with zero filesystem I/O.

```php
use SimpleDB\Adapters\ApcuCacheAdapter;
use SimpleDB\Adapters\FileAdapter;
use SimpleDB\Adapters\SqliteAdapter;

// Wrap FileAdapter
$db = new SimpleDB('cars', new ApcuCacheAdapter(new FileAdapter('/path/to/storage'), ttl: 60));

// Wrap SqliteAdapter for maximum throughput
$db = new SimpleDB('sessions', new ApcuCacheAdapter(new SqliteAdapter('/path/to/store.sqlite'), ttl: 30));
```

| Parameter    | Default          | Description                                           |
|--------------|------------------|-------------------------------------------------------|
| `$inner`     | —                | Any `StorageInterface` adapter to wrap                |
| `$ttl`       | `0` (no expiry)  | APCu entry TTL in seconds                             |
| `$keyPrefix` | `'simpledb:'`    | Namespace prefix for APCu keys                        |

**How cache invalidation works:**

| Operation | Cache behaviour |
|-----------|----------------|
| `read()` | APCu hit → return immediately; miss → read inner, store in APCu |
| `write()` | Write to inner, update APCu entry, invalidate ID-list cache |
| `delete()` | Delete from inner, evict APCu entry, invalidate ID-list cache |
| `readAll()` | `listIds()` from APCu (or inner on miss), then serve each doc via per-doc cache |
| `listIds()` | APCu hit → return cached list; miss → query inner, store in APCu |
| `stream()` | Delegate to inner; warm APCu as each doc passes through |
| `count()` | Delegate to inner (accurate count required) |

Missing documents are stored as a tombstone to prevent repeated storage lookups for non-existent keys.

```php
// Manual eviction of a single document
$cached->evict('cars', $id);

// Evict all cached entries for a collection (documents + ID list)
$cached->evictCollection('cars');

// Flush all APCu keys (affects the whole PHP process, use carefully)
$cached->flushAll();
```

#### Native query push-down through the cache layer

`ApcuCacheAdapter` implements `DecoratorInterface`.  QueryBuilder automatically peeks through decorator layers to find a `NativeQueryInterface` adapter underneath.  This means SQL push-down works transparently even when APCu caching is active:

```php
// ApcuCacheAdapter wrapping SqliteAdapter:
// QueryBuilder → peeks through ApcuCacheAdapter → finds SqliteAdapter (NativeQueryInterface)
// → executes a single SQL query instead of PHP streaming
$db = new SimpleDB(
    'products',
    new ApcuCacheAdapter(new SqliteAdapter('/var/data/myapp.sqlite'), ttl: 60),
);

$results = $db->where('category', 'electronics')
              ->where('price', '<', 500)
              ->orderBy('name')
              ->get(); // runs as SQL, not PHP streaming
```

Individual document reads from `get()` / `getAll()` still go through the APCu cache as usual.

**Requirements:** `ext-apcu` (`pecl install apcu`); for CLI set `apc.enable_cli=1` in `php.ini`.

---

## API Reference

### `SimpleDB` constructor

```php
public function __construct(
    string           $collection,
    StorageInterface $storage,
    callable|null    $logger     = null,
    bool             $timestamps = false,
)
```

| Parameter      | Type                | Description                                                                   |
|----------------|---------------------|-------------------------------------------------------------------------------|
| `$collection`  | `string`            | Collection name (alphanumeric, `_`, `-` only)                                 |
| `$storage`     | `StorageInterface`  | Storage adapter instance                                                      |
| `$logger`      | `callable\|null`   | Optional log sink: `fn(string $level, string $message, array $context): void` |
| `$timestamps`  | `bool`              | Auto-inject `_created_at` / `_updated_at` Unix timestamps                     |

---

### Fluent Query Builder

The most expressive way to query.  Start with `->where()` or `->newQuery()`, chain conditions, terminate with `->get()`, `->first()`, `->count()`, or `->exists()`.

When the underlying adapter (or the adapter it wraps) implements `NativeQueryInterface`, **all terminal methods execute as a single database query** instead of streaming documents through PHP.

```php
// Equality shorthand
$results = $db->where('category', 'fruit')->get();

// Comparison operator
$results = $db->where('price', '<', 5.0)->get();

// Chain conditions + order + paginate
$page2 = $db->where('category', 'fruit')
            ->where('stock', '>', 0)
            ->orderBy('price', 'asc')
            ->limit(10)
            ->offset(10)
            ->get();

// Terminal: first matching document
$doc = $db->where('name', 'starts_with', 'App')->first();

// Terminal: count without loading documents
$n = $db->where('category', 'veggie')->count();

// Terminal: existence check
if ($db->where('sku', 'ABC-001')->exists()) { ... }
```

#### Supported operators for `->where(field, operator, value)`

| Operator       | Matches when…                                     |
|----------------|---------------------------------------------------|
| `=` (default)  | strict `===` equality                             |
| `!=`           | strict `!==` inequality                           |
| `>`            | field value is greater than                       |
| `>=`           | field value is greater than or equal to           |
| `<`            | field value is less than                          |
| `<=`           | field value is less than or equal to              |
| `in`           | field value appears in the given array            |
| `not_in`       | field value does not appear in the given array    |
| `contains`     | field string contains the substring (case-sensitive) |
| `starts_with`  | field string starts with the prefix (case-sensitive) |
| `ends_with`    | field string ends with the suffix (case-sensitive)   |
| `null`         | field is missing or set to `null`                 |
| `not_null`     | field exists and is not `null`                    |

#### Convenience constraint methods

```php
$db->newQuery()->whereIn('status', ['active', 'pending'])->get();
$db->newQuery()->whereNotIn('type', ['archived'])->get();
$db->newQuery()->whereNull('deleted_at')->get();
$db->newQuery()->whereNotNull('email')->get();
```

#### Dot-notation for nested fields

```php
// Document: {'address': {'city': 'Paris', 'zip': '75001'}}
$results = $db->where('address.city', 'Paris')->get();
$results = $db->where('address.zip', 'starts_with', '75')->get();

// Array index access
// Document: {'tags': ['php', 'sqlite', 'nosql']}
$results = $db->where('tags.0', 'php')->get();
```

---

### PHP Native Interface Support

`SimpleDB` implements `ArrayAccess`, `Countable`, and `IteratorAggregate`, letting you use it with native PHP syntax:

```php
// ArrayAccess
$car          = $db['abc123'];    // → get($id)
$db['abc123'] = $data;            // → put($id, $data)
$db[]         = $data;            // → post($data)  (ID is auto-generated)
unset($db['abc123']);             // → delete($id), silently ignores missing

if (isset($db['abc123'])) { ... } // → exists($id)

// Countable
$total = count($db);

// IteratorAggregate
foreach ($db as $id => $document) {
    // stream all documents lazily
}
```

---

### Auto-Timestamps

Pass `timestamps: true` to automatically inject `_created_at` and `_updated_at` fields.

```php
$db = new SimpleDB('posts', $adapter, timestamps: true);

$id  = $db->post(['title' => 'Hello World']);
$doc = $db->get($id);
// ['title' => 'Hello World', '_created_at' => 1700000000, '_updated_at' => 1700000000]

$db->put($id, ['title' => 'Updated']);
$doc = $db->get($id);
// ['title' => 'Updated', '_updated_at' => 1700000001]
// Note: _created_at is NOT added by put()
```

- `_created_at` is only set by `post()` (not `put()`).
- `_updated_at` is set by both `post()` and `put()`.
- Pre-existing values in the document are preserved (`??=` semantics).

---

### Lifecycle Hooks

Register callbacks that fire around write and delete operations.  Hooks run in registration order.

```php
// Transform data before it is written (must return the array)
$db->beforeWrite(function (string $id, array $data, bool $isNew): array {
    $data['slug'] = strtolower(str_replace(' ', '-', $data['title'] ?? ''));
    return $data;
});

// React after a successful write
$db->afterWrite(function (string $id, array $data, bool $isNew): void {
    $cache->invalidate($id);
});

// Validate / audit before deletion
$db->beforeDelete(function (string $id): void {
    auditLog("about to delete {$id}");
});

// React after deletion
$db->afterDelete(function (string $id): void {
    searchIndex->remove($id);
});
```

Hook execution order per operation:

```
post() / put()                      delete()
──────────────────────────────      ────────────────────────
1. inject timestamps (if enabled)   1. beforeDelete hooks
2. beforeWrite hooks (→ data)       2. storage::delete()
3. storage::write()                 3. cache invalidation
4. cache update                     4. afterDelete hooks
5. afterWrite hooks
```

---

### CRUD Methods

#### `get(string $id): array|null`

```php
$doc = $db->get('abc123');  // null when missing
```

#### `getAll(): array`

```php
$all = $db->getAll();  // ['id1' => [...], 'id2' => [...]]
```

#### `stream(): Generator`

Lazily yield documents without loading the whole collection into memory:

```php
foreach ($db->stream() as $id => $document) { ... }
// Equivalent to: foreach ($db as $id => $document) { ... }
```

#### `count(): int`

```php
$n = $db->count();           // total documents
$n = count($db);             // same, via Countable
$n = $db->where('x', 1)->count();  // filtered count
```

#### `post(array $data): string`

Creates a document with an auto-generated ID.  Returns the ID.

```php
$id = $db->post(['name' => 'Alice', 'age' => 30]);
```

#### `batchPost(array $documents): string[]`

```php
$ids = $db->batchPost([
    ['make' => 'Honda'],
    ['make' => 'Toyota'],
]);
```

#### `put(string $id, array $data): void`

```php
$db->put('my-id', ['name' => 'Bob']);
```

#### `batchPut(array $documents): void`

```php
$db->batchPut([
    'id-1' => ['make' => 'Honda', 'color' => 'blue'],
    'id-2' => ['make' => 'Toyota', 'color' => 'red'],
]);
```

#### `delete(string $id): void`

Throws `DocumentNotFoundException` when the document does not exist.

```php
try {
    $db->delete($id);
} catch (DocumentNotFoundException $e) { ... }
```

#### `query(array $criteria, int $limit = 0, int $offset = 0): array`

Simple strict-equality filter (backward-compatible).  For richer queries use the fluent builder.

```php
$results = $db->query(['make' => 'Honda', 'color' => 'blue']);
$page    = $db->query([], limit: 20, offset: 40);
```

#### `timestamp(string $id): int|null`

```php
$ts = $db->timestamp($id);
echo date('Y-m-d H:i:s', $ts);
```

#### `exists(string $id): bool`

Checks in-process cache first, then falls back to storage.

#### `getCollection(): string`

#### `clearCache(): void`

```php
$db->clearCache();  // force next get() to re-read from disk/DB
```

---

### `FileAdapter`

```php
public function __construct(
    string $storageDir,
    int    $maxDocumentSize = 5 * 1024 * 1024,  // 5 MiB
)
```

```php
$adapter = new FileAdapter('/var/data/myapp');

// Custom 256 KiB limit
$adapter = new FileAdapter('/var/data/myapp', maxDocumentSize: 256 * 1024);
```

- Directories are created with mode `0750` (no world access).
- All paths are verified with `realpath()` to guard against symlink escapes.
- Documents exceeding `$maxDocumentSize` throw `StorageException`.

---

## Advanced Usage

### Debug Logging

```php
$db = new SimpleDB('cars', $adapter, logger: function (string $level, string $msg, array $ctx): void {
    error_log("[simpledb:{$level}] {$msg}");
});
```

### Input Validation with Hooks

```php
$db->beforeWrite(function (string $id, array $data, bool $isNew): array {
    if (empty($data['email'])) {
        throw new \InvalidArgumentException('email is required');
    }
    $data['email'] = strtolower($data['email']);
    return $data;
});
```

### Multi-stage Data Pipeline

```php
$db->beforeWrite(fn($id, $data, $new) => array_merge($data, ['source' => 'api']));
$db->beforeWrite(fn($id, $data, $new) => array_filter($data, fn($v) => $v !== ''));
$db->afterWrite(fn($id, $data) => $searchIndex->index($id, $data));
```

### Streaming Large Collections

```php
foreach ($db as $id => $document) {
    processDocument($document);  // one document in memory at a time
}
```

### Custom Storage Adapter

Any class implementing `StorageInterface` can replace `FileAdapter`.  Optionally implement `NativeQueryInterface` to push query conditions into the storage engine, and `DecoratorInterface` if your adapter wraps another:

```php
use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Contracts\NativeQueryInterface;

class RedisAdapter implements StorageInterface, NativeQueryInterface
{
    // StorageInterface: read, readAll, stream, write, batchWrite,
    //                   delete, exists, listIds, count, timestamp
    //
    // NativeQueryInterface: executeNativeQuery, executeNativeFirst,
    //                       executeNativeCount, executeNativeExists
}

$db = new SimpleDB('sessions', new RedisAdapter($redis));
// QueryBuilder automatically uses native queries via RedisAdapter
```

---

## Safety Features

| Feature                      | Details                                                                                          |
|------------------------------|--------------------------------------------------------------------------------------------------|
| **Atomic writes**            | Temp file + `rename()` prevents partial-write corruption                                         |
| **File locking**             | `flock(LOCK_EX)` serialises concurrent writers per document                                      |
| **ID sanitisation**          | Only `[a-zA-Z0-9_-]` permitted — blocks path-traversal attempts                                 |
| **realpath() verification**  | Constructed paths are resolved and verified to stay inside the storage root (blocks symlink escapes) |
| **Restrictive permissions**  | Directories created with mode `0750` (no world access)                                           |
| **Document size limit**      | Configurable max JSON size per document (default 5 MiB)                                          |
| **No error suppression**     | No `@` operator — all failures surface as typed exceptions                                       |
| **Strict types**             | `declare(strict_types=1)` in every file                                                          |

---

## Performance Features

| Feature                      | Details                                                                                          |
|------------------------------|--------------------------------------------------------------------------------------------------|
| **In-process cache**         | `get()` / `exists()` serve from a per-instance cache after the first read; invalidated on write/delete |
| **APCu shared-memory cache** | `ApcuCacheAdapter` shares cached documents and ID lists across all PHP-FPM workers               |
| **APCu ID-list cache**       | `listIds()` result per collection is cached in APCu; invalidated immediately on write/delete    |
| **Tombstone cache**          | Missing-document lookups are cached so repeated `get('ghost-id')` skips storage entirely         |
| **SQL push-down queries**    | `SqliteAdapter` implements `NativeQueryInterface`; all QueryBuilder conditions run as SQL         |
| **Decorator peek-through**   | QueryBuilder peels through `ApcuCacheAdapter` layers to find `SqliteAdapter` for SQL push-down  |
| **Streaming reads**          | `stream()` / `foreach ($db ...)` yield documents lazily via Generator                            |
| **Batch operations**         | `batchPost()` / `batchPut()` reduce per-operation overhead                                       |
| **SQLite WAL + page cache**  | Concurrent readers, 64 MB page cache, single-transaction batch writes                            |
| **Cheap counts**             | `count()` uses `SELECT COUNT(*)` (SQLite) or reads only filenames (FileAdapter) — no document parsing |
| **Lazy query filtering**     | Without NativeQueryInterface, QueryBuilder streams one document at a time and breaks early        |
| **Pagination**               | `query(limit:, offset:)` and `->limit()->offset()` on the builder (SQL `LIMIT/OFFSET` for SQLite) |

---

## Error Handling

| Exception                    | When thrown                                                      |
|------------------------------|------------------------------------------------------------------|
| `StorageException`           | I/O error, invalid ID/collection name, document too large, path escapes root, APCu not available |
| `DocumentNotFoundException`  | `delete()` called with an ID that does not exist                 |

Both extend `SimpleDB\Exceptions\SimpleDBException`.

---

## Running Tests

```bash
composer install
vendor/bin/phpunit          # 172 tests (14 skipped when APCu not installed)
```

Static analysis:

```bash
vendor/bin/phpstan analyse src --level=5
```

---

## Project Structure

```
SimpleDB/
├── src/
│   ├── SimpleDB.php                     # Main class
│   ├── Contracts/
│   │   ├── StorageInterface.php         # Storage adapter contract
│   │   ├── NativeQueryInterface.php     # SQL/native query push-down contract
│   │   └── DecoratorInterface.php       # Decorator peek-through contract
│   ├── Adapters/
│   │   ├── FileAdapter.php              # One JSON file per document
│   │   ├── SqliteAdapter.php            # SQLite (WAL, transactions, NativeQueryInterface)
│   │   └── ApcuCacheAdapter.php         # APCu cache decorator (DecoratorInterface)
│   ├── Query/
│   │   └── QueryBuilder.php             # Fluent query builder (auto-detects NativeQueryInterface)
│   └── Exceptions/
│       ├── SimpleDBException.php        # Base exception
│       ├── DocumentNotFoundException.php
│       └── StorageException.php
├── tests/
│   ├── SimpleDBTest.php                 # Core CRUD / security / performance (44)
│   ├── QueryBuilderTest.php             # Fluent query builder (33)
│   ├── SimpleDBFeaturesTest.php         # Interfaces / hooks / timestamps (28)
│   ├── SqliteAdapterTest.php            # SqliteAdapter full coverage (23)
│   ├── NativeQueryTest.php              # SQL push-down via NativeQueryInterface (30)
│   └── ApcuCacheAdapterTest.php         # ApcuCacheAdapter (skipped if APCu absent)
├── composer.json
├── phpunit.xml
└── README.md
```

---

## Migrating from `Simple_DB` (v1)

| Old API                                  | New API                                    |
|------------------------------------------|--------------------------------------------|
| `new Simple_DB('cars')`                  | `new SimpleDB('cars', new FileAdapter($dir))` |
| `$db->get($id)`                          | `$db->get($id)` (returns `null` not `false`) |
| `$db->get()`                             | `$db->getAll()`                            |
| `$db->post($array)`                      | `$db->post($array)`                        |
