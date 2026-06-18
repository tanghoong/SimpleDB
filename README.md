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
public function __construct(string $collection, StorageInterface $storage)
```

| Parameter     | Type               | Description                       |
|---------------|--------------------|-----------------------------------|
| `$collection` | `string`           | Name of the document collection   |
| `$storage`    | `StorageInterface` | Storage adapter instance          |

---

#### `get(string $id): array|null`

Retrieve a single document by ID. Returns `null` when the document does not exist.

```php
$doc = $db->get('abc123');
if ($doc === null) {
    // document not found
}
```

---

#### `getAll(): array`

Return all documents in the collection as an associative array keyed by document ID.

```php
$all = $db->getAll();
// ['id1' => [...], 'id2' => [...]]
```

---

#### `post(array $data): string`

Create a new document with an auto-generated ID. Returns the new document ID.

```php
$id = $db->post(['name' => 'Alice', 'age' => 30]);
```

---

#### `put(string $id, array $data): void`

Write (create or replace) a document with an explicit ID.

```php
$db->put('my-custom-id', ['name' => 'Bob']);
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

#### `query(array $criteria): array`

Return all documents whose top-level fields match every key/value pair in `$criteria`.
Returns an associative array keyed by document ID (empty array if no matches).

```php
// Find all blue Hondas
$results = $db->query(['make' => 'Honda', 'color' => 'blue']);
```

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

```php
if ($db->exists($id)) {
    // document is there
}
```

---

### `FileAdapter`

#### Constructor

```php
public function __construct(string $storageDir)
```

Creates (if needed) and validates the storage root directory. Throws `StorageException` if the directory cannot be created or is invalid.

```php
$adapter = new FileAdapter('/var/data/myapp');
```

**ID / collection name rules:** only `[a-zA-Z0-9_-]` characters are permitted. Any other character (including path separators) causes a `StorageException` to be thrown immediately, preventing path-traversal attacks.

---

## Advanced Usage

### Custom Storage Directory

```php
$adapter = new FileAdapter(sys_get_temp_dir() . '/myapp-data');
$db      = new SimpleDB('sessions', $adapter);
```

### Error Handling

All exceptions extend `SimpleDB\Exceptions\SimpleDBException`:

| Exception                    | When thrown                                       |
|------------------------------|---------------------------------------------------|
| `StorageException`           | I/O failure or invalid ID / collection name       |
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

Any class implementing `SimpleDB\Contracts\StorageInterface` can be used as the storage backend:

```php
use SimpleDB\Contracts\StorageInterface;

class RedisAdapter implements StorageInterface
{
    // implement read, readAll, write, delete, exists, listIds, timestamp
}

$db = new SimpleDB('sessions', new RedisAdapter($redis));
```

---

## Safety Features

| Feature                    | Details                                                       |
|----------------------------|---------------------------------------------------------------|
| **Atomic writes**          | Documents are written to a temp file then `rename()`-d into place, preventing partial-write corruption |
| **File locking**           | `flock(LOCK_EX)` ensures exclusive access during writes       |
| **ID sanitisation**        | Rejects any ID/collection name that contains characters outside `[a-zA-Z0-9_-]`, blocking path-traversal attacks |
| **No error suppression**   | No `@` operator — all failures surface as typed exceptions    |
| **Strict types**           | `declare(strict_types=1)` in every PHP file                   |

---

## Running Tests

```bash
composer install
vendor/bin/phpunit
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
│   └── SimpleDBTest.php                 # PHPUnit test suite
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

