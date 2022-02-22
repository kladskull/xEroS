<?php declare(strict_types=1);

namespace Xeros;

use PHPUnit\Framework\TestCase;

class BalanceTest extends TestCase
{
    private Balance $balance;
    private Account $account;
    private Address $address;
    private Transaction $transaction;
    private array $createdIds = [];
    private array $mempoolIds = [];
    private array $accountCreatedIds = [];

    protected function setUp(): void
    {
        $this->balance = new Balance();
        $this->account = new Account();
        $this->address = new Address();
        $this->transaction = new Transaction();
    }

    protected function tearDown(): void
    {
        // delete accounts
        foreach ($this->createdIds as $id) {
            $this->account->delete($id);
        }

        foreach ($this->accountCreatedIds as $id) {
            DB::query("DELETE FROM accounts WHERE id=%i;", $id);
        }
    }

    public function testCreate(): void
    {
        $id = $this->account->create();
        $this->assertGreaterThanOrEqual(1, $id);

        // store to clean-up
        $this->accountCreatedIds[] = $id;

        // store to clean-up
        $this->createdIds[] = $id;
    }

    public function testAddBalance(): void
    {
        $id = $this->account->create();
        $account = $this->account->get($id);
        $this->accountCreatedIds[] = $id;

        $this->assertGreaterThanOrEqual(1, $id);

        // store to clean-up
        $this->accountCreatedIds[] = $id;

        $testAmount = "15";
        $this->balance->add($account['address'], "10");
        $this->balance->add($account['address'], "5");

        $balance = $this->balance->getBalance($account['address']);

        $this->assertEquals(0, bccomp($balance, $testAmount));

        // store to clean-up
        $this->createdIds[] = $id;
    }

    public function testSubtractBalance(): void
    {
        $id = $this->account->create();
        $account = $this->account->get($id);
        $this->assertGreaterThanOrEqual(1, $id);

        // store to clean-up
        $this->accountCreatedIds[] = $id;

        $testAmount = "50";
        $this->balance->add($account['address'], "100");
        $this->balance->subtract($account['address'], "50");

        $balance = $this->balance->getBalance($account['address']);

        $this->assertEquals(0, bccomp($balance, $testAmount));

        // store to clean-up
        $this->createdIds[] = $id;
    }

}
