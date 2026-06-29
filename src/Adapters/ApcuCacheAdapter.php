<?php

declare(strict_types=1);

namespace SimpleDB\Adapters;

use SimpleDB\Contracts\DecoratorInterface;
use SimpleDB\Contracts\StorageInterface;
use SimpleDB\Exceptions\StorageException;

/**
 * APCu shared-memory cache decorator for any StorageInterface.
 *
 * Wraps an existing adapter and transparently caches individual document reads
 * in APCu — PHP's shared memory store that is available to all workers in a
 * PHP-FPM pool simultaneously.  A cache hit for a document costs a single
 * in-process memory lookup; no filesystem I/O at all.
 *
 * How it works:
 *
 *  read()    → APCu → (miss) → inner adapter → store result in APCu
 *  write()   → inner adapter → update APCu entry + invalidate ID-list cache
 *  delete()  → inner adapter → remove APCu entry + invalidate ID-list cache
 *  readAll() → listIds() from APCu → read() each doc via APCu
 *  listIds() → APCu → (miss) → inner adapter → store in APCu
 *  stream()  → delegates to inner (Generators cannot be cached); warms APCu
 *  count()   → delegates to inner (accurate count required)
 *
 * Cache invalidation:
 *   - Individual document entries are invalidated immediately on write/delete.
 *   - The ID-list cache for a collection is invalidated on every write/delete so
 *     that readAll() / getAll() always returns a fresh set of IDs.
 *   - For multi-process invalidation the TTL should be set to a value your
 *     application can tolerate as a staleness window (e.g. 60 seconds).
 *
 * Stacking with NativeQueryInterface adapters:
 *
 *   This adapter implements DecoratorInterface so that QueryBuilder can peek
 *   through it and find a NativeQueryInterface (e.g. SqliteAdapter) underneath,
 *   enabling SQL push-down queries even when APCu caching is active.
 *
 *   $sqlite  = new SqliteAdapter('/path/to/store.sqlite');
 *   $cached  = new ApcuCacheAdapter($sqlite, ttl: 30);
 *   $db      = new SimpleDB('sessions', $cached);
 *   // QueryBuilder automatically uses SQLite native queries for ->where()->get()
 *
 * Requirements:
 *   - The APCu PHP extension must be installed and enabled.
 *   - Works in CLI only when apc.enable_cli = 1 in php.ini.
 */
class ApcuCacheAdapter implements StorageInterface, DecoratorInterface
{
    /**
     * Sentinel stored in APCu to represent "document does not exist",
     * distinguishing a cache hit for a missing doc from a cache miss.
     */
    private const TOMBSTONE = "\0__simpledb_null__\0";

    public function __construct(
        private readonly StorageInterface $inner,
        private readonly int $ttl = 0,
        private readonly string $keyPrefix = 'simpledb:',
    ) {
        if (!extension_loaded('apcu')) {
            throw new StorageException(
                'ApcuCacheAdapter requires the APCu PHP extension (pecl install apcu).'
            );
        }

        if (!apcu_enabled()) {
            throw new StorageException(
                'APCu is installed but not enabled. Set apc.enabled=1 (and apc.enable_cli=1 for CLI) in php.ini.'
            );
        }
    }

    // -------------------------------------------------------------------------
    // DecoratorInterface
    // -------------------------------------------------------------------------

    public function getInnerAdapter(): StorageInterface
    {
        return $this->inner;
    }

    // -------------------------------------------------------------------------
    // StorageInterface
    // -------------------------------------------------------------------------

    public function read(string $collection, string $id): array|null
    {
        $key   = $this->docKey($collection, $id);
        $value = apcu_fetch($key, $found);

        if ($found) {
            return $value === self::TOMBSTONE ? null : $value;
        }

        $data = $this->inner->read($collection, $id);

        // Cache both hits (array) and misses (TOMBSTONE) to avoid dog-piling.
        apcu_store($key, $data ?? self::TOMBSTONE, $this->ttl);

        return $data;
    }

