<?php declare(strict_types=1);

namespace Xeros;

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

    private function createAccount(): array
    {
        $id = $this->account->create();
        $this->createdIds[] = $id;
        return $this->account->get($id);
    }

    /**
     * @throws Exception
     */
    private function createBlock(int $height, int $date, string $nonce, array $generatorAccount, array $previousBlock, array $transactions, string $hash): array
    {
        return [];
    }

    /**
     * No longer works
     * @throws Exception
     */
    private function createTransaction(int $date, string $blockId, int $height, array $destinationAccount, array $sourceAccount, string $amount, string $fee, string $message): array
    {
        return [];
    }

    /**
     * @throws Exception
     */
    public function testGenesis(): void
    {

    }

    public function testMaxTransactions(): void
    {
        $this->assertEquals(100, $this->block->getMaxTransactions());
    }

    public function testGetRewardValue(): void
    {
        $this->assertEquals(0, bccomp("5", $this->block->getRewardValue(1)));
        $this->assertEquals(0, bccomp("2.5", $this->block->getRewardValue(565001)));
        $this->assertEquals(0, bccomp("1.25", $this->block->getRewardValue(1130002)));
        $this->assertEquals(0, bccomp("0.625", $this->block->getRewardValue(1695003)));
        $this->assertEquals(0, $this->block->getRewardValue(10000000));
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

    public function testDifficultyDefaults(): void
    {
        $this->assertEquals(Config::getDefaultDifficulty(), $this->block->getDifficulty(-1));
        $this->assertEquals(Config::getDefaultDifficulty(), $this->block->getDifficulty());
        $this->assertEquals(Config::getDefaultDifficulty(), $this->block->getDifficulty(10));
        $this->assertEquals(Config::getDefaultDifficulty(), $this->block->getDifficulty(200));
        $this->assertEquals(Config::getDefaultDifficulty(), $this->block->getDifficulty(2000));
        $this->assertEquals(Config::getDefaultDifficulty(), $this->block->getDifficulty(2015));
    }

    /**
     * @throws Exception
     */
    public function testAdd(): void
    {
    }

    /**
     * @throws Exception
     */
    public function testGetCurrent(): void
    {
    }

    /**
     * @throws Exception
     */
    public function testGetCurrentHeight(): void
    {
    }

    /**
     * @throws Exception
     */
    public function testDifficultyWithRealBlocksPerfectBlockTiming(): void
    {
    }

}