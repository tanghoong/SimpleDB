<?php

declare(strict_types=1);

namespace SimpleDB;

use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Exceptions\DocumentNotFoundException;
use SimpleDB\Query\QueryBuilder;

/**
 * @implements \ArrayAccess<string, array>
 */
class SimpleDB implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<string, array> In-process document cache; invalidated on write/delete. */
    private array $cache = [];

    private readonly ?\Closure $logger;

    /** @var list<\Closure> */
    private array $beforeWriteHooks = [];

    /** @var list<\Closure> */
    private array $afterWriteHooks = [];

    /** @var list<\Closure> */
    private array $beforeDeleteHooks = [];

    /** @var list<\Closure> */
    private array $afterDeleteHooks = [];

    /**
     * @param string           $collection  Collection name.
     * @param StorageInterface $storage     Storage adapter.
     * @param callable|null    $logger      Optional log sink: fn(string $level, string $message, array $context): void
     * @param bool             $timestamps  When true, auto-injects _created_at (post only) and _updated_at (post/put).
     */
    public function __construct(
        private readonly string $collection,
        private readonly StorageInterface $storage,
        callable|null $logger = null,
        private readonly bool $timestamps = false,
    ) {
        $this->logger = $logger !== null ? \Closure::fromCallable($logger) : null;
    }

    // -------------------------------------------------------------------------
    // ArrayAccess
    // -------------------------------------------------------------------------

    public function offsetExists(mixed $offset): bool
    {
        return $this->exists((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    /**
     * Assign a document by ID or append with auto-generated ID.
     *
     * $db[$id] = $data  →  put($id, $data)
     * $db[]    = $data  →  post($data)  (generated ID is not retrievable from this syntax)
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('SimpleDB values must be arrays.');
        }

        if ($offset === null) {
            $this->post($value);
        } else {
            $this->put((string) $offset, $value);
        }
    }

    /**
     * Delete a document.  Silently ignores a missing document (consistent with unset() semantics).
     */
    public function offsetUnset(mixed $offset): void
    {
        try {
            $this->delete((string) $offset);
        } catch (DocumentNotFoundException) {
            // intentionally swallowed — unset on a missing key is a no-op
        }
    }

    // -------------------------------------------------------------------------
    // Countable
    // -------------------------------------------------------------------------

    public function count(): int
    {
        return $this->storage->count($this->collection);
    }

    // -------------------------------------------------------------------------
    // IteratorAggregate
    // -------------------------------------------------------------------------

    public function getIterator(): \Traversable
    {
        return $this->storage->stream($this->collection);
    }

    // -------------------------------------------------------------------------
    // Lifecycle hooks
    // -------------------------------------------------------------------------

    /**
     * Register a hook called before every write (post / put / batch).
     * The hook receives (string $id, array $data, bool $isNew) and MUST return the (modified) data array.
     */
    public function beforeWrite(callable $hook): void
    {
        $this->beforeWriteHooks[] = \Closure::fromCallable($hook);
    }

    /**
     * Register a hook called after every successful write.
     * Signature: fn(string $id, array $data, bool $isNew): void
     */
    public function afterWrite(callable $hook): void
    {
        $this->afterWriteHooks[] = \Closure::fromCallable($hook);
    }

    /**
     * Register a hook called before a document is deleted.
     * Signature: fn(string $id): void
     */
    public function beforeDelete(callable $hook): void
    {
        $this->beforeDeleteHooks[] = \Closure::fromCallable($hook);
    }

    /**
     * Register a hook called after a document is successfully deleted.
     * Signature: fn(string $id): void
     */
    public function afterDelete(callable $hook): void
    {
        $this->afterDeleteHooks[] = \Closure::fromCallable($hook);
    }

    // -------------------------------------------------------------------------
    // Fluent query entry points
    // -------------------------------------------------------------------------

    /**
     * Start a fluent query against this collection.
     *
     * Example:
     *   $db->where('make', 'Honda')->where('year', '>', 2020)->orderBy('model')->limit(5)->get();
     */
    public function where(string $field, mixed $operatorOrValue, mixed $value = null): QueryBuilder
    {
        $qb = $this->newQuery();

        if (func_num_args() === 2) {
            return $qb->where($field, $operatorOrValue);
        }

        return $qb->where($field, $operatorOrValue, $value);
    }

    /**
     * Return a fresh QueryBuilder bound to this collection.
     */
    public function newQuery(): QueryBuilder
    {
        return new QueryBuilder($this->collection, $this->storage);
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Retrieve a single document by ID.
     * Serves from the in-process cache when available.
     * Returns null when the document does not exist.
     */
    public function get(string $id): array|null
    {
        if (array_key_exists($id, $this->cache)) {
            $this->log('debug', "cache hit for '{$id}'", ['collection' => $this->collection]);
            return $this->cache[$id];
        }

        $data = $this->storage->read($this->collection, $id);

        if ($data !== null) {
            $this->cache[$id] = $data;
        }

        return $data;
    }

    /**
     * Retrieve all documents in the collection.
     * Returns an associative array keyed by document ID and populates the cache.
     */
    public function getAll(): array
    {
        $all = $this->storage->readAll($this->collection);

        foreach ($all as $id => $doc) {
            $this->cache[$id] = $doc;
        }

        return $all;
    }

    /**
     * Lazily stream documents one by one without loading the whole collection into memory.
     *
     * @return \Generator<string, array> Yields id => document
     */
    public function stream(): \Generator
    {
        return $this->storage->stream($this->collection);
    }

    /**
     * Create a new document and return its auto-generated ID.
     */
    public function post(array $data): string
    {
        $id   = $this->generateId();
        $data = $this->runWritePipeline($id, $data, isNew: true);

        $this->storage->write($this->collection, $id, $data);
        $this->cache[$id] = $data;
        $this->log('debug', "created '{$id}'", ['collection' => $this->collection]);

        foreach ($this->afterWriteHooks as $hook) {
            $hook($id, $data, true);
        }

        return $id;
    }

    /**
     * Create multiple documents in one call.
     * Returns an array of the auto-generated IDs in the same order as $documents.
     *
     * @param  array<int, array> $documents List of document data arrays.
     * @return string[]
     */
    public function batchPost(array $documents): array
    {
        $toWrite = [];

        foreach ($documents as $data) {
            $id           = $this->generateId($toWrite);
            $processed    = $this->runWritePipeline($id, $data, isNew: true);
            $toWrite[$id] = $processed;
        }

        $this->storage->batchWrite($this->collection, $toWrite);

        foreach ($toWrite as $id => $data) {
            $this->cache[$id] = $data;
        }

        $this->log('debug', 'batch created ' . count($toWrite) . ' documents', ['collection' => $this->collection]);

        foreach ($toWrite as $id => $data) {
            foreach ($this->afterWriteHooks as $hook) {
                $hook($id, $data, true);
            }
        }

        return array_keys($toWrite);
    }

    /**
     * Write (create or replace) a document with an explicit ID.
     */
    public function put(string $id, array $data): void
    {
        $data = $this->runWritePipeline($id, $data, isNew: false);

        $this->storage->write($this->collection, $id, $data);
        $this->cache[$id] = $data;
        $this->log('debug', "upserted '{$id}'", ['collection' => $this->collection]);

        foreach ($this->afterWriteHooks as $hook) {
            $hook($id, $data, false);
        }
    }

    /**
     * Write (create or replace) multiple documents with explicit IDs.
     *
     * @param array<string, array> $documents Associative array of id => data.
     */
    public function batchPut(array $documents): void
    {
        $toWrite = [];

        foreach ($documents as $id => $data) {
            $toWrite[(string) $id] = $this->runWritePipeline((string) $id, $data, isNew: false);
        }

        $this->storage->batchWrite($this->collection, $toWrite);

        foreach ($toWrite as $id => $data) {
            $this->cache[$id] = $data;
        }

        $this->log('debug', 'batch upserted ' . count($toWrite) . ' documents', ['collection' => $this->collection]);

        foreach ($toWrite as $id => $data) {
            foreach ($this->afterWriteHooks as $hook) {
                $hook($id, $data, false);
            }
        }
    }

    /**
     * Delete a document.
     *
     * @throws DocumentNotFoundException when the document does not exist
     */
    public function delete(string $id): void
    {
        if (!$this->storage->exists($this->collection, $id)) {
            throw new DocumentNotFoundException(
                "Document '{$id}' not found in collection '{$this->collection}'."
            );
        }

        foreach ($this->beforeDeleteHooks as $hook) {
            $hook($id);
        }

        $this->storage->delete($this->collection, $id);
        unset($this->cache[$id]);
        $this->log('debug', "deleted '{$id}'", ['collection' => $this->collection]);

        foreach ($this->afterDeleteHooks as $hook) {
            $hook($id);
        }
    }

    /**
     * Query documents matching all supplied criteria with optional pagination.
     *
     * For more expressive queries, use the fluent builder: $db->where(...)->get()
     *
     * @param  array $criteria  Field/value pairs every matching document must satisfy.
     * @param  int   $limit     Maximum results to return (0 = no limit).
     * @param  int   $offset    Number of matching results to skip.
     */
    public function query(array $criteria, int $limit = 0, int $offset = 0): array
    {
        $qb = $this->newQuery();

        foreach ($criteria as $field => $value) {
            $qb->where((string) $field, $value);
        }

        if ($offset > 0) {
            $qb->offset($offset);
        }

        if ($limit > 0) {
            $qb->limit($limit);
        }

        return $qb->get();
    }

    /**
     * Return the last-modified Unix timestamp for a document, or null if it does not exist.
     */
    public function timestamp(string $id): int|null
    {
        return $this->storage->timestamp($this->collection, $id);
    }

    /**
     * Check whether a document with the given ID exists.
     * Checks the in-process cache before hitting storage.
     */
    public function exists(string $id): bool
    {
        if (array_key_exists($id, $this->cache)) {
            return true;
        }

        return $this->storage->exists($this->collection, $id);
    }

    /**
     * Return the name of the current collection.
     */
    public function getCollection(): string
    {
        return $this->collection;
    }

    /**
     * Clear the in-process document cache.
     * Useful when external processes may have modified the collection.
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Apply timestamps and run all beforeWrite hooks, returning the (possibly modified) data.
     */
    private function runWritePipeline(string $id, array $data, bool $isNew): array
    {
        if ($this->timestamps) {
            $now = time();
            if ($isNew) {
                $data['_created_at'] ??= $now;
                $data['_updated_at'] ??= $now;   // preserve caller-supplied value on create
            } else {
                $data['_updated_at'] = $now;      // always refresh on update
            }
        }

        foreach ($this->beforeWriteHooks as $hook) {
            $result = $hook($id, $data, $isNew);

            if (!is_array($result)) {
                throw new \LogicException('A beforeWrite hook must return an array; got ' . get_debug_type($result) . '.');
            }

            $data = $result;
        }

        return $data;
    }

    private function generateId(array $exclude = []): string
    {
        do {
            $id = bin2hex(random_bytes(8));
        } while ($this->storage->exists($this->collection, $id) || isset($exclude[$id]));

        return $id;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
