<?php

declare(strict_types=1);

namespace SimpleDB\Tests;

use PHPUnit\Framework\TestCase;
use SimpleDB\Adapters\SqliteAdapter;
use SimpleDB\Contracts\DecoratorInterface;
use SimpleDB\Contracts\NativeQueryInterface;
use SimpleDB\SimpleDB;

/**
 * Tests that verify SQL push-down via NativeQueryInterface on SqliteAdapter.
 * Also verifies that QueryBuilder auto-detects the native adapter.
 */
class NativeQueryTest extends TestCase
{
    private SqliteAdapter $adapter;
    private SimpleDB $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required.');
        }

        $this->adapter = new SqliteAdapter(':memory:');
        $this->db      = new SimpleDB('products', $this->adapter);

        $this->db->batchPut([
            'p1' => ['name' => 'Apple',  'type' => 'fruit',   'price' => 1.20, 'stock' => 100, 'active' => true],
            'p2' => ['name' => 'Banana', 'type' => 'fruit',   'price' => 0.50, 'stock' => 200, 'active' => true],
            'p3' => ['name' => 'Carrot', 'type' => 'veggie',  'price' => 0.80, 'stock' => 150, 'active' => false],
            'p4' => ['name' => 'Durian', 'type' => 'fruit',   'price' => 15.0, 'stock' => 10,  'active' => true],
            'p5' => ['name' => 'Eggplant', 'type' => 'veggie', 'price' => 2.00, 'stock' => 50, 'active' => true],
        ]);
    }

    // -------------------------------------------------------------------------
    // Interface detection
    // -------------------------------------------------------------------------

    public function testSqliteAdapterImplementsNativeQueryInterface(): void
    {
        $this->assertInstanceOf(NativeQueryInterface::class, $this->adapter);
    }

    public function testQueryBuilderUsesNativeStorageForSqlite(): void
    {
        // Indirect proof: native path returns results identical to PHP-streaming path.
        $native  = $this->db->where('type', 'fruit')->get();
        $phpPath = $this->adapter->readAll('products');
        $filtered = array_filter($phpPath, fn($d) => $d['type'] === 'fruit');

        $this->assertEqualsCanonicalizing(array_keys($filtered), array_keys($native));
    }

    // -------------------------------------------------------------------------
    // Equality / inequality operators
    // -------------------------------------------------------------------------

    public function testNativeEquality(): void
    {
        $results = $this->db->where('type', 'veggie')->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p3', $results);
        $this->assertArrayHasKey('p5', $results);
    }

    public function testNativeInequality(): void
    {
        $results = $this->db->where('type', '!=', 'fruit')->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p3', $results);
        $this->assertArrayHasKey('p5', $results);
    }

    // -------------------------------------------------------------------------
    // Numeric comparisons (validates PARAM_INT / CAST(? AS REAL) binding)
    // -------------------------------------------------------------------------

    public function testNativeLessThanInteger(): void
    {
        $results = $this->db->where('stock', '<', 100)->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p4', $results);
        $this->assertArrayHasKey('p5', $results);
    }

    public function testNativeGreaterThanOrEqualInteger(): void
    {
        $results = $this->db->where('stock', '>=', 150)->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p2', $results);
        $this->assertArrayHasKey('p3', $results);
    }

    public function testNativeLessThanFloat(): void
    {
        $results = $this->db->where('price', '<', 1.00)->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p2', $results);
        $this->assertArrayHasKey('p3', $results);
    }

    public function testNativeGreaterThanFloat(): void
    {
        $results = $this->db->where('price', '>', 2.00)->get();

        // p4 price=15.0 is the only one strictly > 2.00
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('p4', $results);
    }

    // -------------------------------------------------------------------------
    // Combined conditions
    // -------------------------------------------------------------------------

    public function testNativeMultipleConditions(): void
    {
        $results = $this->db->where('type', 'fruit')
                            ->where('price', '<', 2.00)
                            ->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p1', $results);
        $this->assertArrayHasKey('p2', $results);
    }

    // -------------------------------------------------------------------------
    // IN / NOT IN
    // -------------------------------------------------------------------------

    public function testNativeIn(): void
    {
        $results = $this->db->where('name', 'in', ['Apple', 'Durian'])->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p1', $results);
        $this->assertArrayHasKey('p4', $results);
    }

    public function testNativeInEmptyArrayReturnsNothing(): void
    {
        $results = $this->db->where('name', 'in', [])->get();
        $this->assertCount(0, $results);
    }

    public function testNativeNotIn(): void
    {
        $results = $this->db->where('type', 'not_in', ['fruit'])->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p3', $results);
        $this->assertArrayHasKey('p5', $results);
    }

    public function testNativeNotInEmptyArrayReturnsAll(): void
    {
        $results = $this->db->where('type', 'not_in', [])->get();
        $this->assertCount(5, $results);
    }

    // -------------------------------------------------------------------------
    // String operators
    // -------------------------------------------------------------------------

    public function testNativeContains(): void
    {
        $results = $this->db->where('name', 'contains', 'an')->get();

        // Banana, Durian, Eggplant all contain 'an'
        $this->assertCount(3, $results);
        $this->assertArrayHasKey('p2', $results);
        $this->assertArrayHasKey('p4', $results);
        $this->assertArrayHasKey('p5', $results);
    }

    public function testNativeContainsCaseSensitive(): void
    {
        $results = $this->db->where('name', 'contains', 'apple')->get();
        $this->assertCount(0, $results); // 'apple' ≠ 'Apple'
    }

    public function testNativeStartsWith(): void
    {
        $results = $this->db->where('type', 'starts_with', 'fru')->get();
        $this->assertCount(3, $results);
    }

    public function testNativeEndsWith(): void
    {
        $results = $this->db->where('name', 'ends_with', 'ant')->get();
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('p5', $results);
    }

    // -------------------------------------------------------------------------
    // Null / not_null
    // -------------------------------------------------------------------------

    public function testNativeNull(): void
    {
        $this->db->put('p6', ['name' => 'Fig']); // no 'type' field

        $results = $this->db->newQuery()->whereNull('type')->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('p6', $results);
    }

    public function testNativeNotNull(): void
    {
        $this->db->put('p6', ['name' => 'Fig']); // no 'type' field

        $results = $this->db->newQuery()->whereNotNull('type')->get();

        $this->assertCount(5, $results);
        $this->assertArrayNotHasKey('p6', $results);
    }

    // -------------------------------------------------------------------------
    // ORDER BY
    // -------------------------------------------------------------------------

    public function testNativeOrderByAscending(): void
    {
        $results = $this->db->where('type', 'fruit')
                            ->orderBy('name')
                            ->get();

        $names = array_column(array_values($results), 'name');
        $this->assertSame(['Apple', 'Banana', 'Durian'], $names);
    }

    public function testNativeOrderByDescending(): void
    {
        $results = $this->db->where('type', 'fruit')
                            ->orderBy('name', 'desc')
                            ->get();

        $names = array_column(array_values($results), 'name');
        $this->assertSame(['Durian', 'Banana', 'Apple'], $names);
    }

    // -------------------------------------------------------------------------
    // LIMIT / OFFSET
    // -------------------------------------------------------------------------

    public function testNativeLimit(): void
    {
        $results = $this->db->where('type', 'fruit')
                            ->orderBy('name')
                            ->limit(2)
                            ->get();

        $this->assertCount(2, $results);
        $names = array_column(array_values($results), 'name');
        $this->assertSame(['Apple', 'Banana'], $names);
    }

    public function testNativeOffset(): void
    {
        $results = $this->db->where('type', 'fruit')
                            ->orderBy('name')
                            ->offset(1)
                            ->limit(2)
                            ->get();

        $this->assertCount(2, $results);
        $names = array_column(array_values($results), 'name');
        $this->assertSame(['Banana', 'Durian'], $names);
    }

    // -------------------------------------------------------------------------
    // Terminal methods
    // -------------------------------------------------------------------------

    public function testNativeFirst(): void
    {
        $doc = $this->db->where('type', 'veggie')
                        ->orderBy('name')
                        ->first();

        $this->assertNotNull($doc);
        $this->assertSame('Carrot', $doc['name']);
    }

    public function testNativeFirstReturnsNullWhenNoMatch(): void
    {
        $doc = $this->db->where('type', 'grain')->first();
        $this->assertNull($doc);
    }

    public function testNativeCount(): void
    {
        $count = $this->db->where('active', '=', true)->count();
        $this->assertSame(4, $count);
    }

    public function testNativeCountNoConditions(): void
    {
        $count = $this->db->newQuery()->count();
        $this->assertSame(5, $count);
    }

    public function testNativeExists(): void
    {
        $this->assertTrue($this->db->where('name', 'Durian')->exists());
        $this->assertFalse($this->db->where('name', 'Jackfruit')->exists());
    }

    // -------------------------------------------------------------------------
    // Dot-notation nested fields
    // -------------------------------------------------------------------------

    public function testNativeNestedFieldEquality(): void
    {
        $this->db->put('n1', ['meta' => ['category' => 'A'], 'val' => 1]);
        $this->db->put('n2', ['meta' => ['category' => 'B'], 'val' => 2]);
        $this->db->put('n3', ['meta' => ['category' => 'A'], 'val' => 3]);

        $results = $this->db->where('meta.category', 'A')->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('n1', $results);
        $this->assertArrayHasKey('n3', $results);
    }

    public function testNativeArrayIndexPath(): void
    {
        $this->db->put('a1', ['tags' => ['php', 'db', 'cache']]);
        $this->db->put('a2', ['tags' => ['go', 'db']]);

        $results = $this->db->where('tags.0', 'php')->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('a1', $results);
    }
}
