<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class AddressTest extends TestCase
{
    private Address $address;
    private Account $account;
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->account = new Account();
        $this->address = new Address();
        $this->openssl = new OpenSsl();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $this->account->delete($id);
        }
    }

    public function testGenerateAddress(): void
    {
        $id = $this->account->create();
        $this->createdIds[] = $id;

        $account = $this->account->get($id);
        $address = $this->address->create($account['public_key']);
        $this->assertNotEmpty($address);

        $address2 = $this->address->create($account['public_key']);
        $this->assertNotEmpty($address2);

        $this->assertEquals($address, $address2);

        $this->assertEquals('Bc', substr($address, 0, 2));
    }

    public function testValidateAddress(): void
    {
        $id = $this->account->create();
        $account = $this->account->get($id);
        $this->createdIds[] = $id;

        // reg address
        $address = $this->address->create($account['public_key']);
        $this->assertNotEmpty($address);
        $this->assertTrue($this->address->validateAddress($address));
    }
}
