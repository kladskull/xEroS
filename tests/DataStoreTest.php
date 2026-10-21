<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PHPUnit\Framework\TestCase;

class DataStoreTest extends TestCase
{
    private PDO $db;
    private DataStore $store;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->beginTransaction();
        $this->store = new DataStore();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testAddAndRead(): void
    {
        $id = $this->store->add('test_key', 'value');

        $this->assertGreaterThan(0, $id);
        $this->assertEquals('value', $this->store->getKey('test_key'));
        $this->assertEquals(['key' => 'test_key', 'data' => 'value'], $this->store->get($id));
    }

    public function testMissingKeyGivesTheDefault(): void
    {
        $this->assertEquals('', $this->store->getKey('no_such_key'));
        $this->assertEquals('fallback', $this->store->getKey('no_such_key', 'fallback'));
    }

    public function testAddReplacesTheValue(): void
    {
        $this->store->add('test_key', 'one');
        $this->store->add('test_key', 'two');

        $this->assertEquals('two', $this->store->getKey('test_key'));
    }

    public function testExpiredValueIsGone(): void
    {
        $this->store->add('old_key', 'value', time() - 1);
        $this->store->add('new_key', 'value', time() + 60);

        $this->assertEquals('gone', $this->store->getKey('old_key', 'gone'));
        $this->assertEquals('value', $this->store->getKey('new_key'));
    }

    public function testKeyTooLongIsRefused(): void
    {
        $this->assertEquals(0, $this->store->add(str_repeat('k', 129), 'value'));
    }

    public function testDelete(): void
    {
        $id = $this->store->add('a_key', 'value');
        $this->store->add('b_key', 'value');

        $this->assertTrue($this->store->delete($id));
        $this->assertNull($this->store->get($id));

        $this->assertTrue($this->store->deleteKey('b_key'));
        $this->assertEquals('', $this->store->getKey('b_key'));
    }
}
