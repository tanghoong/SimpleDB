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
        mkdir($this->tmpDir, 0755, true);

        $adapter    = new FileAdapter($this->tmpDir);
        $this->db   = new SimpleDB('cars', $adapter);
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
        $this->expectException(StorageException::class);

        new FileAdapter('/root/simpledb_no_permission_' . uniqid());
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
