<?php declare(strict_types=1);

namespace Xeros;

use PHPUnit\Framework\TestCase;

class LogTest extends TestCase
{
    private array $createdIds = [];

    protected function setUp(): void
    {

    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            Log::delete($id);
        }
    }


    /**
     * @throws JsonException
     * @throws Exception
     */
    public function testAdd(): void
    {
        $id = Log::add('some_type', 'Test message', 'some key' . md5(random_bytes(16)), 'lots of data');
        $this->createdIds[] = $id;
        self::assertGreaterThan(0, $id);
    }

    /**
     * @throws JsonException
     */
    public function testAddDuplicateKey(): void
    {
        $this->expectException("mysqli_sql_exception");

        $nonUnique = md5(random_bytes(16));
        $id = Log::add('some_type', 'Test message', $nonUnique, 'lots of data');
        $this->createdIds[] = $id;

        $id = Log::add('some_type', 'Test message', $nonUnique, 'lots of data');
        $this->createdIds[] = $id;
    }

    /**
     * @throws JsonException
     */
    public function testGetById(): void
    {
        $anotherKey = 'another key' . md5(random_bytes(16));
        $id = Log::add('another_type', 'Test message', $anotherKey, 'a slight bit of data');
        $this->createdIds[] = $id;

        $data = Log::get($id);
        self::assertEquals('another_type', $data['data_type']);
        self::assertEquals('Test message', $data['message']);
        self::assertEquals($anotherKey, $data['key']);
        self::assertEquals('a slight bit of data', $data['data']);
    }

    /**
     * @throws JsonException
     */
    public function testGetByKey(): void
    {
        $anotherKey = 'another key' . md5(random_bytes(16));
        $id = Log::add('another_type', 'Test message', $anotherKey, 'a slight bit of data');
        $this->createdIds[] = $id;

        $data = Log::getByKey('another_type', $anotherKey);
        self::assertEquals('another_type', $data['data_type']);
        self::assertEquals('Test message', $data['message']);
        self::assertEquals($anotherKey, $data['key']);
        self::assertEquals('a slight bit of data', $data['data']);
    }

    public function testDelete(): void
    {
        $anotherKey = 'deleteme' . md5(random_bytes(16));
        $id = Log::add('another_type', 'Test message', $anotherKey, 'a slight bit of data');
        $this->createdIds[] = $id;

        $data = Log::get($id);
        self::assertEquals('another_type', $data['data_type']);
        self::assertEquals('Test message', $data['message']);
        self::assertEquals($anotherKey, $data['key']);
        self::assertEquals('a slight bit of data', $data['data']);

        // delete
        $this->assertTrue(Log::delete($id));

        // try and load
        $this->assertNull(Log::get($id));
    }

}
