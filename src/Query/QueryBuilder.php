<?php

declare(strict_types=1);

namespace SimpleDB\Query;

use SimpleDB\Contracts\DecoratorInterface;
use SimpleDB\Contracts\NativeQueryInterface;
use SimpleDB\Contracts\StorageInterface;

/**
 * Fluent, lazy query builder for SimpleDB collections.
 *
 * Build constraints with ->where() / ->whereIn() etc., then call a terminal
 * method (get / first / count / exists) to execute.  Documents are streamed
 * one at a time so the whole collection is never loaded into memory unless
 * orderBy() is used (which requires a full pass for sorting).
 *
 * All constraint methods return $this so they are chainable.
 *
 * Native query push-down:
 *   When the underlying StorageAdapter implements NativeQueryInterface (e.g.
 *   SqliteAdapter), query conditions are pushed down to SQL — no PHP-level
 *   streaming occurs.  DecoratorInterface layers (e.g. ApcuCacheAdapter) are
 *   peeled back automatically so the native adapter is always found.
 */
final class QueryBuilder
{
    /** @var list<array{field: string, operator: string, value: mixed}> */
    private array $conditions = [];

    /** @var list<array{field: string, direction: string}> */
    private array $orders = [];

    private int $limitVal  = 0;
    private int $offsetVal = 0;

    /** Sentinel returned by resolveField() when a path does not exist in the document. */
    private readonly object $missing;

    /** Non-null when the storage (or a decorated inner adapter) supports native queries. */
    private readonly NativeQueryInterface|null $native;

    public function __construct(
        private readonly string $collection,
        private readonly StorageInterface $storage,
    ) {
        $this->missing = new \stdClass();
        $this->native  = $this->resolveNativeStorage($storage);
    }

    // -------------------------------------------------------------------------
    // Constraint methods
    // -------------------------------------------------------------------------

    /**
     * Add an equality or comparison condition.
     *
     * Two-argument form (equality):  ->where('make', 'Honda')
     * Three-argument form:           ->where('year', '>', 2020)
     *
     * Supported operators: =  !=  >  >=  <  <=  in  not_in
     *                       contains  starts_with  ends_with  null  not_null
     *
     * Dot-notation is supported for nested fields: ->where('address.city', 'Paris')
     */
    public function where(string $field, mixed $operatorOrValue, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $this->conditions[] = ['field' => $field, 'operator' => '=', 'value' => $operatorOrValue];
        } else {
            $op = strtolower((string) $operatorOrValue);
            $this->validateOperator($op);

            if (in_array($op, ['in', 'not_in'], true) && !is_array($value)) {
                throw new \InvalidArgumentException(
                    "Operator '{$op}' requires an array value; " . get_debug_type($value) . ' given.'
                );
            }

            $this->conditions[] = ['field' => $field, 'operator' => $op, 'value' => $value];
        }

