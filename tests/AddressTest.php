<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

class AddressTest extends TestCase
{
    private Address $address;
    private Account $account;
    private OpenSsl $openssl;
    private TransferEncoding $transferEncoding;
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->account = new Account();
        $this->address = new Address();
        $this->openssl = new OpenSsl();
        $this->transferEncoding = new TransferEncoding();
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
        $address2 = $this->address->create($account['public_key']);

        $this->assertNotEmpty($address);
        $this->assertNotEmpty($address2);
        $this->assertEquals($address, $address2);
        $this->assertEquals(Config::getAddressHeader(), substr($address, 0, 2));
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

    /**
     * Builds an address from raw parts with a correct checksum.
     */
    private function build(string $versionHex, string $body): string
    {
        $raw = hex2bin($versionHex) . $body;

        return Config::getAddressHeader()
            . $this->transferEncoding->binToBase58($raw . $this->address->getAddressChecksum($raw));
    }

    private function newAddress(): string
    {
        $keys = $this->openssl->createRsaKeyPair();

        return $this->address->create($keys['public_key']);
    }

    public function testBuiltAddressMatchesCreate(): void
    {
        $keys = $this->openssl->createRsaKeyPair();
        $body = hash('ripemd160', $this->address->createPartial($keys['public_key']), true);

        $this->assertEquals(
            $this->address->create($keys['public_key']),
            $this->build(Config::getChainVersion(), $body)
        );
    }

    public function testAddressFromAnotherChainIsRejected(): void
    {
        $body = random_bytes(20);

        $this->assertTrue($this->address->validateAddress($this->build(Config::getChainVersion(), $body)));
        $this->assertFalse($this->address->validateAddress($this->build('01', $body)));
    }

    public function testWrongLengthIsRejected(): void
    {
        $this->assertFalse($this->address->validateAddress($this->build(Config::getChainVersion(), random_bytes(19))));
        $this->assertFalse($this->address->validateAddress($this->build(Config::getChainVersion(), random_bytes(21))));
        $this->assertFalse($this->address->validateAddress($this->build(Config::getChainVersion(), '')));
    }

    public function testWrongHeaderIsRejected(): void
    {
        $address = $this->newAddress();

        $this->assertFalse($this->address->validateAddress('Bc' . substr($address, 2)));
        $this->assertFalse($this->address->validateAddress(substr($address, 2)));
    }

    public function testChangedCharacterIsRejected(): void
    {
        $address = $this->newAddress();
        $last = $address[-1];
        $changed = substr($address, 0, -1) . ($last === 'z' ? 'y' : 'z');

        $this->assertFalse($this->address->validateAddress($changed));
    }

    public function testGarbageIsRejectedWithoutThrowing(): void
    {
        foreach (['', 'Xa', 'Xa0OIl', 'Xa!!!', Config::getAddressHeader() . str_repeat('z', 200)] as $bad) {
            $this->assertFalse($this->address->validateAddress($bad), $bad);
        }
    }

    public function testChecksumIsFourBytesOfDoubleSha256(): void
    {
        $raw = hex2bin('00') . random_bytes(20);
        $expected = substr(hash('sha256', hash('sha256', $raw, true), true), 0, 4);

        $this->assertSame($expected, $this->address->getAddressChecksum($raw));
        $this->assertSame(4, strlen($this->address->getAddressChecksum($raw)));
    }
}
