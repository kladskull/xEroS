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

    /*
    TODO:: No longer works this way, we need to generate a real block test set.
    public function testPendingBalanceWithSpend(): void
    {
        $id = $this->account->create();

        // store to clean-up
        $this->createdIds[] = $id;

        $account = $this->account->get($id);
        $this->assertGreaterThanOrEqual(1, $id);

        // store to clean-up
        $this->accountCreatedIds[] = $id;

        $this->balance->add($account['address'], "700");
        $balance = $this->balance->getBalance($account['address']);

        $this->assertEquals(0, bccomp("700", $balance));

        $transactionId = hash('sha256', random_bytes(8));
        DB::insert('transactions', [
            'transaction_id' => $transactionId,
            'height' => 123,
            'date_created' => time(),
            'mempool' => 1,
            'public_key' => 'FAKE' . hash('sha256', random_bytes(8)),
            'signature' => 'FAKE' . hash('sha256', random_bytes(8)),
            'version' => Version::TestTransaction,
            'peer' => substr(hash('sha256', random_bytes(8)), 0, 64),
        ]);
        $this->mempoolIds[] = DB::insertId();

        $this->assertEquals(0, bccomp("45", $this->balance->getPendingBalance($account['address'])));
    }

    public function testPendingBalanceWithUnspent(): void
    {
        $id = $this->account->create();

        // store to clean-up
        $this->createdIds[] = $id;

        $account = $this->account->get($id);
        $this->assertGreaterThanOrEqual(1, $id);

        // store to clean-up
        $this->accountCreatedIds[] = $id;

        $this->balance->add($account['address'], "700");
        $balance = $this->balance->getBalance($account['address']);

        $this->assertEquals(0, bccomp("700", $balance));

        DB::insert('mempool', [
            'transaction_id' => hash('sha256', random_bytes(8)),
            'height' => 123,
            'date_created' => time(),
            'destination_address' => $account['address'],
            'public_key_raw' => $account['public_key_raw'],
            'source_address' => $account['address'] . 'X',
            'amount' => $this->balance->currencyToSatoshi("150"),
            'fee' => $this->balance->currencyToSatoshi("5"),
            'signature' => 'FAKE' . hash('sha256', random_bytes(8)),
            'version' => Version::TestTransaction,
            'message' => 'Test transaction',
            'peer' => substr(hash('sha256', random_bytes(8)), 0, 64),
        ]);
        $this->mempoolIds[] = DB::insertId();

        $this->assertEquals(0, bccomp("850", $this->balance->getPendingBalance($account['address'])));
    }

    */
}
