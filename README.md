# SimpleDB

![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-8892BF)
![License](https://img.shields.io/badge/license-MIT-green)

A lightweight, zero-dependency **NoSQL JSON Document Storage** library for PHP 8.2+.

SimpleDB lets you use the local filesystem as a simple document store — one JSON file per document — without spinning up a database server. It is ideal for CLI tools, static-site generators, feature-flag stores, local development data, and any single-machine scenario where you need quick, structured persistence.

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

// Point the adapter at a writable directory
$adapter = new FileAdapter('/path/to/storage');

// Open (or create) a collection called "cars"
$db = new SimpleDB('cars', $adapter);

// Create a document — returns the auto-generated ID
$id = $db->post(['make' => 'Honda', 'model' => 'Civic', 'color' => 'blue']);

// Retrieve it
$car = $db->get($id);       // ['make' => 'Honda', 'model' => 'Civic', 'color' => 'blue']

// Update it
$db->put($id, ['make' => 'Honda', 'model' => 'Civic', 'color' => 'red']);

// Delete it
$db->delete($id);
```

---

## API Reference

### `SimpleDB`

#### Constructor

```php
public function __construct(
    string $collection,
    StorageInterface $storage,
    callable|null $logger = null,
)
```

| Parameter     | Type                | Description                                                                 |
|---------------|---------------------|-----------------------------------------------------------------------------|
| `$collection` | `string`            | Name of the document collection                                             |
| `$storage`    | `StorageInterface`  | Storage adapter instance                                                    |
| `$logger`     | `callable\|null`   | Optional log sink: `fn(string $level, string $message, array $context): void` |

The optional `$logger` callable lets you hook into internal debug events (cache hits, writes, deletes) without any external dependency:

```php
$db = new SimpleDB('cars', $adapter, function (string $level, string $msg, array $ctx): void {
    error_log("[{$level}] {$msg}");
});
```

---

#### `get(string $id): array|null`

Retrieve a single document by ID. Returns `null` when the document does not exist.
Serves from the in-process cache on subsequent calls within the same request.

```php
$doc = $db->get('abc123');
if ($doc === null) {
    // document not found
}
```

---

#### `getAll(): array`

Return all documents in the collection as an associative array keyed by document ID.
Populates the in-process cache for all returned documents.

```php
$all = $db->getAll();
// ['id1' => [...], 'id2' => [...]]
```

---

#### `stream(): Generator`

Lazily yield documents one at a time without loading the entire collection into memory.
Ideal for large collections.

```php
foreach ($db->stream() as $id => $document) {
    // process one document at a time
}
```

---

#### `count(): int`

Return the total number of documents in the collection.

```php
$total = $db->count(); // e.g. 42
```

---

#### `post(array $data): string`

Create a new document with an auto-generated ID. Returns the new document ID.

```php
$id = $db->post(['name' => 'Alice', 'age' => 30]);
```

---

#### `batchPost(array $documents): string[]`

Create multiple documents in a single call. Returns an array of the generated IDs
in the same order as the input.

```php
$ids = $db->batchPost([
    ['make' => 'Honda'],
    ['make' => 'Toyota'],
    ['make' => 'Ford'],
]);
// $ids = ['a1b2c3d4...', 'e5f6a7b8...', ...]
```

---

#### `put(string $id, array $data): void`

Write (create or replace) a document with an explicit ID.

```php
$db->put('my-custom-id', ['name' => 'Bob']);
```

---

#### `batchPut(array $documents): void`

Write (create or replace) multiple documents with explicit IDs in a single call.

```php
$db->batchPut([
    'car-1' => ['make' => 'Honda', 'color' => 'blue'],
    'car-2' => ['make' => 'Toyota', 'color' => 'red'],
]);
```

---

#### `delete(string $id): void`

Delete a document. Throws `DocumentNotFoundException` if the document does not exist.

```php
use SimpleDB\Exceptions\DocumentNotFoundException;

try {
    $db->delete($id);
} catch (DocumentNotFoundException $e) {
    // handle missing document
}
```

---

#### `query(array $criteria, int $limit = 0, int $offset = 0): array`

Return all documents whose top-level fields match every key/value pair in `$criteria`.
Returns an associative array keyed by document ID (empty array if no matches).

Supports pagination via `$limit` and `$offset`:

```php
// Find all blue Hondas
$results = $db->query(['make' => 'Honda', 'color' => 'blue']);

// Paginate: second page of 10 results
$page2 = $db->query([], limit: 10, offset: 10);
```

| Parameter   | Type  | Default | Description                                      |
|-------------|-------|---------|--------------------------------------------------|
| `$criteria` | array | —       | Field/value pairs every matching document must satisfy |
| `$limit`    | int   | `0`     | Maximum results to return (`0` = no limit)       |
| `$offset`   | int   | `0`     | Number of matching results to skip               |

---

#### `timestamp(string $id): int|null`

Return the Unix timestamp of the last modification time for a document, or `null` if it does not exist.

```php
$ts = $db->timestamp($id);
echo date('Y-m-d H:i:s', $ts);
```

---

#### `exists(string $id): bool`

Check whether a document with the given ID is present in the collection.
Checks the in-process cache before hitting storage.

```php
if ($db->exists($id)) {
    // document is there
}
```

---

#### `getCollection(): string`

Return the name of the current collection.

---

#### `clearCache(): void`

Discard the in-process document cache. Useful when another process may have modified
the collection externally.

```php
$db->clearCache();
$freshDoc = $db->get($id); // always reads from storage
```

---

### `FileAdapter`

#### Constructor

```php
public function __construct(
    string $storageDir,
    int $maxDocumentSize = 5 * 1024 * 1024,
)
```

| Parameter         | Type   | Default   | Description                                          |
|-------------------|--------|-----------|------------------------------------------------------|
| `$storageDir`     | string | —         | Writable root directory for all collections          |
| `$maxDocumentSize`| int    | 5 MiB     | Maximum JSON byte size allowed per document          |

Creates (if needed) and validates the storage root directory with permissions `0750`
(owner read/write/execute, group read/execute, no world access).
Throws `StorageException` if the directory cannot be created or is invalid.

```php
// Default 5 MiB per document
$adapter = new FileAdapter('/var/data/myapp');

// Custom 256 KiB limit
$adapter = new FileAdapter('/var/data/myapp', maxDocumentSize: 256 * 1024);
```

**ID / collection name rules:** only `[a-zA-Z0-9_-]` characters are permitted.
Any other character (including path separators) causes a `StorageException` immediately,
preventing path-traversal attacks. All constructed paths are verified via `realpath()`
to guard against symlink-based escapes.

---

## Advanced Usage

### Streaming Large Collections

Use `stream()` to process a large collection without loading everything into memory:

```php
$adapter = new FileAdapter('/path/to/storage');
$db      = new SimpleDB('events', $adapter);

foreach ($db->stream() as $id => $event) {
    processEvent($event);
}
```

### Batch Operations

```php
// Create many documents efficiently
$ids = $db->batchPost([
    ['user' => 'alice', 'score' => 95],
    ['user' => 'bob',   'score' => 87],
    ['user' => 'carol', 'score' => 92],
]);

// Upsert many documents with known IDs
$db->batchPut([
    'config-email'   => ['enabled' => true,  'host' => 'smtp.example.com'],
    'config-logging' => ['enabled' => false, 'level' => 'warning'],
]);
```

### Pagination

```php
$pageSize = 20;
$page     = (int) ($_GET['page'] ?? 1);

$results = $db->query(
    criteria: ['status' => 'active'],
    limit:    $pageSize,
    offset:   ($page - 1) * $pageSize,
);
```

### Debug Logging

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$log = new Logger('simpledb');
$log->pushHandler(new StreamHandler('php://stderr'));

$db = new SimpleDB('sessions', $adapter, function (string $level, string $msg, array $ctx) use ($log): void {
    $log->$level($msg, $ctx);
});
```

### Custom Storage Directory

```php
$adapter = new FileAdapter(sys_get_temp_dir() . '/myapp-data');
$db      = new SimpleDB('sessions', $adapter);
```

### Error Handling

All exceptions extend `SimpleDB\Exceptions\SimpleDBException`:

| Exception                    | When thrown                                       |
|------------------------------|---------------------------------------------------|
| `StorageException`           | I/O failure, invalid ID / collection name, document too large, path escapes root |
| `DocumentNotFoundException`  | `delete()` called with an ID that does not exist  |

```php
use SimpleDB\Exceptions\SimpleDBException;
use SimpleDB\Exceptions\DocumentNotFoundException;
use SimpleDB\Exceptions\StorageException;

try {
    $db->delete('unknown-id');
} catch (DocumentNotFoundException $e) {
    echo "Not found: " . $e->getMessage();
} catch (StorageException $e) {
    echo "I/O error: " . $e->getMessage();
} catch (SimpleDBException $e) {
    echo "Library error: " . $e->getMessage();
}
```

### Implementing a Custom Storage Adapter

Any class implementing `SimpleDB\Contracts\StorageInterface` can be used as the storage backend.
Note that `StorageInterface` now includes `stream()`, `batchWrite()`, and `count()`:

```php
use SimpleDB\Contracts\StorageInterface;

class RedisAdapter implements StorageInterface
{
    // implement: read, readAll, stream, write, batchWrite,
    //            delete, exists, listIds, count, timestamp
}

$db = new SimpleDB('sessions', new RedisAdapter($redis));
```

---

## Safety Features

| Feature                      | Details                                                                                          |
|------------------------------|--------------------------------------------------------------------------------------------------|
| **Atomic writes**            | Documents are written to a temp file then `rename()`-d into place, preventing partial-write corruption |
| **File locking**             | `flock(LOCK_EX)` ensures exclusive access during writes                                          |
| **ID sanitisation**          | Rejects any ID/collection name containing characters outside `[a-zA-Z0-9_-]`, blocking path-traversal |
| **realpath() verification**  | All constructed paths are resolved and verified to stay inside the storage root, blocking symlink escapes |
| **Restrictive permissions**  | Storage and collection directories are created with mode `0750` (no world access)               |
| **Document size limit**      | Configurable maximum JSON byte size per document (default 5 MiB); oversized writes throw `StorageException` |
| **No error suppression**     | No `@` operator — all failures surface as typed exceptions                                       |
| **Strict types**             | `declare(strict_types=1)` in every PHP file                                                      |

---

## Performance Features

| Feature                      | Details                                                                                          |
|------------------------------|--------------------------------------------------------------------------------------------------|
| **In-process cache**         | `get()` and `exists()` serve from a per-instance cache after the first read; invalidated on `put()` / `delete()` |
| **Streaming reads**          | `stream()` yields documents one at a time via a PHP Generator, avoiding full collection load into memory |
| **Batch operations**         | `batchPost()` and `batchPut()` reduce per-operation overhead for bulk inserts and updates        |
| **Cheap counts**             | `count()` reads only file names — no document parsing required                                   |
| **Pagination**               | `query($criteria, limit: N, offset: M)` avoids loading unnecessary results                      |

---

## Running Tests

```bash
composer install
vendor/bin/phpunit
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
│   │   └── FileAdapter.php              # File-based storage adapter
│   └── Exceptions/
│       ├── SimpleDBException.php        # Base exception
│       ├── DocumentNotFoundException.php
│       └── StorageException.php
├── tests/
│   └── SimpleDBTest.php                 # PHPUnit test suite (44 tests)
├── composer.json
├── phpunit.xml
└── README.md
```

---

## Migrating from `Simple_DB` (v1)

The original `Simple_DB.php` (2013) has been removed. Key migration points:

| Old API                                  | New API                                    |
|------------------------------------------|--------------------------------------------|
| `new Simple_DB('cars')`                  | `new SimpleDB('cars', new FileAdapter($dir))` |
| `$db->get($id)`                          | `$db->get($id)` (returns `null` not `false`) |
| `$db->get()`                             | `$db->getAll()`                            |
| `$db->post($array)`                      | `$db->post($array)`                        |
| `$db->put($id, $array)`                  | `$db->put($id, $array)`                    |
| `$db->delete($id)`                       | `$db->delete($id)` (throws on missing)     |
| `$db->query('color=blue&make=Honda')`    | `$db->query(['color' => 'blue', 'make' => 'Honda'])` |
| `$db->timestamp($id)`                    | `$db->timestamp($id)` (returns `null` not `false`) |
| `$db->getJSON($id)` *(broken in v1)*     | `json_encode($db->get($id))`               |

### Migrating Custom Storage Adapters (v2 → v3)

`StorageInterface` gained three new methods. Add these to any custom adapter:

```php
// Lazy document streaming
public function stream(string $collection): \Generator { ... }

// Bulk write
public function batchWrite(string $collection, array $documents): void { ... }

// Cheap document count
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
