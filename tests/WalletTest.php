<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class WalletTest extends TestCase
{
    use ChainFixtures;

    private function account(array $keys): array
    {
        return $keys + ['address' => $this->address->create($keys['public_key'])];
    }

    private function coins(string $coins): string
    {
        return Wallet::toUnits($coins);
    }

    public function testCoinConversion(): void
    {
        $this->assertEquals('150000000', Wallet::toUnits('1.5'));
        $this->assertEquals('1', Wallet::toUnits('0.00000001'));
        $this->assertEquals('2500000000', Wallet::toUnits('25'));
        $this->assertNull(Wallet::toUnits('0.000000001'));
        $this->assertNull(Wallet::toUnits('-1'));
        $this->assertNull(Wallet::toUnits('1e5'));
        $this->assertNull(Wallet::toUnits(''));
        $this->assertEquals('1.50000000', Wallet::toCoins('150000000'));
    }

    public function testTransferWithChangeIsValidAndBalances(): void
    {
        $wallet = new Wallet();
        $owner = $this->account($this->owner);
        $this->fundOwner();
        $before = $wallet->getBalance($owner['address']);

        $tx = $wallet->createTransfer($owner, $this->account($this->thief)['address'], $this->coins('3'));
        $result = $this->transaction->validate($tx);

        $this->assertTrue($result['validated'], $result['reason']);
        $this->assertEquals(Config::getMinimumTransactionFee(), $result['fee']);
        $this->assertCount(2, $tx[Transaction::OUTPUTS]);
        $this->assertEquals($this->coins('3'), $tx[Transaction::OUTPUTS][0]['value']);
        $this->assertEquals(
            bcsub(bcsub($before, $this->coins('3')), Config::getMinimumTransactionFee()),
            $tx[Transaction::OUTPUTS][1]['value']
        );
        $this->assertEquals($owner['address'], $tx[Transaction::OUTPUTS][1]['address']);
    }

    public function testExactAmountHasNoChange(): void
    {
        $wallet = new Wallet();
        $owner = $this->account($this->owner);
        $this->fundOwner();
        $amount = bcsub($wallet->getBalance($owner['address']), Config::getMinimumTransactionFee());

        $tx = $wallet->createTransfer($owner, $this->account($this->thief)['address'], $amount);

        $this->assertCount(1, $tx[Transaction::OUTPUTS]);
        $this->assertTrue($this->transaction->validate($tx)['validated']);
    }

    public function testPaymentIsMinedAndTheReceiverCanSpendIt(): void
    {
        $wallet = new Wallet();
        $mempool = new Mempool();
        $owner = $this->account($this->owner);
        $receiver = $this->account($this->thief);
        $this->fundOwner();

        $tx = $wallet->createTransfer($owner, $receiver['address'], $this->coins('5'));
        $this->assertTrue($mempool->add($tx));

        // the output is taken by the mempool, so it no longer counts as spendable
        $this->assertEquals('0', $wallet->getBalance($owner['address']));

        $this->mineNextBlock($this->owner);
        $this->assertEquals($this->coins('5'), $wallet->getBalance($receiver['address']));

        // the receiver spends what they got, which proves the address lock script works
        $back = $wallet->createTransfer($receiver, $owner['address'], $this->coins('4'));
        $result = $this->transaction->validate($back);
        $this->assertTrue($result['validated'], $result['reason']);
        $this->assertTrue($mempool->add($back));
    }

    public function testSpendsSeveralOutputsWhenNeeded(): void
    {
        $wallet = new Wallet();
        $owner = $this->account($this->owner);
        $this->fundOwner();
        $this->fundOwner();
        $amount = bcadd($this->block->getRewardValue(2), $this->coins('1'));

        $tx = $wallet->createTransfer($owner, $this->account($this->thief)['address'], $amount);

        $this->assertCount(2, $tx[Transaction::INPUTS]);
        $result = $this->transaction->validate($tx);
        $this->assertTrue($result['validated'], $result['reason']);
    }

    public function testNotEnoughFundsThrows(): void
    {
        $wallet = new Wallet();
        $owner = $this->account($this->owner);
        $this->fundOwner();

        $this->expectException(RuntimeException::class);
        $wallet->createTransfer($owner, $this->account($this->thief)['address'], $this->coins('1000'));
    }

    public function testLockedCoinbaseIsNotSpendable(): void
    {
        $wallet = new Wallet();
        $owner = $this->account($this->owner);
        $this->mineNextBlock($this->owner);

        $this->assertEquals('0', $wallet->getBalance($owner['address']));
    }

    public function testBadAddressThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new Wallet())->createTransfer($this->account($this->owner), 'Xanope', '100');
    }

    public function testZeroAmountThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new Wallet())->createTransfer($this->account($this->owner), $this->account($this->thief)['address'], '0');
    }

    public function testFeeBelowTheMinimumThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new Wallet())->createTransfer($this->account($this->owner), $this->account($this->thief)['address'], '100', '1');
    }
}
