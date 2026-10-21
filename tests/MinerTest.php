<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

class MinerTest extends TestCase
{
    private Miner $miner;
    private Pow $pow;

    protected function setUp(): void
    {
        $this->miner = new Miner();
        $this->pow = new Pow();
    }

    public function testNumberOfSetBits(): void
    {
        $this->assertEquals(0, $this->miner->numberOfSetBits(0));
        $this->assertEquals(1, $this->miner->numberOfSetBits(128));
        $this->assertEquals(8, $this->miner->numberOfSetBits(255));
        $this->assertEquals(4, $this->miner->numberOfSetBits(0b10101010));
    }

    public function testHashRateText(): void
    {
        $this->assertEquals('0.00hps', $this->miner->hashOutput(0));
        $this->assertEquals('500.00hps', $this->miner->hashOutput(500));
        $this->assertEquals('1.50Khps', $this->miner->hashOutput(1500));
        $this->assertEquals('2.00Mhps', $this->miner->hashOutput(2000000));
    }

    public function testFoundHashAgreesWithTheConsensusRule(): void
    {
        // the miner has its own fast bit check, it must accept exactly what block validation accepts
        foreach ([0, 1, 7, 8, 9, 12] as $difficulty) {
            $result = $this->miner->mineBlock('header' . $difficulty, $difficulty, 1, 1, false);

            $this->assertTrue($result['result']);
            $this->assertTrue($this->pow->meetsDifficulty($result['hash'], $difficulty), 'difficulty ' . $difficulty);
            $this->assertTrue($this->pow->verifyPow($result['hash'], 'header' . $difficulty, dechex($result['nonce'])));
        }
    }

    public function testFoundHashIsTheFirstThatMeetsTheDifficulty(): void
    {
        $result = $this->miner->mineBlock('first', 10, 1, 1, false);

        for ($nonce = 1; $nonce < $result['nonce']; $nonce++) {
            $hash = bin2hex($this->pow->calculate('first', dechex($nonce)));
            $this->assertFalse($this->pow->meetsDifficulty($hash, 10), 'nonce ' . $nonce . ' was skipped');
        }
    }
}
