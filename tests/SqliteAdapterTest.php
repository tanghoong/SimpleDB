<?php

declare(strict_types=1);

namespace SimpleDB\Tests;

use PHPUnit\Framework\TestCase;
use SimpleDB\Adapters\SqliteAdapter;
use SimpleDB\Exceptions\DocumentNotFoundException;
use SimpleDB\Exceptions\StorageException;
use SimpleDB\SimpleDB;

class SqliteAdapterTest extends TestCase
{
    private string $dbPath;
    private SqliteAdapter $adapter;
    private SimpleDB $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension is required for SqliteAdapter tests.');
        }

        $this->dbPath  = sys_get_temp_dir() . '/simpledb_test_' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->adapter = new SqliteAdapter($this->dbPath);
        $this->db      = new SimpleDB('cars', $this->adapter);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    // -------------------------------------------------------------------------
    // In-memory convenience
    // -------------------------------------------------------------------------

    public function testInMemoryDatabase(): void
    {
        $adapter = new SqliteAdapter(':memory:');
        $db      = new SimpleDB('test', $adapter);

        $id  = $db->post(['x' => 1]);
        $doc = $db->get($id);

        $this->assertSame(['x' => 1], $doc);
    }

    // -------------------------------------------------------------------------
    // CRUD — mirrors FileAdapter behaviour
    // -------------------------------------------------------------------------

    public function testCreateAndRetrieve(): void
    {
        $data = ['make' => 'Honda', 'model' => 'Civic'];
        $id   = $this->db->post($data);

        $this->assertIsString($id);
        $this->assertSame($data, $this->db->get($id));
    }

    public function testGetReturnsNullForMissingDocument(): void
    {
        $this->assertNull($this->db->get('no-such-id'));
    }

    public function testUpdateDocument(): void
    {
        $id = $this->db->post(['color' => 'red']);
        $this->db->put($id, ['color' => 'blue']);

        $this->assertSame('blue', $this->db->get($id)['color']);
    }

    public function testDeleteDocument(): void
    {
        $id = $this->db->post(['x' => 1]);
        $this->db->delete($id);

        $this->assertNull($this->db->get($id));
    }

    public function testDeleteThrowsForMissingDocument(): void
    {
        $this->expectException(DocumentNotFoundException::class);
        $this->db->delete('ghost');
    }

    public function testGetAllReturnsAllDocuments(): void
    {
        $idA = $this->db->post(['make' => 'Honda']);
        $idB = $this->db->post(['make' => 'Toyota']);

        $all = $this->db->getAll();

        $this->assertArrayHasKey($idA, $all);
        $this->assertArrayHasKey($idB, $all);
        $this->assertCount(2, $all);
    }

    // -------------------------------------------------------------------------
    // count()
    // -------------------------------------------------------------------------

    public function testCountReturnsZeroForEmptyCollection(): void
    {
        $this->assertSame(0, $this->db->count());
    }

    public function testCountReturnsCorrectValue(): void
    {
        $this->db->post(['x' => 1]);
        $this->db->post(['x' => 2]);
        $this->db->post(['x' => 3]);

        $this->assertSame(3, $this->db->count());
    }

    // -------------------------------------------------------------------------
    // stream()
    // -------------------------------------------------------------------------

    public function testStreamYieldsAllDocuments(): void
    {
        $idA = $this->db->post(['make' => 'Honda']);
        $idB = $this->db->post(['make' => 'Toyota']);

        $yielded = [];
        foreach ($this->db->stream() as $id => $doc) {
            $yielded[$id] = $doc;
        }

        $this->assertArrayHasKey($idA, $yielded);
        $this->assertArrayHasKey($idB, $yielded);
    }

    // -------------------------------------------------------------------------
    // batchPost / batchPut
    // -------------------------------------------------------------------------

    public function testBatchPostCreatesAllDocuments(): void
    {
        $ids = $this->db->batchPost([['a' => 1], ['a' => 2], ['a' => 3]]);

        $this->assertCount(3, $ids);
        $this->assertSame(3, $this->db->count());
    }

    public function testBatchPutUsesTransaction(): void
    {
        // All documents must be present after the call
        $this->db->batchPut([
            'x1' => ['v' => 1],
            'x2' => ['v' => 2],
            'x3' => ['v' => 3],
        ]);

        $this->assertSame(3, $this->db->count());
        $this->assertSame(['v' => 1], $this->db->get('x1'));
        $this->assertSame(['v' => 3], $this->db->get('x3'));
    }

    // -------------------------------------------------------------------------
    // timestamp()
    // -------------------------------------------------------------------------

    public function testTimestampReturnsIntegerAfterCreation(): void
    {
        $before = time();
        $id     = $this->db->post(['x' => 1]);
        $after  = time();

        $ts = $this->db->timestamp($id);

        $this->assertIsInt($ts);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testTimestampReturnsNullForMissingDocument(): void
    {
        $this->assertNull($this->db->timestamp('ghost'));
    }

    // -------------------------------------------------------------------------
    // Security — ID sanitisation
    // -------------------------------------------------------------------------

    public function testPathTraversalIdThrowsStorageException(): void
    {
        $this->expectException(StorageException::class);
        $this->adapter->read('cars', '../../etc/passwd');
    }

    public function testInvalidCollectionNameThrows(): void
    {
        $this->expectException(StorageException::class);
        $this->adapter->read('../escape', 'id');
    }

    public function testInvalidIdCharactersThrow(): void
    {
        $this->expectException(StorageException::class);
        $this->adapter->write('cars', 'bad id!', ['x' => 1]);
    }

    // -------------------------------------------------------------------------
    // Document size limit
    // -------------------------------------------------------------------------

    public function testDocumentSizeLimitThrows(): void
    {
        $adapter = new SqliteAdapter(':memory:', maxDocumentSize: 50);
        $db      = new SimpleDB('t', $adapter);

        $this->expectException(StorageException::class);
        $db->post(['data' => str_repeat('x', 100)]);
    }

    // -------------------------------------------------------------------------
    // Fluent QueryBuilder works with SqliteAdapter
    // -------------------------------------------------------------------------

    public function testQueryBuilderWithSqliteAdapter(): void
    {
        $this->db->batchPut([
            'p1' => ['type' => 'sedan', 'price' => 25000],
            'p2' => ['type' => 'suv',   'price' => 40000],
            'p3' => ['type' => 'sedan', 'price' => 30000],
        ]);

        $results = $this->db->where('type', 'sedan')
                            ->where('price', '<', 28000)
                            ->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('p1', $results);
    }

    public function testQueryBuilderCountWithSqliteAdapter(): void
    {
        $this->db->batchPut([
            'a' => ['status' => 'active'],
            'b' => ['status' => 'inactive'],
            'c' => ['status' => 'active'],
        ]);

        $this->assertSame(2, $this->db->where('status', 'active')->count());
    }

    // -------------------------------------------------------------------------
    // Auto-timestamps work with SqliteAdapter
    // -------------------------------------------------------------------------

    public function testAutoTimestampsWithSqliteAdapter(): void
    {
        $adapter = new SqliteAdapter(':memory:');
        $db      = new SimpleDB('ts', $adapter, timestamps: true);

        $id  = $db->post(['name' => 'Test']);
        $doc = $db->get($id);

        $this->assertArrayHasKey('_created_at', $doc);
        $this->assertArrayHasKey('_updated_at', $doc);
    }

    // -------------------------------------------------------------------------
    // Multiple collections in one database file
    // -------------------------------------------------------------------------

    public function testMultipleCollectionsInOneDatabase(): void
    {
        $cars   = new SimpleDB('cars',   $this->adapter);
        $trucks = new SimpleDB('trucks', $this->adapter);

        $cars->put('c1',   ['make' => 'Honda']);
        $trucks->put('t1', ['make' => 'Ford']);

        $this->assertSame(1, $cars->count());
        $this->assertSame(1, $trucks->count());
        $this->assertNull($cars->get('t1'));
        $this->assertNull($trucks->get('c1'));
    }

    // -------------------------------------------------------------------------
    // exists() and ArrayAccess
    // -------------------------------------------------------------------------

    public function testExistsAndArrayAccess(): void
    {
        $this->db['my-car'] = ['make' => 'Tesla'];

        $this->assertTrue(isset($this->db['my-car']));
        $this->assertSame(['make' => 'Tesla'], $this->db['my-car']);

        unset($this->db['my-car']);

        $this->assertFalse(isset($this->db['my-car']));
    }
}
