<?php

declare(strict_types=1);

namespace SimpleDB;

use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Exceptions\DocumentNotFoundException;

class SimpleDB
{
    public function __construct(
        private readonly string $collection,
        private readonly StorageInterface $storage,
    ) {}

    /**
     * Retrieve a single document by ID.
     * Returns null when the document does not exist.
     */
    public function get(string $id): array|null
    {
        return $this->storage->read($this->collection, $id);
    }

    /**
     * Retrieve all documents in the collection.
     * Returns an associative array keyed by document ID.
     */
    public function getAll(): array
    {
        return $this->storage->readAll($this->collection);
    }

    /**
     * Create a new document and return its auto-generated ID.
     */
    public function post(array $data): string
    {
        $id = $this->generateId();
        $this->storage->write($this->collection, $id, $data);

        return $id;
    }

    /**
     * Write (create or replace) a document with an explicit ID.
     */
    public function put(string $id, array $data): void
    {
        $this->storage->write($this->collection, $id, $data);
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
    }

    /**
     * Query documents matching all supplied criteria.
     * Each key/value pair in $criteria must match the corresponding field in the document.
     * Returns an associative array of matching documents keyed by document ID.
     */
    public function query(array $criteria): array
    {
        $all    = $this->storage->readAll($this->collection);
        $output = [];

        foreach ($all as $id => $document) {
            if ($this->matchesCriteria($document, $criteria)) {
                $output[$id] = $document;
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
     */
    public function exists(string $id): bool
    {
        return $this->storage->exists($this->collection, $id);
    }

    /**
     * Return the name of the current collection.
     */
    public function getCollection(): string
    {
        return $this->collection;
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
}
