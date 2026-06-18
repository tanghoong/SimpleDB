<?php

declare(strict_types=1);

namespace SimpleDB\Tests;

use PHPUnit\Framework\TestCase;
use SimpleDB\Adapters\FileAdapter;
use SimpleDB\Query\QueryBuilder;
use SimpleDB\SimpleDB;

class QueryBuilderTest extends TestCase
{
    private string $tmpDir;
    private SimpleDB $db;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/simpledb_qb_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, true);

        $adapter  = new FileAdapter($this->tmpDir);
        $this->db = new SimpleDB('products', $adapter);

        // Seed the collection
        $this->db->batchPut([
            'p1' => ['name' => 'Apple',  'category' => 'fruit',  'price' => 1.50, 'stock' => 100, 'tags' => ['fresh', 'organic']],
            'p2' => ['name' => 'Banana', 'category' => 'fruit',  'price' => 0.75, 'stock' => 200, 'tags' => ['fresh']],
            'p3' => ['name' => 'Carrot', 'category' => 'veggie', 'price' => 0.90, 'stock' => 50],
            'p4' => ['name' => 'Durian', 'category' => 'fruit',  'price' => 15.0, 'stock' => 10, 'tags' => ['exotic']],
            'p5' => ['name' => 'Eggplant', 'category' => 'veggie', 'price' => 2.20, 'stock' => 0, 'metadata' => ['origin' => ['country' => 'JP']]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // where() — equality (2-arg form)
    // -------------------------------------------------------------------------

    public function testWhereEquality(): void
    {
        $results = $this->db->where('category', 'fruit')->get();

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('p1', $results);
        $this->assertArrayHasKey('p2', $results);
        $this->assertArrayHasKey('p4', $results);
    }

    public function testWhereMultipleConditions(): void
    {
        $results = $this->db->where('category', 'fruit')->where('price', '<', 1.0)->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('p2', $results);
    }

    // -------------------------------------------------------------------------
    // where() — comparison operators
    // -------------------------------------------------------------------------

    public function testWhereGreaterThan(): void
    {
        $results = $this->db->where('price', '>', 1.0)->get();

        $this->assertCount(3, $results); // Apple 1.50, Durian 15.0, Eggplant 2.20
    }

    public function testWhereGreaterThanOrEqual(): void
    {
        $results = $this->db->where('price', '>=', 1.50)->get();

        $this->assertCount(3, $results); // Apple 1.50, Durian 15.0, Eggplant 2.20
    }

    public function testWhereLessThan(): void
    {
        $results = $this->db->where('price', '<', 1.0)->get();

        $this->assertCount(2, $results); // Banana 0.75, Carrot 0.90
    }

    public function testWhereLessThanOrEqual(): void
    {
        $results = $this->db->where('price', '<=', 0.90)->get();

        $this->assertCount(2, $results); // Banana, Carrot
    }

    public function testWhereNotEqual(): void
    {
        $results = $this->db->where('category', '!=', 'fruit')->get();

        $this->assertCount(2, $results); // Carrot, Eggplant
    }

    // -------------------------------------------------------------------------
    // whereIn / whereNotIn
    // -------------------------------------------------------------------------

    public function testWhereIn(): void
    {
        $results = $this->db->where('name', 'in', ['Apple', 'Carrot'])->get();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p1', $results);
        $this->assertArrayHasKey('p3', $results);
    }

    public function testWhereInFluent(): void
    {
        $results = $this->db->newQuery()->whereIn('name', ['Apple', 'Carrot'])->get();

        $this->assertCount(2, $results);
    }

    public function testWhereNotIn(): void
    {
        $results = $this->db->newQuery()->whereNotIn('category', ['fruit'])->get();

        $this->assertCount(2, $results); // Carrot, Eggplant
    }

    public function testWhereInEmptyArrayMatchesNothing(): void
    {
        $results = $this->db->where('name', 'in', [])->get();

        $this->assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // whereNull / whereNotNull
    // -------------------------------------------------------------------------

    public function testWhereNull(): void
    {
        $results = $this->db->newQuery()->whereNull('tags')->get();

        // Carrot (p3) and Eggplant (p5) have no 'tags' field
        $this->assertCount(2, $results);
        $this->assertArrayHasKey('p3', $results);
        $this->assertArrayHasKey('p5', $results);
    }

    public function testWhereNotNull(): void
    {
        $results = $this->db->newQuery()->whereNotNull('tags')->get();

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('p1', $results);
        $this->assertArrayHasKey('p2', $results);
        $this->assertArrayHasKey('p4', $results);
    }

    public function testWhereFluent(): void
    {
        $results = $this->db->newQuery()->whereNull('tags')->get();

        $this->assertCount(2, $results);
    }

    // -------------------------------------------------------------------------
    // String operators
    // -------------------------------------------------------------------------

    public function testWhereContains(): void
    {
        $results = $this->db->where('name', 'contains', 'an')->get();

        $this->assertCount(3, $results); // Banana, Durian, Eggplant
    }

    public function testWhereStartsWith(): void
    {
        $results = $this->db->where('name', 'starts_with', 'B')->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('p2', $results);
    }

    public function testWhereEndsWith(): void
    {
        $results = $this->db->where('name', 'ends_with', 't')->get();

        $this->assertCount(2, $results); // Carrot, Eggplant
    }

    // -------------------------------------------------------------------------
    // Dot-notation nested field access
    // -------------------------------------------------------------------------

    public function testDotNotationEquality(): void
    {
        $results = $this->db->where('metadata.origin.country', 'JP')->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('p5', $results);
    }

    public function testDotNotationMissingFieldTreatedAsNull(): void
    {
        $results = $this->db->newQuery()->whereNull('metadata.origin.country')->get();

        // Only p5 has this path; all others are "null" (missing)
        $this->assertCount(4, $results);
        $this->assertArrayNotHasKey('p5', $results);
    }

    // -------------------------------------------------------------------------
    // orderBy
    // -------------------------------------------------------------------------

    public function testOrderByAscending(): void
    {
        $results = $this->db->where('category', 'fruit')->orderBy('price')->get();

        $names = array_column(array_values($results), 'name');
        // Banana (0.75) → Apple (1.50) → Durian (15)
        $this->assertSame(['Banana', 'Apple', 'Durian'], $names);
    }

    public function testOrderByDescending(): void
    {
        $results = $this->db->where('category', 'fruit')->orderBy('price', 'desc')->get();

        $names = array_column(array_values($results), 'name');
        // Durian (15) → Apple (1.50) → Banana (0.75)
        $this->assertSame(['Durian', 'Apple', 'Banana'], $names);
    }

    public function testOrderByPreservesKeys(): void
    {
        $results = $this->db->where('category', 'fruit')->orderBy('price')->get();

        $keys = array_keys($results);
        $this->assertSame(['p2', 'p1', 'p4'], $keys);
    }

    public function testInvalidDirectionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->newQuery()->orderBy('price', 'sideways');
    }

    // -------------------------------------------------------------------------
    // limit / offset
    // -------------------------------------------------------------------------

    public function testLimit(): void
    {
        $results = $this->db->newQuery()->limit(2)->get();

        $this->assertCount(2, $results);
    }

    public function testOffset(): void
    {
        $all      = $this->db->newQuery()->orderBy('name')->get();
        $names    = array_column(array_values($all), 'name');

        $paged    = $this->db->newQuery()->orderBy('name')->offset(2)->get();
        $pagedNames = array_column(array_values($paged), 'name');

        $this->assertSame(array_slice($names, 2), $pagedNames);
    }

    public function testLimitAndOffset(): void
    {
        $results = $this->db->newQuery()->orderBy('name')->limit(2)->offset(1)->get();

        $this->assertCount(2, $results);
        $names = array_column(array_values($results), 'name');
        $this->assertSame(['Banana', 'Carrot'], $names);
    }

    // -------------------------------------------------------------------------
    // Terminal methods: first / count / exists
    // -------------------------------------------------------------------------

    public function testFirst(): void
    {
        $doc = $this->db->where('category', 'veggie')->orderBy('price')->first();

        $this->assertNotNull($doc);
        $this->assertSame('Carrot', $doc['name']);
    }

    public function testFirstReturnsNullWhenNoMatch(): void
    {
        $doc = $this->db->where('name', 'Zucchini')->first();

        $this->assertNull($doc);
    }

    public function testBuilderCount(): void
    {
        $n = $this->db->where('category', 'fruit')->count();

        $this->assertSame(3, $n);
    }

    public function testBuilderExists(): void
    {
        $this->assertTrue($this->db->where('name', 'Apple')->exists());
        $this->assertFalse($this->db->where('name', 'Zucchini')->exists());
    }

    // -------------------------------------------------------------------------
    // Invalid operator
    // -------------------------------------------------------------------------

    public function testUnknownOperatorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->where('price', 'between', [1, 5])->get();
    }

    public function testInOperatorRequiresArrayThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->where('name', 'in', 'Apple')->get();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }
}