    public function readAll(string $collection): array
    {
        // Scan the ID list from the underlying store (one syscall / one DB query),
        // then serve each document through the per-document APCu cache.
        $ids    = $this->listIds($collection);
        $output = [];

        foreach ($ids as $id) {
            $doc = $this->read($collection, $id);
            if ($doc !== null) {
                $output[$id] = $doc;
            }
        }

        return $output;
    }

    /** @return \Generator<string, array> */
    public function stream(string $collection): \Generator
    {
        // Generators cannot be stored in APCu; stream through the inner adapter.
        // Each document is still warmed into the APCu cache as it passes through.
        foreach ($this->inner->stream($collection) as $id => $doc) {
            apcu_store($this->docKey($collection, $id), $doc, $this->ttl);
            yield $id => $doc;
        }
    }

    public function write(string $collection, string $id, array $data): void
    {
        $this->inner->write($collection, $id, $data);
        // Keep APCu consistent: update immediately after the inner write succeeds.
        apcu_store($this->docKey($collection, $id), $data, $this->ttl);
        // Invalidate the cached ID list so the next listIds()/readAll() is fresh.
        apcu_delete($this->idsKey($collection));
    }

    /** @param array<string, array> $documents */
    public function batchWrite(string $collection, array $documents): void
    {
        $this->inner->batchWrite($collection, $documents);

        foreach ($documents as $id => $data) {
            apcu_store($this->docKey($collection, (string) $id), $data, $this->ttl);
        }

        apcu_delete($this->idsKey($collection));
    }

    public function delete(string $collection, string $id): void
    {
        $this->inner->delete($collection, $id);
        apcu_delete($this->docKey($collection, $id));
        apcu_delete($this->idsKey($collection));
    }

    public function exists(string $collection, string $id): bool
    {
        $key   = $this->docKey($collection, $id);
        $value = apcu_fetch($key, $found);

        if ($found) {
            return $value !== self::TOMBSTONE;
        }

        // Delegate to read() so APCu is warmed for both hits (document stored)
        // and misses (tombstone stored), saving a second round-trip on read().
        return $this->read($collection, $id) !== null;
    }

    public function listIds(string $collection): array
    {
        $idsKey = $this->idsKey($collection);
        $ids    = apcu_fetch($idsKey, $found);

        if ($found) {
            return $ids;
        }

        $ids = $this->inner->listIds($collection);
        apcu_store($idsKey, $ids, $this->ttl);

        return $ids;
    }

    public function count(string $collection): int
    {
        return $this->inner->count($collection);
    }

    public function timestamp(string $collection, string $id): int|null
    {
        return $this->inner->timestamp($collection, $id);
    }

    // -------------------------------------------------------------------------
    // Cache management
    // -------------------------------------------------------------------------

    /**
     * Evict a single document from the APCu cache.
     */
    public function evict(string $collection, string $id): void
    {
        apcu_delete($this->docKey($collection, $id));
    }

    /**
     * Evict all cached entries for an entire collection (documents + ID list).
     * Requires APCUIterator (available when APCu >= 5.1.0).
     */
    public function evictCollection(string $collection): void
    {
        if (!class_exists(\APCUIterator::class)) {
            return;
        }

        $prefix = preg_quote($this->keyPrefix . $collection . ':', '/');
        apcu_delete(new \APCUIterator('/^' . $prefix . '/'));
    }

    /**
     * Flush the entire APCu cache.
     * Use with care — this clears all keys, not just SimpleDB's.
     */
    public function flushAll(): void
    {
        apcu_clear_cache();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function docKey(string $collection, string $id): string
    {
        return $this->keyPrefix . $collection . ':' . $id;
    }

    private function idsKey(string $collection): string
    {
        return $this->keyPrefix . $collection . ':__ids__';
    }
}
