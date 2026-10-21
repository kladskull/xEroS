<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

class AccountBalanceTest extends TestCase
{
    use ChainFixtures;

    public function testBalanceCountsOnlyUnspentOutputs(): void
    {
        $account = new Account();
        $ownerAddress = $this->address->create($this->owner['public_key']);
        $thiefAddress = $this->address->create($this->thief['public_key']);
        $coinbase = $this->fundOwner();
        $reward = $coinbase[Transaction::OUTPUTS][0]['value'];

        $this->assertEquals($reward, $account->getBalance($ownerAddress));

        $tx = $this->transfer([[$coinbase['transaction_id'], 0]], $this->thief, $this->spendAmount(), $this->owner);
        $this->assertTrue((new Mempool())->add($tx));

        // pending: the owner's output is on its way out, the thief's is on its way in
        $this->assertEquals('0', $account->getPendingBalance($ownerAddress));
        $this->assertEquals($this->spendAmount(), $account->getPendingBalance($thiefAddress));
        $this->assertEquals($reward, $account->getBalance($ownerAddress));

        // once mined the owner only has the new block's reward plus the fee
        $block = $this->mineNextBlock($this->owner);
        $this->assertEquals($this->coinbaseOf($block)[Transaction::OUTPUTS][0]['value'], $account->getBalance($ownerAddress));
        $this->assertEquals($this->spendAmount(), $account->getBalance($thiefAddress));
    }

    public function testOrphanedBlockDoesNotCount(): void
    {
        $account = new Account();
        $ownerAddress = $this->address->create($this->owner['public_key']);
        $block = $this->mineNextBlock($this->owner);

        $this->db->prepare('UPDATE blocks SET orphan=1 WHERE block_id=:id')->execute([':id' => $block['block_id']]);

        $this->assertEquals('0', $account->getBalance($ownerAddress));
    }
}
