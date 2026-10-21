<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PeerTest extends TestCase
{
    private PDO $db;
    private Peer $peer;

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->db->beginTransaction();
        $this->peer = new Peer();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public static function badAddressProvider(): array
    {
        return [
            ['999.999.999.999:7747'],
            ['10.0.0.1:0'],
            ['10.0.0.1:70000'],
            ['10.0.0.1'],
            ['example.com:7747'],
            ['10.0.0.1:7747; DROP TABLE peers'],
            [''],
        ];
    }

    #[DataProvider('badAddressProvider')]
    public function testBadAddressIsRefused(string $address): void
    {
        $this->assertEquals(0, $this->peer->add($address));
        $this->assertNull($this->peer->getByHostAddress($address));
    }

    public function testAddAndFind(): void
    {
        $id = $this->peer->add('10.1.2.3:7747');
        $peer = $this->peer->getByHostAddress('10.1.2.3:7747');

        $this->assertGreaterThan(0, $id);
        $this->assertEquals($id, (int)$peer['id']);
        $this->assertEquals(0, (int)$peer['blacklisted']);

        // adding again changes nothing
        $this->assertEquals(0, $this->peer->add('10.1.2.3:7747'));
    }

    public function testPeerListLeavesOutBlacklistedAndFailingPeers(): void
    {
        $this->peer->add('10.1.2.3:7747');
        $this->peer->add('10.1.2.4:7747');
        $this->peer->addBlackList('10.1.2.5:7747');

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->peer->incrementFails('10.1.2.4:7747'));
        }

        $addresses = array_column($this->peer->getAll(), 'address');
        $this->assertContains('10.1.2.3:7747', $addresses);
        $this->assertNotContains('10.1.2.4:7747', $addresses);
        $this->assertNotContains('10.1.2.5:7747', $addresses);

        $this->assertTrue($this->peer->clearFails('10.1.2.4:7747'));
        $this->assertContains('10.1.2.4:7747', array_column($this->peer->getAll(), 'address'));
    }

    public function testPingTimeIsUpdated(): void
    {
        $this->peer->add('10.1.2.3:7747');
        $this->db->exec("UPDATE peers SET last_ping=1 WHERE address='10.1.2.3:7747'");

        $this->assertTrue($this->peer->updatePingTime('10.1.2.3:7747'));
        $this->assertGreaterThanOrEqual(time() - 5, (int)$this->peer->getByHostAddress('10.1.2.3:7747')['last_ping']);
    }

    public function testDelete(): void
    {
        $id = $this->peer->add('10.1.2.3:7747');

        $this->assertTrue($this->peer->delete($id));
        $this->assertNull($this->peer->get($id));
    }

    public function testUniquePeerIdIsStableAndUsableInTransactions(): void
    {
        $id = $this->peer->getUniquePeerId();

        $this->assertEquals($id, $this->peer->getUniquePeerId());
        $this->assertTrue(ctype_alnum($id));
        $this->assertLessThanOrEqual(40, strlen($id));
    }
}