        return $this;
    }

    /** @param list<mixed> $values */
    public function whereIn(string $field, array $values): static
    {
        $this->conditions[] = ['field' => $field, 'operator' => 'in', 'value' => $values];
        return $this;
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $field, array $values): static
    {
        $this->conditions[] = ['field' => $field, 'operator' => 'not_in', 'value' => $values];
        return $this;
    }

    public function whereNull(string $field): static
    {
        $this->conditions[] = ['field' => $field, 'operator' => 'null', 'value' => null];
        return $this;
    }

    public function whereNotNull(string $field): static
    {
        $this->conditions[] = ['field' => $field, 'operator' => 'not_null', 'value' => null];
        return $this;
    }

    /**
     * Add an ordering clause.  Multiple calls are applied in registration order (primary sort first).
     *
     * @throws \InvalidArgumentException for invalid direction
     */
    public function orderBy(string $field, string $direction = 'asc'): static
    {
        $dir = strtolower($direction);

        if (!in_array($dir, ['asc', 'desc'], true)) {
            throw new \InvalidArgumentException("orderBy direction must be 'asc' or 'desc'; '{$direction}' given.");
        }

        $this->orders[] = ['field' => $field, 'direction' => $dir];
        return $this;
    }

    public function limit(int $n): static
    {
        $this->limitVal = max(0, $n);
        return $this;
    }

    public function offset(int $n): static
    {
        $this->offsetVal = max(0, $n);
        return $this;
    }

    // -------------------------------------------------------------------------
    // Terminal methods
    // -------------------------------------------------------------------------

    /**
     * Execute the query and return all matching documents keyed by ID.
     *
     * When orderBy() is used, all matching documents must be collected before
     * sorting, then limit/offset are applied to the sorted result.
     * Without ordering, streaming stops as soon as enough results are found.
     *
     * @return array<string, array>
     */
    public function get(): array
    {
        if ($this->native !== null) {
            return $this->native->executeNativeQuery(
                $this->collection,
                $this->conditions,
                $this->orders,
                $this->limitVal,
                $this->offsetVal,
            );
        }

        if (!empty($this->orders)) {
            return $this->getWithOrdering();
        }

        return $this->getStreaming();
    }

    /**
     * Return the first matching document, or null if none match.
     */
    public function first(): array|null
    {
        if ($this->native !== null) {
            return $this->native->executeNativeFirst(
                $this->collection,
                $this->conditions,
                $this->orders,
            );
        }

        if (!empty($this->orders)) {
            $results = $this->getWithOrdering();
            return !empty($results) ? reset($results) : null;
        }

        $skipped = 0;

        foreach ($this->storage->stream($this->collection) as $doc) {
            if (!$this->matchesAll($doc)) {
                continue;
            }

            if ($skipped < $this->offsetVal) {
                $skipped++;
                continue;
            }

            return $doc;
        }

        return null;
    }

    /**
     * Count matching documents without building a results array.
     */
    public function count(): int
    {
        if ($this->native !== null) {
            return $this->native->executeNativeCount(
                $this->collection,
                $this->conditions,
            );
        }

        $n = 0;

        foreach ($this->storage->stream($this->collection) as $doc) {
            if ($this->matchesAll($doc)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Return true if at least one document matches all conditions.
     */
    public function exists(): bool
    {
        if ($this->native !== null) {
            return $this->native->executeNativeExists(
                $this->collection,
                $this->conditions,
            );
        }

        foreach ($this->storage->stream($this->collection) as $doc) {
            if ($this->matchesAll($doc)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Private execution helpers
    // -------------------------------------------------------------------------

    /** @return array<string, array> */
    private function getStreaming(): array
    {
        $results = [];
        $skipped = 0;
        $found   = 0;

        foreach ($this->storage->stream($this->collection) as $id => $doc) {
            if (!$this->matchesAll($doc)) {
                continue;
            }

            if ($skipped < $this->offsetVal) {
                $skipped++;
                continue;
            }

            $results[$id] = $doc;
            $found++;

            if ($this->limitVal > 0 && $found >= $this->limitVal) {
                break;
            }
        }

        return $results;
    }

    /** @return array<string, array> */
    private function getWithOrdering(): array
    {
        $results = [];

        foreach ($this->storage->stream($this->collection) as $id => $doc) {
            if ($this->matchesAll($doc)) {
                $results[$id] = $doc;
            }
        }

        uasort($results, function (array $a, array $b): int {
            foreach ($this->orders as ['field' => $field, 'direction' => $dir]) {
                $av  = $this->resolveField($a, $field);
                $bv  = $this->resolveField($b, $field);
                $av  = $av === $this->missing ? null : $av;
                $bv  = $bv === $this->missing ? null : $bv;
                $cmp = $av <=> $bv;

                if ($cmp !== 0) {
                    return $dir === 'desc' ? -$cmp : $cmp;
                }
            }

            return 0;
        });

        if ($this->offsetVal > 0) {
            $results = array_slice($results, $this->offsetVal, preserve_keys: true);
        }

        if ($this->limitVal > 0) {
            $results = array_slice($results, 0, $this->limitVal, preserve_keys: true);
        }

        return $results;
    }

    private function matchesAll(array $doc): bool
    {
        foreach ($this->conditions as ['field' => $field, 'operator' => $op, 'value' => $expected]) {
            $raw         = $this->resolveField($doc, $field);
            $fieldExists = $raw !== $this->missing;
            $actual      = $fieldExists ? $raw : null;

            if (!$this->evaluate($actual, $op, $expected, $fieldExists)) {
                return false;
            }
        }

        return true;
    }

    private function evaluate(mixed $actual, string $op, mixed $expected, bool $fieldExists): bool
    {
        return match ($op) {
            '='           => $actual === $expected,
            '!='          => $actual !== $expected,
            '>'           => $fieldExists && $actual > $expected,
            '>='          => $fieldExists && $actual >= $expected,
            '<'           => $fieldExists && $actual < $expected,
            '<='          => $fieldExists && $actual <= $expected,
            'in'          => in_array($actual, (array) $expected, strict: true),
            'not_in'      => !in_array($actual, (array) $expected, strict: true),
            'contains'    => is_string($actual) && str_contains($actual, (string) $expected),
            'starts_with' => is_string($actual) && str_starts_with($actual, (string) $expected),
            'ends_with'   => is_string($actual) && str_ends_with($actual, (string) $expected),
            'null'        => !$fieldExists || $actual === null,
            'not_null'    => $fieldExists && $actual !== null,
            default       => false,
        };
    }

    private function resolveField(array $doc, string $field): mixed
    {
        $keys    = explode('.', $field);
        $current = $doc;

        foreach ($keys as $key) {
            $index = is_numeric($key) ? (int) $key : $key;

            if (!is_array($current) || !array_key_exists($index, $current)) {
                return $this->missing;
            }

            $current = $current[$index];
        }

        return $current;
    }

    private function validateOperator(string $op): void
    {
        static $valid = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in',
                         'contains', 'starts_with', 'ends_with', 'null', 'not_null'];

        if (!in_array($op, $valid, true)) {
            throw new \InvalidArgumentException(
                "Unknown query operator '{$op}'. Supported: " . implode(', ', $valid)
            );
        }
    }

    /**
     * Walk through any DecoratorInterface layers to find a NativeQueryInterface.
     */
    private function resolveNativeStorage(StorageInterface $storage): NativeQueryInterface|null
    {
        $current = $storage;

        while (true) {
            if ($current instanceof NativeQueryInterface) {
                return $current;
            }

            if ($current instanceof DecoratorInterface) {
                $current = $current->getInnerAdapter();
            } else {
                return null;
            }
        }
    }
}
