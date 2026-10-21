<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use PHPUnit\Framework\TestCase;
use RichJenks\Merkle\Merkle;

class BlockTest extends TestCase
{
    private Account $account;
    private Address $address;
    private Block $block;
    private Transaction $transaction;
    private Peer $peer;
    private array $createdIds = [];
    private array $createdBlockIds = [];
    private array $createdTransactionIds = [];
    private array $createdPeerIds = [];

    protected function setUp(): void
    {
        $this->block = new Block();
        $this->transaction = new Transaction();
        $this->account = new Account();
        $this->address = new Address();
        $this->peer = new Peer();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $this->account->delete($id);
        }

        foreach ($this->createdBlockIds as $id) {
            $this->block->delete($id);
        }

        foreach ($this->createdTransactionIds as $id) {
            $this->transaction->delete($id, false);
        }

        foreach ($this->createdPeerIds as $id) {
            $this->peer->delete($id);
        }
    }

    public function testGetRewardValue(): void
    {
        $this->assertEquals('5000000000', $this->block->getRewardValue(1));
        $this->assertEquals('3324163183', $this->block->getRewardValue(565001));
        $this->assertEquals('2121611688', $this->block->getRewardValue(1130002));
        $this->assertEquals('1354096029', $this->block->getRewardValue(1695003));
        $this->assertEquals('99313533', $this->block->getRewardValue(10000000));
    }

    public function testDifficultyExpectations(): void
    {
        // perfect block time over 2 weeks
        $this->assertEquals(0, 600 - $this->block->getBlockTime(1639526400, 1638316800, 2016));
        // too difficult, didn't create enough blocks
        $this->assertEquals(-32, 600 - $this->block->getBlockTime(1639526400, 1638316800, 1916));
        // too easy, created too many
        $this->assertEquals(28, 600 - $this->block->getBlockTime(1639526400, 1638316800, 2116));
    }

}