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
| `read()`  | APCu hit → return immediately; miss → read inner, store in APCu |
| `write()` | Write to inner, then update APCu entry |
| `delete()` | Delete from inner, then evict APCu entry |
| `readAll()` | `listIds()` from inner (one syscall), then serve each doc via APCu |
| `stream()` | Delegate to inner, warm APCu as each doc passes through |
| `count()` | Delegate to inner (accurate count required) |

Missing documents are stored as a tombstone to prevent repeated storage lookups for non-existent keys.

```php
// Manual eviction
$cached->evict('cars', $id);

// Flush all APCu keys (affects the whole PHP process, use carefully)
$cached->flushAll();
```

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
| `contains`     | field string contains the substring               |
| `starts_with`  | field string starts with the prefix               |
| `ends_with`    | field string ends with the suffix                 |
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
$db->clearCache();  // force next get() to re-read from disk
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

Any class implementing `StorageInterface` can replace `FileAdapter`:

```php
use SimpleDB\Contracts\StorageInterface;

class RedisAdapter implements StorageInterface
{
    // read, readAll, stream, write, batchWrite,
    // delete, exists, listIds, count, timestamp
}

$db = new SimpleDB('sessions', new RedisAdapter($redis));
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
| **Streaming reads**          | `stream()` / `foreach ($db ...)` yield documents lazily via Generator                            |
| **Batch operations**         | `batchPost()` / `batchPut()` reduce per-operation overhead                                       |
| **Cheap counts**             | `count()` reads only filenames — no document parsing                                             |
| **Lazy query filtering**     | QueryBuilder streams and evaluates conditions one document at a time; breaks early when possible  |
| **Pagination**               | `query(limit:, offset:)` and `->limit()->offset()` on the builder                               |

---

## Error Handling

| Exception                    | When thrown                                                      |
|------------------------------|------------------------------------------------------------------|
| `StorageException`           | I/O error, invalid ID/collection name, document too large, path escapes root |
| `DocumentNotFoundException`  | `delete()` called with an ID that does not exist                 |

Both extend `SimpleDB\Exceptions\SimpleDBException`.

---

## Running Tests

```bash
composer install
vendor/bin/phpunit          # 105 tests
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
│   │   └── StorageInterface.php         # Storage adapter contract
│   ├── Adapters/
│   │   ├── FileAdapter.php              # One JSON file per document
│   │   ├── SqliteAdapter.php            # SQLite-backed (WAL, transactions)
│   │   └── ApcuCacheAdapter.php         # APCu shared-memory cache decorator
│   ├── Query/
│   │   └── QueryBuilder.php             # Fluent query builder
│   └── Exceptions/
│       ├── SimpleDBException.php        # Base exception
│       ├── DocumentNotFoundException.php
│       └── StorageException.php
├── tests/
│   ├── SimpleDBTest.php                 # Core CRUD / security / performance (44)
│   ├── QueryBuilderTest.php             # Fluent query builder (33)
│   ├── SimpleDBFeaturesTest.php         # Interfaces / hooks / timestamps (28)
│   ├── SqliteAdapterTest.php            # SqliteAdapter full coverage (23)
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
| `$db->put($id, $array)`                  | `$db->put($id, $array)`                    |
| `$db->delete($id)`                       | `$db->delete($id)` (throws on missing)     |
| `$db->query('color=blue&make=Honda')`    | `$db->where('color','blue')->where('make','Honda')->get()` |
| `$db->timestamp($id)`                    | `$db->timestamp($id)` (returns `null` not `false`) |

### Migrating Custom Storage Adapters (v2 → v3)

`StorageInterface` gained three new methods — add these to any custom adapter:

```php
public function stream(string $collection): \Generator { ... }
public function batchWrite(string $collection, array $documents): void { ... }
public function count(string $collection): int { ... }
```

---

## Requirements

- PHP ≥ 8.2
- A writable filesystem directory for storage

---

## License

MIT © [Simplicity Solutions Group](http://simplicitysolutionsgroup.com)

---

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Write tests for your changes
4. Ensure all tests pass (`vendor/bin/phpunit`)
5. Open a pull request
