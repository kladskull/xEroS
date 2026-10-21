<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PHPUnit\Framework\TestCase;

class QueueTest extends TestCase
{
    private PDO $db;
    private Queue $queue;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->beginTransaction();
        $this->queue = new Queue();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testAddAndGet(): void
    {
        $id = $this->queue->add('new_block', 'abc');
        $item = $this->queue->get($id);

        $this->assertGreaterThan(0, $id);
        $this->assertEquals('new_block', $item['command']);
        $this->assertEquals('abc', $item['data']);
        $this->assertEquals(0, (int)$item['trys']);
        $this->assertNull($this->queue->get(999999999));
    }

    public function testItemsAreFilteredByCommand(): void
    {
        $this->queue->add('new_block', 'a');
        $this->queue->add('new_block', 'b');
        $this->queue->add('other', 'c');

        $this->assertEquals(['a', 'b'], array_column($this->queue->getItems('new_block'), 'data'));
        $this->assertCount(1, $this->queue->getItems('new_block', 1));
    }

    public function testFailingItemsDropOutAndArePruned(): void
    {
        $id = $this->queue->add('new_block', 'a');

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->queue->incrementFails($id));
        }

        $this->assertEquals([], $this->queue->getItems('new_block'));

        $this->queue->clearFails($id);
        $this->assertCount(1, $this->queue->getItems('new_block'));

        for ($i = 0; $i < 5; $i++) {
            $this->queue->incrementFails($id);
        }

        $this->assertTrue($this->queue->prune());
        $this->assertNull($this->queue->get($id));
    }

    public function testDelete(): void
    {
        $id = $this->queue->add('new_block', 'a');

        $this->assertTrue($this->queue->delete($id));
        $this->assertNull($this->queue->get($id));
    }
}
