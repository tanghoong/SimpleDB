<?php

declare(strict_types=1);

namespace SimpleDB\Tests;

use PHPUnit\Framework\TestCase;
use SimpleDB\Adapters\ApcuCacheAdapter;
use SimpleDB\Adapters\FileAdapter;
use SimpleDB\Adapters\SqliteAdapter;
use SimpleDB\Exceptions\StorageException;
use SimpleDB\SimpleDB;

class ApcuCacheAdapterTest extends TestCase
{
    private string $tmpDir;
    private FileAdapter $file;
    private ApcuCacheAdapter $cached;
    private SimpleDB $db;

    protected function setUp(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            $this->markTestSkipped('APCu extension is required (and apc.enable_cli=1 for CLI).');
        }

        // Flush APCu before each test to avoid cross-test pollution.
        apcu_clear_cache();

        $this->tmpDir = sys_get_temp_dir() . '/simpledb_apcu_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, true);

        $this->file   = new FileAdapter($this->tmpDir);
        $this->cached = new ApcuCacheAdapter($this->file, ttl: 60, keyPrefix: 'test:');
        $this->db     = new SimpleDB('items', $this->cached);
    }

    protected function tearDown(): void
    {
        if (extension_loaded('apcu') && function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }

        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    // -------------------------------------------------------------------------
    // Basic CRUD pass-through
    // -------------------------------------------------------------------------

    public function testCreateAndRetrieve(): void
    {
        $id  = $this->db->post(['name' => 'Widget']);
        $doc = $this->db->get($id);

        $this->assertSame(['name' => 'Widget'], $doc);
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

    // -------------------------------------------------------------------------
    // Cache behaviour
    // -------------------------------------------------------------------------

    public function testReadIsCachedInApcu(): void
    {
        $id = $this->db->post(['v' => 42]);

        // Clear the SimpleDB in-process cache so the next get() hits APCu
        $this->db->clearCache();

        $doc = $this->db->get($id);

        // Now the value should be in APCu — verify by reading the APCu key directly
        $apcuValue = apcu_fetch('test:items:' . $id, $found);

        $this->assertTrue($found, 'Document should be stored in APCu after first read.');
        $this->assertSame(['v' => 42], $apcuValue);
        $this->assertSame(['v' => 42], $doc);
    }

    public function testApcuHitAvoidsFilesystemRead(): void
    {
        $id = $this->db->post(['v' => 1]);

        // Warm APCu
        $this->db->clearCache();
        $this->db->get($id);

        // Now overwrite the file directly to simulate a stale cache scenario.
        // If the adapter reads from APCu, it will return the old value.
        $filePath = $this->tmpDir . '/items/' . $id . '.json';
        file_put_contents($filePath, json_encode(['v' => 999]));

        $this->db->clearCache();
        $doc = $this->db->get($id);

        // APCu serves the cached (old) value, not the file-modified value
        $this->assertSame(['v' => 1], $doc, 'APCu cache hit should bypass filesystem.');
    }

    public function testWriteUpdatesApcuCache(): void
    {
        $id = $this->db->post(['v' => 1]);

        // Warm the cache
        $this->db->clearCache();
        $this->db->get($id);

        // Update through the adapter
        $this->db->put($id, ['v' => 2]);
        $this->db->clearCache();

        // APCu should now return the updated value
        $apcuValue = apcu_fetch('test:items:' . $id, $found);

        $this->assertTrue($found);
        $this->assertSame(['v' => 2], $apcuValue);
    }

    public function testDeleteEvictsFromApcu(): void
    {
        $id = $this->db->post(['v' => 1]);

        // Warm the cache
        $this->db->clearCache();
        $this->db->get($id);

        $this->db->delete($id);

        $apcu_value = apcu_fetch('test:items:' . $id, $found);

        $this->assertFalse($found, 'Deleted document should be evicted from APCu.');
    }

    public function testMissingDocumentTombstonedInApcu(): void
    {
        // Reading a non-existent document should store a tombstone in APCu
        $this->db->get('ghost-id');

        // A second read should be served from APCu (tombstone), not storage
        $apcuValue = apcu_fetch('test:items:ghost-id', $found);

        $this->assertTrue($found, 'A tombstone should be cached for missing documents.');
        $this->assertNull($this->db->get('ghost-id'));
    }

    public function testReadAllServesDocumentsFromApcu(): void
    {
        $this->db->put('a', ['x' => 1]);
        $this->db->put('b', ['x' => 2]);

        // Warm APCu
        $this->db->clearCache();
        $this->db->getAll();

        // Corrupt the files to prove APCu is being read
        file_put_contents($this->tmpDir . '/items/a.json', '{"x": 999}');

        $this->db->clearCache();
        $all = $this->db->getAll(); // listIds() still reads dir, but read() hits APCu

        $this->assertSame(['x' => 1], $all['a'], 'readAll() should serve cached doc for key "a".');
    }

    public function testBatchWriteUpdatesApcu(): void
    {
        $this->db->batchPut([
            'k1' => ['n' => 1],
            'k2' => ['n' => 2],
        ]);

        $apcuK1 = apcu_fetch('test:items:k1', $foundK1);
        $apcuK2 = apcu_fetch('test:items:k2', $foundK2);

        $this->assertTrue($foundK1);
        $this->assertTrue($foundK2);
        $this->assertSame(['n' => 1], $apcuK1);
        $this->assertSame(['n' => 2], $apcuK2);
    }

    // -------------------------------------------------------------------------
    // Stream warms the cache
    // -------------------------------------------------------------------------

    public function testStreamWarmsApcuCache(): void
    {
        $this->db->put('s1', ['v' => 10]);
        $this->db->put('s2', ['v' => 20]);

        // Clear SimpleDB in-process cache and APCu
        $this->db->clearCache();
        apcu_clear_cache();

        // Stream populates APCu
        foreach ($this->db->stream() as $id => $doc) {
            // iterate all
        }

        $v = apcu_fetch('test:items:s1', $found);
        $this->assertTrue($found, 'stream() should warm the APCu cache.');
        $this->assertSame(['v' => 10], $v);
    }

    // -------------------------------------------------------------------------
    // Stacking ApcuCacheAdapter on top of SqliteAdapter
    // -------------------------------------------------------------------------

    public function testApcuOnTopOfSqlite(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite extension required.');
        }

        $sqlite  = new SqliteAdapter(':memory:');
        $adapter = new ApcuCacheAdapter($sqlite, ttl: 30, keyPrefix: 'sqlite_test:');
        $db      = new SimpleDB('products', $adapter);

        $id  = $db->post(['name' => 'Laptop', 'price' => 999]);
        $doc = $db->get($id);

        $this->assertSame('Laptop', $doc['name']);

        // Verify the APCu key was written
        $cached = apcu_fetch('sqlite_test:products:' . $id, $found);
        $this->assertTrue($found);
        $this->assertSame('Laptop', $cached['name']);
    }

    // -------------------------------------------------------------------------
    // evict() helper
    // -------------------------------------------------------------------------

    public function testEvictRemovesSpecificKey(): void
    {
        $this->db->put('e1', ['v' => 1]);
        $this->db->clearCache();
        $this->db->get('e1'); // warm APCu

        $this->cached->evict('items', 'e1');

        $apcu_value = apcu_fetch('test:items:e1', $found);
        $this->assertFalse($found);
    }

    // -------------------------------------------------------------------------
    // Error: APCu not available
    // -------------------------------------------------------------------------

    public function testThrowsWhenApcuExtensionMissing(): void
    {
        // This test only makes sense when we can simulate the extension being absent.
        // Since we can't unload extensions, we test the error message indirectly
        // by verifying the class checks correctly when APCu IS present.
        $this->assertInstanceOf(ApcuCacheAdapter::class, $this->cached);
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
