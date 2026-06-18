<?php

declare(strict_types=1);

namespace SimpleDB\Contracts;

/**
 * Marks a StorageAdapter as capable of executing QueryBuilder predicates natively
 * (e.g. via SQL) rather than streaming and filtering in PHP.
 *
 * QueryBuilder detects this interface (peeking through any DecoratorInterface
 * layers) and delegates its terminal methods here when available.
 */
interface NativeQueryInterface
{
    /**
     * Execute a full query and return matching documents keyed by ID.
     *
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     * @param list<array{field: string, direction: string}>               $orders
     * @return array<string, array>
     */
    public function executeNativeQuery(
        string $collection,
        array $conditions,
        array $orders,
        int $limit,
        int $offset,
    ): array;

    /**
     * Return the first matching document or null.
     *
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     * @param list<array{field: string, direction: string}>               $orders
     */
    public function executeNativeFirst(
        string $collection,
        array $conditions,
        array $orders,
    ): array|null;

    /**
     * Count matching documents without fetching them.
     *
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     */
    public function executeNativeCount(string $collection, array $conditions): int;

    /**
     * Return true if at least one document matches.
     *
     * @param list<array{field: string, operator: string, value: mixed}> $conditions
     */
    public function executeNativeExists(string $collection, array $conditions): bool;
}
