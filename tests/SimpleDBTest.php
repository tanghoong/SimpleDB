<?php

declare(strict_types=1);

namespace SimpleDB\Tests;

use PHPUnit\Framework\TestCase;
use SimpleDB\Adapters\FileAdapter;
use SimpleDB\Exceptions\DocumentNotFoundException;
use SimpleDB\Exceptions\StorageException;
use SimpleDB\SimpleDB;

class SimpleDBTest extends TestCase
{
    private string $tmpDir;
    private SimpleDB $db;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/simpledb_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, true);

        $adapter  = new FileAdapter($this->tmpDir);
        $this->db = new SimpleDB('cars', $adapter);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // CRUD tests
    // -------------------------------------------------------------------------

    public function testCreateAndRetrieveById(): void
    {
        $data = ['make' => 'Honda', 'model' => 'Civic', 'color' => 'blue'];
        $id   = $this->db->post($data);

        $this->assertIsString($id);
        $this->assertNotEmpty($id);

        $retrieved = $this->db->get($id);

        $this->assertSame($data, $retrieved);
    }

    public function testGetReturnsNullForNonExistentId(): void
    {
        $result = $this->db->get('nonexistent-id-abc123');

        $this->assertNull($result);
    }

    public function testUpdateDocument(): void
    {
        $id = $this->db->post(['make' => 'Toyota', 'color' => 'red']);

        $this->db->put($id, ['make' => 'Toyota', 'color' => 'green']);

        $updated = $this->db->get($id);

        $this->assertSame('green', $updated['color']);
    }

    public function testDeleteDocument(): void
    {
        $id = $this->db->post(['make' => 'Ford']);

        $this->db->delete($id);

        $this->assertNull($this->db->get($id));
    }

    public function testDeleteThrowsDocumentNotFoundForMissingId(): void
    {
        $this->expectException(DocumentNotFoundException::class);

        $this->db->delete('does-not-exist');
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

    public function testGetAllReturnsEmptyArrayWhenNoDocuments(): void
    {
        $this->assertSame([], $this->db->getAll());
    }

    // -------------------------------------------------------------------------
    // Query tests
    // -------------------------------------------------------------------------

    public function testQueryWithMatchingCriteria(): void
    {
        $this->db->post(['make' => 'Honda', 'color' => 'blue']);
        $this->db->post(['make' => 'Honda', 'color' => 'red']);
        $this->db->post(['make' => 'Toyota', 'color' => 'blue']);

        $results = $this->db->query(['make' => 'Honda', 'color' => 'blue']);

        $this->assertCount(1, $results);
        $doc = array_values($results)[0];
        $this->assertSame('Honda', $doc['make']);
        $this->assertSame('blue', $doc['color']);
    }

    public function testQueryWithNonMatchingCriteriaReturnsEmptyArray(): void
    {
        $this->db->post(['make' => 'Honda', 'color' => 'blue']);

        $results = $this->db->query(['make' => 'Bugatti']);

        $this->assertSame([], $results);
    }

    public function testQueryWithEmptyCriteriaReturnsAllDocuments(): void
    {
        $this->db->post(['make' => 'Honda']);
        $this->db->post(['make' => 'Toyota']);

        $results = $this->db->query([]);

        $this->assertCount(2, $results);
    }

    public function testQueryWithLimit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->db->post(['type' => 'car', 'index' => $i]);
        }

        $results = $this->db->query(['type' => 'car'], limit: 3);

        $this->assertCount(3, $results);
    }

    public function testQueryWithOffset(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->db->post(['type' => 'car', 'index' => $i]);
        }

        $results = $this->db->query(['type' => 'car'], offset: 2);

        $this->assertCount(3, $results);
    }

    public function testQueryWithLimitAndOffset(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->db->post(['type' => 'car', 'index' => $i]);
        }

        $results = $this->db->query(['type' => 'car'], limit: 3, offset: 4);

        $this->assertCount(3, $results);
    }

    public function testQueryOffsetBeyondResultsReturnsEmpty(): void
    {
        $this->db->post(['type' => 'car']);
        $this->db->post(['type' => 'car']);

        $results = $this->db->query(['type' => 'car'], offset: 100);

        $this->assertSame([], $results);
    }

    // -------------------------------------------------------------------------
    // count() tests
    // -------------------------------------------------------------------------

    public function testCountReturnsZeroForEmptyCollection(): void
    {
        $this->assertSame(0, $this->db->count());
    }

    public function testCountReturnsCorrectCount(): void
    {
        $this->db->post(['make' => 'Honda']);
        $this->db->post(['make' => 'Toyota']);
        $this->db->post(['make' => 'Ford']);

        $this->assertSame(3, $this->db->count());
    }

    public function testCountDecreasesAfterDelete(): void
    {
        $id = $this->db->post(['make' => 'Honda']);
        $this->db->post(['make' => 'Toyota']);

        $this->assertSame(2, $this->db->count());

        $this->db->delete($id);

        $this->assertSame(1, $this->db->count());
    }

    // -------------------------------------------------------------------------
    // stream() tests
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
        $this->assertCount(2, $yielded);
    }

    public function testStreamYieldsEmptyForEmptyCollection(): void
    {
        $count = 0;
        foreach ($this->db->stream() as $id => $doc) {
            $count++;
        }

        $this->assertSame(0, $count);
    }

    // -------------------------------------------------------------------------
    // batchPost() tests
    // -------------------------------------------------------------------------

    public function testBatchPostCreatesAllDocuments(): void
    {
        $ids = $this->db->batchPost([
            ['make' => 'Honda'],
            ['make' => 'Toyota'],
            ['make' => 'Ford'],
        ]);

        $this->assertCount(3, $ids);
        $this->assertSame(3, $this->db->count());

        foreach ($ids as $id) {
            $this->assertNotNull($this->db->get($id));
        }
    }

    public function testBatchPostReturnsUniqueIds(): void
    {
        $ids = $this->db->batchPost([
            ['x' => 1],
            ['x' => 2],
            ['x' => 3],
        ]);

        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    // -------------------------------------------------------------------------
    // batchPut() tests
    // -------------------------------------------------------------------------

    public function testBatchPutCreatesDocumentsWithExplicitIds(): void
    {
        $this->db->batchPut([
            'car-1' => ['make' => 'Honda'],
            'car-2' => ['make' => 'Toyota'],
        ]);

        $this->assertSame(['make' => 'Honda'], $this->db->get('car-1'));
        $this->assertSame(['make' => 'Toyota'], $this->db->get('car-2'));
        $this->assertSame(2, $this->db->count());
    }

    public function testBatchPutOverwritesExistingDocuments(): void
    {
        $this->db->put('car-1', ['make' => 'Honda', 'color' => 'red']);

        $this->db->batchPut([
            'car-1' => ['make' => 'Honda', 'color' => 'blue'],
        ]);

        $this->assertSame('blue', $this->db->get('car-1')['color']);
    }

    // -------------------------------------------------------------------------
    // Cache tests
    // -------------------------------------------------------------------------

    public function testGetHitsCacheAfterFirstRead(): void
    {
        $logs = [];
        $adapter = new FileAdapter($this->tmpDir);
        $db = new SimpleDB('cache_test', $adapter, function (string $level, string $msg) use (&$logs): void {
            $logs[] = $msg;
        });

        $id = $db->post(['x' => 1]);
        $db->get($id); // second call — should hit cache

        $this->assertNotEmpty(array_filter($logs, fn(string $m): bool => str_contains($m, 'cache hit')));
    }

    public function testCacheIsInvalidatedOnDelete(): void
    {
        $id = $this->db->post(['make' => 'Honda']);
        $this->db->get($id); // warms cache
        $this->db->delete($id);

        // After delete, get() must return null (not stale cache data)
        $this->assertNull($this->db->get($id));
    }

    public function testCacheIsUpdatedOnPut(): void
    {
        $id = $this->db->post(['make' => 'Honda', 'color' => 'red']);
        $this->db->get($id); // warms cache

        $this->db->put($id, ['make' => 'Honda', 'color' => 'green']);

        // get() should return updated value from cache, not old value
        $this->assertSame('green', $this->db->get($id)['color']);
    }

    public function testClearCacheAllowsFreshRead(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $db1     = new SimpleDB('shared', $adapter);
        $db2     = new SimpleDB('shared', $adapter);

        $id = $db1->post(['value' => 'original']);
        $db2->get($id); // warm db2's cache

        // Simulate external update via db1
        $db1->put($id, ['value' => 'updated']);

        // db2's cache is stale — clear it and re-read
        $db2->clearCache();

        $this->assertSame('updated', $db2->get($id)['value']);
    }

    // -------------------------------------------------------------------------
    // Logger tests
    // -------------------------------------------------------------------------

    public function testLoggerReceivesCallbackOnWrite(): void
    {
        $received = [];
        $adapter  = new FileAdapter($this->tmpDir);
        $db       = new SimpleDB(
            'log_test',
            $adapter,
            function (string $level, string $message, array $context) use (&$received): void {
                $received[] = ['level' => $level, 'message' => $message, 'context' => $context];
            }
        );

        $db->post(['key' => 'value']);

        $this->assertNotEmpty($received);
        $this->assertSame('debug', $received[0]['level']);
        $this->assertSame('log_test', $received[0]['context']['collection']);
    }

    // -------------------------------------------------------------------------
    // Timestamp tests
    // -------------------------------------------------------------------------

    public function testTimestampReturnsIntegerAfterCreation(): void
    {
        $before = time();
        $id     = $this->db->post(['make' => 'Mazda']);
        $after  = time();

        $ts = $this->db->timestamp($id);

        $this->assertIsInt($ts);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function testTimestampReturnsNullForNonExistentDocument(): void
    {
        $this->assertNull($this->db->timestamp('ghost'));
    }

    // -------------------------------------------------------------------------
    // Security / ID sanitisation tests
    // -------------------------------------------------------------------------

    public function testPathTraversalIdThrowsStorageException(): void
    {
        $this->expectException(StorageException::class);

        $adapter = new FileAdapter($this->tmpDir);
        $adapter->read('cars', '../../etc/passwd');
    }

    public function testCollectionNameWithSlashThrowsStorageException(): void
    {
        $this->expectException(StorageException::class);

        $adapter = new FileAdapter($this->tmpDir);
        $adapter->read('../escape', 'id');
    }

    public function testInvalidIdCharactersThrowStorageException(): void
    {
        $this->expectException(StorageException::class);

        $adapter = new FileAdapter($this->tmpDir);
        $adapter->write('cars', 'bad id!', ['x' => 1]);
    }

    public function testDocumentSizeLimitThrowsStorageException(): void
    {
        $adapter = new FileAdapter($this->tmpDir, maxDocumentSize: 100);
        $db      = new SimpleDB('size_test', $adapter);

        $this->expectException(StorageException::class);

        // A document that serialises to more than 100 bytes
        $db->post(['data' => str_repeat('x', 200)]);
    }

    public function testDocumentWithinSizeLimitIsAccepted(): void
    {
        $adapter = new FileAdapter($this->tmpDir, maxDocumentSize: 1024);
        $db      = new SimpleDB('size_test', $adapter);

        $id = $db->post(['name' => 'ok']);

        $this->assertNotNull($db->get($id));
    }

    public function testStorageDirectoryPermissionsAreRestrictive(): void
    {
        $newDir  = $this->tmpDir . '/perm_test';
        $adapter = new FileAdapter($newDir);
        $adapter->write('col', 'doc1', ['x' => 1]);

        $perms = fileperms($newDir) & 0777;

        // Directory should NOT be world-readable (mode 0750 or stricter)
        $this->assertSame(0, $perms & 0007, 'Storage directory must not be world-accessible.');
    }

    // -------------------------------------------------------------------------
    // Atomic write test
    // -------------------------------------------------------------------------

    public function testAtomicWriteDoesNotLeaveTemporaryFiles(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $adapter->write('cars', 'mycar', ['make' => 'Tesla']);

        $collectionDir = $this->tmpDir . '/cars';
        $entries       = array_filter(
            scandir($collectionDir),
            fn(string $e): bool => str_starts_with($e, '.tmp_')
        );

        $this->assertEmpty($entries, 'Temporary files should be cleaned up after atomic write.');

        $data = $adapter->read('cars', 'mycar');
        $this->assertSame(['make' => 'Tesla'], $data);
    }

    // -------------------------------------------------------------------------
    // exists() tests
    // -------------------------------------------------------------------------

    public function testExistsReturnsTrueForPresentDocument(): void
    {
        $id = $this->db->post(['make' => 'BMW']);

        $this->assertTrue($this->db->exists($id));
    }

    public function testExistsReturnsFalseForAbsentDocument(): void
    {
        $this->assertFalse($this->db->exists('i-do-not-exist'));
    }

    // -------------------------------------------------------------------------
    // FileAdapter edge cases
    // -------------------------------------------------------------------------

    public function testFileAdapterThrowsWhenStorageDirCannotBeCreated(): void
    {
        // Use an existing regular file as the path — FileAdapter must reject it
        // because it is not a directory (works even when tests run as root).
        $file = tempnam(sys_get_temp_dir(), 'simpledb_test_');
        $this->assertNotFalse($file, 'Could not create temporary file for test.');

        try {
            $this->expectException(StorageException::class);
            new FileAdapter($file);
        } finally {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function testListIdsReturnsCorrectIds(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $adapter->write('books', 'book1', ['title' => 'A']);
        $adapter->write('books', 'book2', ['title' => 'B']);

        $ids = $adapter->listIds('books');

        $this->assertContains('book1', $ids);
        $this->assertContains('book2', $ids);
        $this->assertCount(2, $ids);
    }

    public function testDeleteNonExistentFileIsIdempotent(): void
    {
        $adapter = new FileAdapter($this->tmpDir);

        // Should not throw — document simply does not exist
        $adapter->delete('cars', 'phantom');

        $this->assertTrue(true);
    }

    public function testBatchWriteViaAdapter(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $adapter->batchWrite('fleet', [
            'truck1' => ['type' => 'truck'],
            'van1'   => ['type' => 'van'],
        ]);

        $this->assertSame(['type' => 'truck'], $adapter->read('fleet', 'truck1'));
        $this->assertSame(['type' => 'van'], $adapter->read('fleet', 'van1'));
        $this->assertSame(2, $adapter->count('fleet'));
    }

    public function testStreamViaAdapter(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $adapter->write('stream_col', 'a1', ['val' => 1]);
        $adapter->write('stream_col', 'a2', ['val' => 2]);

        $yielded = [];
        foreach ($adapter->stream('stream_col') as $id => $doc) {
            $yielded[$id] = $doc;
        }

        $this->assertArrayHasKey('a1', $yielded);
        $this->assertArrayHasKey('a2', $yielded);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
