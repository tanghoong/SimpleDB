<?php

declare(strict_types=1);

namespace SimpleDB\Tests;

use PHPUnit\Framework\TestCase;
use SimpleDB\Adapters\FileAdapter;
use SimpleDB\Exceptions\DocumentNotFoundException;
use SimpleDB\SimpleDB;

/**
 * Tests for: ArrayAccess / Countable / IteratorAggregate interfaces,
 * auto-timestamps, and lifecycle hooks.
 */
class SimpleDBFeaturesTest extends TestCase
{
    private string $tmpDir;
    private SimpleDB $db;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/simpledb_feat_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, true);

        $adapter  = new FileAdapter($this->tmpDir);
        $this->db = new SimpleDB('items', $adapter);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Countable interface
    // -------------------------------------------------------------------------

    public function testCountableInterface(): void
    {
        $this->assertSame(0, count($this->db));

        $this->db->put('a', ['x' => 1]);
        $this->db->put('b', ['x' => 2]);

        $this->assertSame(2, count($this->db));
    }

    // -------------------------------------------------------------------------
    // IteratorAggregate interface
    // -------------------------------------------------------------------------

    public function testIteratorAggregateInterface(): void
    {
        $this->db->put('id1', ['val' => 'one']);
        $this->db->put('id2', ['val' => 'two']);

        $collected = [];
        foreach ($this->db as $id => $doc) {
            $collected[$id] = $doc;
        }

        $this->assertArrayHasKey('id1', $collected);
        $this->assertArrayHasKey('id2', $collected);
        $this->assertCount(2, $collected);
    }

    public function testForeachYieldsEmptyForEmptyCollection(): void
    {
        $count = 0;
        foreach ($this->db as $id => $doc) {
            $count++;
        }
        $this->assertSame(0, $count);
    }

    // -------------------------------------------------------------------------
    // ArrayAccess — offsetGet
    // -------------------------------------------------------------------------

    public function testArrayAccessGet(): void
    {
        $this->db->put('car1', ['make' => 'Honda']);

        $this->assertSame(['make' => 'Honda'], $this->db['car1']);
    }

    public function testArrayAccessGetReturnsNullForMissing(): void
    {
        $this->assertNull($this->db['does-not-exist']);
    }

    // -------------------------------------------------------------------------
    // ArrayAccess — offsetSet
    // -------------------------------------------------------------------------

    public function testArrayAccessSetWithExplicitId(): void
    {
        $this->db['my-id'] = ['name' => 'Test'];

        $this->assertSame(['name' => 'Test'], $this->db->get('my-id'));
    }

    public function testArrayAccessSetWithNullIdCallsPost(): void
    {
        $this->db[] = ['name' => 'Auto'];

        $this->assertSame(1, count($this->db));
    }

    public function testArrayAccessSetRequiresArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->db['id'] = 'not-an-array';
    }

    // -------------------------------------------------------------------------
    // ArrayAccess — offsetExists / offsetUnset
    // -------------------------------------------------------------------------

    public function testArrayAccessIsset(): void
    {
        $this->db->put('exists', ['v' => 1]);

        $this->assertTrue(isset($this->db['exists']));
        $this->assertFalse(isset($this->db['no-such-id']));
    }

    public function testArrayAccessUnset(): void
    {
        $this->db->put('to-delete', ['v' => 1]);
        unset($this->db['to-delete']);

        $this->assertNull($this->db->get('to-delete'));
    }

    public function testArrayAccessUnsetOnMissingIdIsNoOp(): void
    {
        // Must not throw DocumentNotFoundException
        unset($this->db['phantom']);
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Auto-timestamps
    // -------------------------------------------------------------------------

    public function testTimestampsInjectedOnPost(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $db      = new SimpleDB('ts', $adapter, timestamps: true);

        $before = time();
        $id     = $db->post(['name' => 'Alice']);
        $after  = time();

        $doc = $db->get($id);

        $this->assertArrayHasKey('_created_at', $doc);
        $this->assertArrayHasKey('_updated_at', $doc);
        $this->assertGreaterThanOrEqual($before, $doc['_created_at']);
        $this->assertLessThanOrEqual($after, $doc['_created_at']);
        $this->assertSame($doc['_created_at'], $doc['_updated_at']);
    }

    public function testTimestampsUpdatedAtInjectedOnPut(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $db      = new SimpleDB('ts', $adapter, timestamps: true);

        $id = $db->post(['name' => 'Alice']);
        sleep(1); // ensure _updated_at differs

        $db->put($id, ['name' => 'Alice Updated']);

        $doc = $db->get($id);

        $this->assertArrayNotHasKey('_created_at', $doc, '_created_at must not be added by put()');
        $this->assertArrayHasKey('_updated_at', $doc);
    }

    public function testTimestampsDoNotOverrideExistingValues(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $db      = new SimpleDB('ts', $adapter, timestamps: true);

        $customTime = 1000000;
        $id         = $db->post(['name' => 'Bob', '_created_at' => $customTime, '_updated_at' => $customTime]);

        $doc = $db->get($id);

        $this->assertSame($customTime, $doc['_created_at']);
        $this->assertSame($customTime, $doc['_updated_at']);
    }

    public function testTimestampsInjectedInBatchPost(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $db      = new SimpleDB('ts', $adapter, timestamps: true);

        $ids = $db->batchPost([['n' => 1], ['n' => 2]]);

        foreach ($ids as $id) {
            $doc = $db->get($id);
            $this->assertArrayHasKey('_created_at', $doc);
            $this->assertArrayHasKey('_updated_at', $doc);
        }
    }

    public function testTimestampsInjectedInBatchPut(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $db      = new SimpleDB('ts', $adapter, timestamps: true);

        $db->batchPut(['x1' => ['n' => 1], 'x2' => ['n' => 2]]);

        foreach (['x1', 'x2'] as $id) {
            $doc = $db->get($id);
            $this->assertArrayHasKey('_updated_at', $doc);
        }
    }

    public function testNoTimestampsWhenDisabled(): void
    {
        $id  = $this->db->post(['name' => 'No timestamps']);
        $doc = $this->db->get($id);

        $this->assertArrayNotHasKey('_created_at', $doc);
        $this->assertArrayNotHasKey('_updated_at', $doc);
    }

    // -------------------------------------------------------------------------
    // beforeWrite hook
    // -------------------------------------------------------------------------

    public function testBeforeWriteHookCanModifyData(): void
    {
        $this->db->beforeWrite(function (string $id, array $data, bool $isNew): array {
            $data['injected'] = 'yes';
            return $data;
        });

        $id  = $this->db->post(['name' => 'Original']);
        $doc = $this->db->get($id);

        $this->assertSame('yes', $doc['injected']);
    }

    public function testBeforeWriteHookReceivesIsNewFlag(): void
    {
        $flags = [];

        $this->db->beforeWrite(function (string $id, array $data, bool $isNew) use (&$flags): array {
            $flags[] = $isNew;
            return $data;
        });

        $id = $this->db->post(['x' => 1]);
        $this->db->put($id, ['x' => 2]);

        $this->assertSame([true, false], $flags);
    }

    public function testBeforeWriteHookMustReturnArray(): void
    {
        $this->db->beforeWrite(function (string $id, array $data, bool $isNew): mixed {
            return null; // wrong — must return array
        });

        $this->expectException(\LogicException::class);
        $this->db->post(['x' => 1]);
    }

    public function testMultipleBeforeWriteHooksRunInOrder(): void
    {
        $order = [];

        $this->db->beforeWrite(function (string $id, array $data, bool $isNew) use (&$order): array {
            $order[] = 'first';
            return $data;
        });

        $this->db->beforeWrite(function (string $id, array $data, bool $isNew) use (&$order): array {
            $order[] = 'second';
            return $data;
        });

        $this->db->post(['x' => 1]);

        $this->assertSame(['first', 'second'], $order);
    }

    // -------------------------------------------------------------------------
    // afterWrite hook
    // -------------------------------------------------------------------------

    public function testAfterWriteHookFires(): void
    {
        $fired = [];

        $this->db->afterWrite(function (string $id, array $data, bool $isNew) use (&$fired): void {
            $fired[] = ['id' => $id, 'isNew' => $isNew];
        });

        $id = $this->db->post(['v' => 1]);
        $this->db->put($id, ['v' => 2]);

        $this->assertCount(2, $fired);
        $this->assertTrue($fired[0]['isNew']);
        $this->assertFalse($fired[1]['isNew']);
    }

    public function testAfterWriteHookFiresInBatchPost(): void
    {
        $fired = 0;
        $this->db->afterWrite(function () use (&$fired): void {
            $fired++;
        });

        $this->db->batchPost([['a' => 1], ['b' => 2], ['c' => 3]]);

        $this->assertSame(3, $fired);
    }

    // -------------------------------------------------------------------------
    // beforeDelete / afterDelete hooks
    // -------------------------------------------------------------------------

    public function testBeforeDeleteHookFires(): void
    {
        $deletedId = null;

        $this->db->beforeDelete(function (string $id) use (&$deletedId): void {
            $deletedId = $id;
        });

        $id = $this->db->post(['x' => 1]);
        $this->db->delete($id);

        $this->assertSame($id, $deletedId);
    }

    public function testAfterDeleteHookFires(): void
    {
        $firedAfter = false;

        $this->db->afterDelete(function (string $id) use (&$firedAfter): void {
            $firedAfter = true;
        });

        $id = $this->db->post(['x' => 1]);
        $this->db->delete($id);

        $this->assertTrue($firedAfter);
    }

    public function testBeforeDeleteHookFiresBeforeActualDeletion(): void
    {
        $existedDuringHook = null;
        $adapter           = new FileAdapter($this->tmpDir);
        $db                = new SimpleDB('hook_order', $adapter);

        $db->beforeDelete(function (string $id) use ($db, &$existedDuringHook): void {
            $existedDuringHook = $db->exists($id);
        });

        $id = $db->post(['x' => 1]);
        $db->delete($id);

        $this->assertTrue($existedDuringHook, 'Document must still exist when beforeDelete fires.');
    }

    public function testAfterDeleteHookFiresAfterActualDeletion(): void
    {
        $existedAfterHook = true;
        $adapter          = new FileAdapter($this->tmpDir);
        $db               = new SimpleDB('hook_order', $adapter);

        $db->afterDelete(function (string $id) use ($db, &$existedAfterHook): void {
            $existedAfterHook = $db->exists($id);
        });

        $id = $db->post(['x' => 1]);
        $db->delete($id);

        $this->assertFalse($existedAfterHook, 'Document must be gone when afterDelete fires.');
    }

    // -------------------------------------------------------------------------
    // where() shortcut on SimpleDB
    // -------------------------------------------------------------------------

    public function testWhereShortcutReturnsQueryBuilder(): void
    {
        $this->db->put('a', ['type' => 'x']);
        $this->db->put('b', ['type' => 'y']);

        $results = $this->db->where('type', 'x')->get();

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('a', $results);
    }

    public function testWhereShortcutWithOperator(): void
    {
        $adapter = new FileAdapter($this->tmpDir);
        $db      = new SimpleDB('scores', $adapter);

        $db->put('s1', ['score' => 10]);
        $db->put('s2', ['score' => 20]);
        $db->put('s3', ['score' => 30]);

        $results = $db->where('score', '>', 15)->get();

        $this->assertCount(2, $results);
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
