<?php

declare(strict_types=1);

namespace SimpleDB;

use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Exceptions\DocumentNotFoundException;

class SimpleDB
{
    /** @var array<string, array> In-process document cache; invalidated on write/delete. */
    private array $cache = [];

    private readonly ?\Closure $logger;

    /**
     * @param string        $collection  Collection name.
     * @param StorageInterface $storage  Storage adapter.
     * @param callable|null $logger      Optional log sink: fn(string $level, string $message, array $context): void
     */
    public function __construct(
        private readonly string $collection,
        private readonly StorageInterface $storage,
        callable|null $logger = null,
    ) {
        $this->logger = $logger !== null ? \Closure::fromCallable($logger) : null;
    }

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
     * Return the total number of documents in the collection.
     */
    public function count(): int
    {
        return $this->storage->count($this->collection);
    }

    /**
     * Create a new document and return its auto-generated ID.
     */
    public function post(array $data): string
    {
        $id = $this->generateId();
        $this->storage->write($this->collection, $id, $data);
        $this->cache[$id] = $data;
        $this->log('debug', "created '{$id}'", ['collection' => $this->collection]);

        return $id;
    }

    /**
     * Create multiple documents in one call.
     * Returns an array of the auto-generated IDs in the same order as $documents.
     *
     * @param  array<int, array> $documents List of document data arrays.
     * @return string[]                     List of generated IDs.
     */
    public function batchPost(array $documents): array
    {
        $toWrite = [];

        foreach ($documents as $data) {
            do {
                $id = bin2hex(random_bytes(8));
            } while ($this->storage->exists($this->collection, $id) || isset($toWrite[$id]));

            $toWrite[$id] = $data;
        }

        $this->storage->batchWrite($this->collection, $toWrite);

        foreach ($toWrite as $id => $data) {
            $this->cache[$id] = $data;
        }

        $this->log('debug', 'batch created ' . count($toWrite) . ' documents', ['collection' => $this->collection]);

        return array_keys($toWrite);
    }

    /**
     * Write (create or replace) a document with an explicit ID.
     */
    public function put(string $id, array $data): void
    {
        $this->storage->write($this->collection, $id, $data);
        $this->cache[$id] = $data;
        $this->log('debug', "upserted '{$id}'", ['collection' => $this->collection]);
    }

    /**
     * Write (create or replace) multiple documents with explicit IDs.
     *
     * @param array<string, array> $documents Associative array of id => data.
     */
    public function batchPut(array $documents): void
    {
        $this->storage->batchWrite($this->collection, $documents);

        foreach ($documents as $id => $data) {
            $this->cache[$id] = $data;
        }

        $this->log('debug', 'batch upserted ' . count($documents) . ' documents', ['collection' => $this->collection]);
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

        $this->storage->delete($this->collection, $id);
        unset($this->cache[$id]);
        $this->log('debug', "deleted '{$id}'", ['collection' => $this->collection]);
    }

    /**
     * Query documents matching all supplied criteria with optional pagination.
     *
     * Each key/value pair in $criteria must match the corresponding field in the document.
     * Returns an associative array of matching documents keyed by document ID.
     *
     * @param  array $criteria  Field/value pairs that every matching document must satisfy.
     * @param  int   $limit     Maximum number of results to return (0 = no limit).
     * @param  int   $offset    Number of matching results to skip before collecting.
     */
    public function query(array $criteria, int $limit = 0, int $offset = 0): array
    {
        $all     = $this->storage->readAll($this->collection);
        $output  = [];
        $skipped = 0;
        $found   = 0;

        foreach ($all as $id => $document) {
            $this->cache[$id] = $document;

            if (!$this->matchesCriteria($document, $criteria)) {
                continue;
            }

            if ($skipped < $offset) {
                $skipped++;
                continue;
            }

            $output[$id] = $document;
            $found++;

            if ($limit > 0 && $found >= $limit) {
                break;
            }
        }

        return $output;
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

    private function generateId(): string
    {
        do {
            $id = bin2hex(random_bytes(8));
        } while ($this->storage->exists($this->collection, $id));

        return $id;
    }

    /**
     * Test whether a document's top-level keys satisfy every criterion.
     */
    private function matchesCriteria(array $document, array $criteria): bool
    {
        foreach ($criteria as $key => $value) {
            if (!array_key_exists($key, $document)) {
                return false;
            }

            if ($document[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
