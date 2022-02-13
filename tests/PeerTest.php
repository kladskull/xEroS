<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class PeerTest extends TestCase
{
    private Peer $peer;
    private array $createdIds = [];

    protected function setUp(): void
    {
        $this->peer = new Peer();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $this->peer->delete($id);
        }
    }

    /**
     * @throws Exception
     */
    public function testAdd(): void
    {
        $address = "http://1.2.3.4/" . md5(random_bytes(16));
        $id = $this->peer->add($address, false);
        $this->createdIds[] = $id;
        $this->assertGreaterThan(0, $id);
    }

    /**
     * @throws Exception
     */
    public function testAddNotBlackListed(): void
    {
        $address = "http://1.2.3.4/" . md5(random_bytes(16));
        $id = $this->peer->add($address, false);
        $this->createdIds[] = $id;
        $this->assertGreaterThan(0, $id);

        $peer = $this->peer->get($id);
        $this->assertNotEmpty($peer['address']);
        $this->assertGreaterThan(time() - 60, $peer['date_created']);
        $this->assertEquals(0, (int)$peer['fails']);
        $this->assertEquals(0, (int)$peer['blacklisted']);
        $this->assertEquals(0, (int)$peer['last_ping']);
        $this->assertEquals(0, (int)$peer['reserve']);
    }

    /**
     * @throws Exception
     */
    public function testAddBlackListed(): void
    {
        $address = "http://1.2.3.4/" . md5(random_bytes(16));
        $id = $this->peer->add($address, true);
        $this->createdIds[] = $id;
        $this->assertGreaterThan(0, $id);

        $peer = $this->peer->get($id);
        $this->assertNotEmpty($peer['address']);
        $this->assertGreaterThan(time() - 60, $peer['date_created']);
        $this->assertEquals(0, $peer['fails']);
        $this->assertEquals(1, $peer['blacklisted']);
        $this->assertEquals(0, $peer['last_ping']);
        $this->assertEquals(0, $peer['reserve']);
    }

    /**
     * @throws Exception
     */
    public function testAddAsReserve(): void
    {
        // have to clear the table
        foreach ($this->createdIds as $id) {
            $this->peer->delete($id);
        }

        // create a ton of peers
        for ($i = 0; $i < Config::getMaxPeers() + 5; $i++) {
            $address = "http://1.2.3.4/" . md5(random_bytes(16));
            $id = $this->peer->add($address, false);
            $this->peer->updatePingTime($address);
            $this->createdIds[] = $id;
            $this->assertGreaterThan(0, $id);
        }

        // create our reserve
        $address = "http://1.2.3.4/" . md5(random_bytes(16));
        $id = $this->peer->add($address, false);
        $this->createdIds[] = $id;
        $this->assertGreaterThan(0, $id);
        $this->peer->updatePingTime($address);

        $peer = $this->peer->get($id);
        $this->assertNotEmpty($peer['address']);
        $this->assertGreaterThan(time() - 60, (int)$peer['date_created']);
        $this->assertEquals(0, (int)$peer['fails']);
        $this->assertEquals(0, (int)$peer['blacklisted']);
        $this->assertGreaterThan(time() - 8000, (int)$peer['last_ping']);
        $this->assertEquals(1, (int)$peer['reserve']);
    }

    /**
     * @throws Exception
     */
    public function testAddAsReserveNoActiveHosts(): void
    {
        // have to clear the table
        foreach ($this->createdIds as $id) {
            $this->peer->delete($id);
        }

        // create a ton of peers
        for ($i = 0; $i < Config::getMaxPeers() + 5; $i++) {
            $address = "http://1.2.3.4/" . md5(random_bytes(16));
            $id = $this->peer->add($address, false);
            $this->createdIds[] = $id;
            $this->assertGreaterThan(0, $id);
        }

        // create our reserve
        $address = "http://1.2.3.4/" . md5(random_bytes(16));
        $id = $this->peer->add($address, false);
        $this->createdIds[] = $id;
        $this->assertGreaterThan(0, $id);

        $peer = $this->peer->get($id);
        $this->assertNotEmpty($peer['address']);
        $this->assertGreaterThan(time() - 60, (int)$peer['date_created']);
        $this->assertEquals(0, (int)$peer['fails']);
        $this->assertEquals(0, (int)$peer['blacklisted']);
        $this->assertEquals(0, (int)$peer['last_ping']);
        $this->assertEquals(0, (int)$peer['reserve']);
    }

    /**
     * @throws Exception
     */
    public function testNotAddedAsBlacklistNoReserve(): void
    {
        // have to clear the table
        foreach ($this->createdIds as $id) {
            $this->peer->delete($id);
        }

        // create a ton of peers
        for ($i = 0; $i < Config::getMaxPeers() + 5; $i++) {
            $address = "http://1.2.3.4/" . md5(random_bytes(16));
            $id = $this->peer->add($address, true);
            $this->createdIds[] = $id;
            $this->assertGreaterThan(0, $id);
        }

        // create our reserve
        $address = "http://1.2.3.4/" . md5(random_bytes(16));
        $id = $this->peer->add($address, true);
        $this->createdIds[] = $id;
        $this->assertGreaterThan(0, $id);

        $peer = $this->peer->get($id);
        $this->assertNotEmpty($peer['address']);
        $this->assertGreaterThan(time() - 60, (int)$peer['date_created']);
        $this->assertEquals(0, (int)$peer['fails']);
        $this->assertEquals(1, (int)$peer['blacklisted']);
        $this->assertEquals(0, (int)$peer['last_ping']);
        $this->assertEquals(0, (int)$peer['reserve']);
    }

    /**
     * @throws Exception
     */
    public function testAddAndFail(): void
    {
        $address = "http://1.2.3.4/" . md5(random_bytes(16));
        $id = $this->peer->add($address, false);
        $this->createdIds[] = $id;
        $this->assertGreaterThan(0, $id);

        $peer = $this->peer->get($id);
        $this->assertEquals(0, (int)$peer['fails']);

        $this->peer->incrementFails($peer['address']);
        $peer = $this->peer->get($id);
        $this->assertEquals(1, (int)$peer['fails']);

        $this->peer->incrementFails($peer['address']);
        $peer = $this->peer->get($id);
        $this->assertEquals(2, (int)$peer['fails']);
    }

    /**
     * @throws Exception
     */
    public function testClearFails(): void
    {
        $address = "http://1.2.3.4/" . md5(random_bytes(16));
        $id = $this->peer->add($address, false);
        $this->createdIds[] = $id;
        $this->assertGreaterThan(0, $id);

        $peer = $this->peer->get($id);
        $this->assertEquals(0, (int)$peer['fails']);

        $this->peer->incrementFails($peer['address']);
        $peer = $this->peer->get($id);
        $this->assertEquals(1, (int)$peer['fails']);

        $this->peer->clearFails($address);
        $peer = $this->peer->get($id);
        $this->assertEquals(0, (int)$peer['fails']);
    }

    /**
     * @throws JsonException
     */
    public function testPingPeer(): void
    {
        $http = new Http();
        $response = $http->get(Config::getTestNodeUrl() . '/ping.php');
        $this->assertTrue(Api::validateResponse($response));
    }
}
