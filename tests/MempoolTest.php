<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

class MempoolTest extends TestCase
{
    use ChainFixtures;

    private function payThief(array $coinbase, ?array $to = null): array
    {
        return $this->transfer(
            [[$coinbase['transaction_id'], 0]],
            $to ?? $this->thief,
            $this->spendAmount(),
            $this->owner
        );
    }

    /**
     * Changes a field and signs the transaction again so only that field is wrong.
     */
    private function resign(array $tx, string $field, int|string $value): array
    {
        $tx[$field] = $value;

        return $this->sign($tx, $this->owner, $this->owner);
    }

    public function testValidTransactionIsAccepted(): void
    {
        $mempool = new Mempool();
        $tx = $this->payThief($this->fundOwner());

        $this->assertTrue($mempool->add($tx));
        $this->assertNotNull($mempool->getByTransactionId($tx['transaction_id']));
    }

    public function testSameTransactionTwiceIsAcceptedOnce(): void
    {
        $mempool = new Mempool();
        $tx = $this->payThief($this->fundOwner());

        $this->assertTrue($mempool->add($tx));
        $this->assertFalse($mempool->add($tx));
        $this->assertEquals(1, $mempool->getMempoolCount(PHP_INT_MAX));
    }

    public function testSameSenderCanHaveTwoPaymentsWaiting(): void
    {
        $mempool = new Mempool();
        $first = $this->payThief($this->fundOwner());
        $second = $this->payThief($this->fundOwner());

        $this->assertTrue($mempool->add($first));
        $this->assertTrue($mempool->add($second));
        $this->assertEquals(2, $mempool->getMempoolCount(PHP_INT_MAX));
    }

    public function testCoinbaseIsRefused(): void
    {
        $block = $this->mineNextBlock($this->owner);

        $this->assertFalse((new Mempool())->add($this->coinbaseOf($block)));
    }

    public function testFarFutureHeightIsRefused(): void
    {
        $tx = $this->payThief($this->fundOwner());
        $tx = $this->resign($tx, 'height', $this->block->getCurrentHeight() + 50);

        $this->assertFalse((new Mempool())->add($tx));
    }

    public function testExpiredTransactionIsRefused(): void
    {
        $tx = $this->payThief($this->fundOwner());
        $tx = $this->resign($tx, 'date_created', time() - Config::getMempoolExpiry() - 60);

        $this->assertFalse((new Mempool())->add($tx));
    }

    public function testFullMempoolRefusesNewTransactions(): void
    {
        $tx = $this->payThief($this->fundOwner());
        $stmt = $this->db->prepare(
            'INSERT INTO mempool_transactions (`transaction_id`,`date_created`,`peer`,`height`,`version`,' .
            "`signature`,`public_key`) VALUES (:id,:d,'test',1,'01','','')"
        );

        for ($i = 0; $i < Config::getMaxMempoolTransactions(); $i++) {
            $stmt->execute([':id' => 'filler' . $i, ':d' => time()]);
        }

        $this->assertFalse((new Mempool())->add($tx));
    }

    public function testEvictionKeepsValidTransactions(): void
    {
        $mempool = new Mempool();
        $tx = $this->payThief($this->fundOwner());
        $this->assertTrue($mempool->add($tx));

        $this->assertEquals(0, $mempool->evictStale($this->block->getCurrentHeight() + 1));
        $this->assertNotNull($mempool->getByTransactionId($tx['transaction_id']));
    }

    public function testEvictionDropsExpiredTransactions(): void
    {
        $mempool = new Mempool();
        $tx = $this->payThief($this->fundOwner());
        $this->assertTrue($mempool->add($tx));

        $stmt = $this->db->prepare('UPDATE mempool_transactions SET date_created=:d WHERE transaction_id=:id');
        $stmt->execute([':d' => time() - Config::getMempoolExpiry() - 1, ':id' => $tx['transaction_id']]);

        $this->assertEquals(1, $mempool->evictStale($this->block->getCurrentHeight() + 1));
        $this->assertNull($mempool->getByTransactionId($tx['transaction_id']));
        $this->assertEquals([], $mempool->getTransactionInputs($tx['transaction_id']));
        $this->assertEquals([], $mempool->getTransactionOutputs($tx['transaction_id']));
    }

    public function testEvictionDropsTransactionsABlockMadeInvalid(): void
    {
        $mempool = new Mempool();
        $coinbase = $this->fundOwner();

        // the mempool holds one spend of the output, a block confirms a different spend of it
        $waiting = $this->payThief($coinbase);
        $this->assertTrue($mempool->add($waiting));
        $confirmed = $this->payThief($coinbase, $this->owner);

        $block = $this->candidateOn($this->block->getCurrent(), $this->owner, [$confirmed]);
        $this->assertTrue($this->block->add($block, false, true));
        $this->assertTrue($this->block->acceptBlock($block['block_id'], (int)$block['height']));

        // nothing removed it when the block was accepted, it can never be mined now
        $this->assertNotNull($mempool->getByTransactionId($waiting['transaction_id']));
        $this->assertEquals(1, $mempool->evictStale($this->block->getCurrentHeight() + 1));
        $this->assertEquals(0, $mempool->getMempoolCount(PHP_INT_MAX));
    }

    public function testMinedTransactionLeavesTheMempool(): void
    {
        $mempool = new Mempool();
        $tx = $this->payThief($this->fundOwner());
        $this->assertTrue($mempool->add($tx));

        $this->mineNextBlock($this->owner);

        $this->assertNull($mempool->getByTransactionId($tx['transaction_id']));
    }
}
