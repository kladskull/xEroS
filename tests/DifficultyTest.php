<?php declare(strict_types=1);

namespace Xeros;

use PHPUnit\Framework\TestCase;
use RichJenks\Merkle\Merkle;

class DifficultyTest extends TestCase
{
    private Block $block;

    protected function setUp(): void
    {
        $this->block = new Block();
    }

    public function testPerfectDifficulty(): void
    {
        $date = time();
        $lastBlock = [
            'date_created' => $date,
            'difficulty' => 24
        ];

        $firstBlock = [
            'date_created' => $date - 86400,
            'difficulty' => 24
        ];
        $difficulty = $this->block->getDifficulty(144, $lastBlock, $firstBlock);

        $this->assertEquals(50, $difficulty);
    }

    public function testFasterBlockTimeDifficulty(): void
    {
        $date = time();
        $lastBlock = [
            'date_created' => $date,
            'difficulty' => 24,
        ];

        $firstBlock = [
            'date_created' => $date - 86400 + (10 * 600), // ~566 blocktime
            'difficulty' => 24
        ];
        $difficulty = $this->block->getDifficulty(144, $lastBlock, $firstBlock);

        $this->assertEquals(25, $difficulty);
    }

    public function testSlowerBlockTimeDifficulty(): void
    {
        $date = time();
        $lastBlock = [
            'date_created' => $date,
            'difficulty' => 24
        ];

        $firstBlock = [
            'date_created' => $date - 86400 - (10 * 600), // ~633 blocktime
            'difficulty' => 24
        ];
        $difficulty = $this->block->getDifficulty(144, $lastBlock, $firstBlock, 50);

        $this->assertEquals(23, $difficulty);
    }
}