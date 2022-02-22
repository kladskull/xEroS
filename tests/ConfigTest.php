<?php declare(strict_types=1);

namespace Xeros;

use JetBrains\PhpStorm\ArrayShape;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /*
    private $ba;
    private $idsToDelete = [];
    */

    // execute before first test
    public static function setUpBeforeClass(): void
    {
    }

    // execute after last
    public static function tearDownAfterClass(): void
    {
    }

    /**
     * (add multiple functions below)
     * @dataProvider additionProvider
     * @dataProvider additionWithNegativeNumbersProvider
     */
    public function testAdd(int $a, int $b, int $expected): void
    {
        $this->assertSame($expected, $a + $b);
    }

    #[ArrayShape(['adding zeros' => "int[]", 'zero plus one' => "int[]", 'one plus zero' => "int[]", 'one plus one' => "int[]"])]
    public function additionProvider(): array
    {
        return [
            'adding zeros' => [0, 0, 0],
            'zero plus one' => [0, 1, 1],
            'one plus zero' => [1, 0, 1],
            'one plus one' => [1, 2, 3]
        ];
    }

    public function additionWithNegativeNumbersProvider(): array
    {
        return [
            [-1, 1, 0],
            [-1, -1, -2],
            [1, -1, 0]

        ];
    }

    /*
    public function additionProvider(): CsvFileIterator
    {
        return new CsvFileIterator('data.csv');
    }
    */

    public function testGetDatabaseHost(): void
    {
        $this->assertEquals(Config::getVersion(), '0.0001');
    }

}
