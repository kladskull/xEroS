<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

class AccountTest extends TestCase
{
    private Account $account;
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->account = new Account();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $this->account->delete($id);
        }
    }

    public function testCreateAccount(): void
    {
        $id = $this->account->create();
        $this->assertGreaterThanOrEqual(1, $id);

        // store to clean-up
        $this->createdIds[] = $id;
    }

    public function testLoadById(): void
    {
        $start_time = time();
        $id = $this->account->create();
        $this->assertGreaterThanOrEqual(1, $id);

        $account = $this->account->get($id);
        $this->assertIsArray($account);
        $this->assertNotEmpty($account['public_key']);
        $this->assertNotEmpty($account['private_key']);
        $this->assertGreaterThanOrEqual($start_time, $account['date_created']);
        $this->assertGreaterThanOrEqual(1, $account['id']);

        // store to clean-up
        $this->createdIds[] = $id;
    }

    public function testLoadByPublicKeyBase58(): void
    {
        $openSsl = new OpenSsl();
        $transferEncoding = new TransferEncoding();

        $start_time = time();
        $id = $this->account->create();
        $this->assertGreaterThanOrEqual(1, $id);

        $xAccount = $this->account->get($id);
        $publicKey = $xAccount['public_key'];
        $publicKeyRaw = $openSsl->stripPem($publicKey);

        $account = $this->account->getByPublicKeyRaw($publicKeyRaw);
        $this->assertIsArray($account);
        $this->assertNotEmpty($account['public_key']);
        $this->assertNotEmpty($account['private_key']);
        $this->assertGreaterThanOrEqual($start_time, $account['date_created']);
        $this->assertGreaterThanOrEqual(1, $account['id']);

        // store to clean-up
        $this->createdIds[] = $id;

    }

    public function testDestroyAccount(): void
    {
        $id = $this->account->create();
        $this->assertGreaterThanOrEqual(1, $id);

        $account = $this->account->get($id);
        $this->assertEquals($id, $account['id']);

        $this->assertTrue(
            $this->account->delete((int)$account['id'])
        );

    }
}
