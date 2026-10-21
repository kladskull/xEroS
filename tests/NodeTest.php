<?php declare(strict_types=1);

namespace Blockchain;

use PHPUnit\Framework\TestCase;

/**
 * Runs a real node on a copy of the database and talks to it over a socket.
 */
class NodeTest extends TestCase
{
    private static $process = null;
    private static string $dbFile = '';
    private static string $logFile = '';
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$dbFile = tempnam(sys_get_temp_dir(), 'xero-node-test-');
        self::$logFile = self::$dbFile . '.log';
        unlink(self::$dbFile);
        Database::getInstance()->exec('VACUUM INTO ' . Database::getInstance()->quote(self::$dbFile));

        // a free port
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($probe, false);
        self::$port = (int)substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        $env = getenv();
        $env['DB_FILE'] = self::$dbFile;
        $env['SEED_PEERS'] = '';
        $env['BAN_SECONDS'] = '2';
        self::$process = proc_open(
            [PHP_BINARY, 'xero.php', '127.0.0.1', (string)self::$port],
            [0 => ['pipe', 'r'], 1 => ['file', self::$logFile, 'a'], 2 => ['file', self::$logFile, 'a']],
            $pipes,
            APP_DIR,
            $env
        );

        for ($i = 0; $i < 100; $i++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . self::$port, $code, $message, 1);

            if ($socket !== false) {
                fclose($socket);

                return;
            }

            usleep(100000);
        }

        self::fail('the node did not start: ' . @file_get_contents(self::$logFile));
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }

        foreach ([self::$dbFile, self::$logFile] as $file) {
            if ($file !== '' && file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function connect()
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::$port, $code, $message, 5);
        $this->assertNotFalse($socket, $message);
        stream_set_blocking($socket, false);

        return $socket;
    }

    private function send($socket, array|string $packet): void
    {
        $line = is_array($packet) ? json_encode($packet, JSON_THROW_ON_ERROR) : $packet;
        fwrite($socket, $line . "\n");
    }

    /**
     * Reads packets until one of $type arrives. Returns null on timeout or when the node hangs up.
     */
    private function waitFor($socket, string $type, float $timeout = 5.0): ?array
    {
        static $buffers = [];
        $id = (int)$socket;
        $buffers[$id] ??= '';
        $end = microtime(true) + $timeout;

        while (microtime(true) < $end) {
            while (($newline = strpos($buffers[$id], "\n")) !== false) {
                $line = substr($buffers[$id], 0, $newline);
                $buffers[$id] = substr($buffers[$id], $newline + 1);
                $data = json_decode($line, true);

                if (is_array($data) && ($data['type'] ?? '') === $type) {
                    return $data;
                }
            }

            $read = [$socket];
            $write = $except = null;

            if (@stream_select($read, $write, $except, 0, 100000) > 0) {
                $chunk = fread($socket, 65536);

                if ($chunk === '' || $chunk === false) {
                    return null;
                }

                $buffers[$id] .= $chunk;
            }
        }

        return null;
    }

    private function isClosed($socket, float $timeout = 5.0): bool
    {
        $end = microtime(true) + $timeout;

        while (microtime(true) < $end) {
            $read = [$socket];
            $write = $except = null;

            if (@stream_select($read, $write, $except, 0, 100000) > 0) {
                $chunk = @fread($socket, 65536);

                if ($chunk === '' || $chunk === false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A connection that has finished the handshake.
     */
    private function ready()
    {
        $socket = $this->connect();
        $this->send($socket, ['type' => 'handshake', 'network' => Config::getNetworkIdentifier(), 'version' => Config::getVersion()]);
        $this->send($socket, ['type' => 'handshake_ok']);
        $this->assertNotNull($this->waitFor($socket, 'handshake_ok'));

        return $socket;
    }

    public function testHandshake(): void
    {
        $socket = $this->connect();
        $this->send($socket, ['type' => 'handshake', 'network' => Config::getNetworkIdentifier(), 'version' => Config::getVersion()]);

        $handshake = $this->waitFor($socket, 'handshake');
        $this->assertEquals(Config::getNetworkIdentifier(), $handshake['network']);
        $this->assertNotNull($this->waitFor($socket, 'handshake_ok'));
    }

    public function testOtherNetworkIsRefused(): void
    {
        $socket = $this->connect();
        $this->send($socket, ['type' => 'handshake', 'network' => 'xx99', 'version' => Config::getVersion()]);

        $error = $this->waitFor($socket, 'error');
        $this->assertStringContainsString('wrong network', $error['message']);
        $this->assertTrue($this->isClosed($socket));
    }

    public function testOlderVersionIsRefused(): void
    {
        $socket = $this->connect();
        $this->send($socket, ['type' => 'handshake', 'network' => Config::getNetworkIdentifier(), 'version' => '0.00001']);

        $this->assertNotNull($this->waitFor($socket, 'error'));
        $this->assertTrue($this->isClosed($socket));
    }

    public function testRequestsBeforeTheHandshakeAreIgnored(): void
    {
        $socket = $this->connect();
        $this->send($socket, ['type' => 'height_req']);

        $this->assertNull($this->waitFor($socket, 'height', 1.0));
    }

    public function testHeightAndBlockRequests(): void
    {
        $socket = $this->ready();
        $genesis = (new Block())->getByHeight(1);

        $this->send($socket, ['type' => 'height_req']);
        $this->assertEquals(1, $this->waitFor($socket, 'height')['height']);

        $this->send($socket, ['type' => 'block_req', 'height' => 1]);
        $block = $this->waitFor($socket, 'block')['block'];
        $this->assertEquals($genesis['block_id'], $block['block_id']);
        $this->assertCount(1, $block['transactions']);

        $this->send($socket, ['type' => 'block_req', 'block_id' => $genesis['block_id']]);
        $this->assertEquals($genesis['block_id'], $this->waitFor($socket, 'block')['block']['block_id']);
    }

    public function testUnknownBlockGetsNoAnswer(): void
    {
        $socket = $this->ready();
        $this->send($socket, ['type' => 'block_req', 'block_id' => str_repeat('ab', 32)]);
        $this->send($socket, ['type' => 'ping']);

        // the pong comes back but no block came before it
        $this->assertNotNull($this->waitFor($socket, 'pong'));
        $this->send($socket, ['type' => 'block_req', 'height' => 999999]);
        $this->assertNull($this->waitFor($socket, 'block', 1.0));
    }

    public function testPingVersionAndMempoolSize(): void
    {
        $socket = $this->ready();

        $this->send($socket, ['type' => 'ping']);
        $this->assertNotNull($this->waitFor($socket, 'pong'));

        $this->send($socket, ['type' => 'version_req']);
        $this->assertEquals(Config::getVersion(), $this->waitFor($socket, 'version')['version']);

        $this->send($socket, ['type' => 'mempool_size_req']);
        $this->assertSame(0, $this->waitFor($socket, 'mempool_size')['mempool_size']);
    }

    public function testMiningInfo(): void
    {
        $socket = $this->ready();
        $block = new Block();
        $genesis = $block->getByHeight(1);

        $this->send($socket, ['type' => 'mining_info_req']);
        $info = $this->waitFor($socket, 'mining_info');

        $this->assertEquals(Config::getNetworkIdentifier(), $info['network_id']);
        $this->assertEquals($genesis['block_id'], $info['block_id']);
        $this->assertEquals($block->getNextDifficulty($genesis), $info['difficulty']);
    }

    public function testBadTransactionIsRefused(): void
    {
        $socket = $this->ready();
        $this->send($socket, ['type' => 'mempool_transaction', 'transaction' => ['transaction_id' => 'nope']]);
        $result = $this->waitFor($socket, 'mempool_transaction_result');

        $this->assertFalse($result['accepted']);
        $this->assertEquals('nope', $result['transaction_id']);

        $this->send($socket, ['type' => 'mempool_transaction', 'transaction' => 'not an array']);
        $this->assertFalse($this->waitFor($socket, 'mempool_transaction_result')['accepted']);
    }

    public function testGarbageDoesNotStopTheNode(): void
    {
        $socket = $this->ready();
        $garbage = [
            'not json',
            '{"no_type":1}',
            '{"type":["array"]}',
            '{"type":"block","block":"x"}',
            '{"type":"block","block":{"height":"x","block_id":[]}}',
            '{"type":"peer_list","peers":"x"}',
            '{"type":"peer_list","peers":[1,{"address":[]}]}',
            '{"type":"error","message":{"a":1}}',
            '{"type":"peer_invite_resp"}',
            '{"type":"version"}',
            '{"type":"height"}',
            '{"type":"mempool_size"}',
            '{"type":"unknown_thing"}',
            '[1,2,3]',
        ];

        foreach ($garbage as $line) {
            $this->send($socket, $line);
        }

        $this->send($socket, ['type' => 'ping']);
        $this->assertNotNull($this->waitFor($socket, 'pong'));
    }

    public function testPacketSplitAcrossWritesIsReassembled(): void
    {
        $socket = $this->ready();
        $packet = json_encode(['type' => 'version_req']);

        fwrite($socket, substr($packet, 0, 5));
        usleep(300000);
        fwrite($socket, substr($packet, 5) . "\n");

        $this->assertNotNull($this->waitFor($socket, 'version'));
    }

    public function testOversizedPacketClosesOnlyThatConnection(): void
    {
        $socket = $this->ready();
        stream_set_blocking($socket, true);
        $chunk = str_repeat('a', 65536);

        for ($i = 0; $i < 70 && @fwrite($socket, $chunk) !== false; $i++) {
        }

        stream_set_blocking($socket, false);
        $this->assertTrue($this->isClosed($socket));

        // the node still serves everyone else
        $other = $this->ready();
        $this->send($other, ['type' => 'ping']);
        $this->assertNotNull($this->waitFor($other, 'pong'));
    }

    public function testPeerInviteAndPeerList(): void
    {
        $socket = $this->ready();

        $this->send($socket, ['type' => 'peer_invite_req', 'port' => 17999]);
        $this->assertEquals('ok', $this->waitFor($socket, 'peer_invite_resp')['result']);

        $this->send($socket, ['type' => 'peer_list_req']);
        $addresses = array_column($this->waitFor($socket, 'peer_list')['peers'], 'address');
        $this->assertContains('127.0.0.1:17999', $addresses);

        $this->send($socket, ['type' => 'peer_invite_req', 'port' => 70000]);
        $this->assertEquals('nok', $this->waitFor($socket, 'peer_invite_resp')['result']);
    }

    public function testBlocksAreSentInRuns(): void
    {
        // bare blocks 2 to 61 in the node's database, removed again at the end
        $copy = new \PDO('sqlite:' . self::$dbFile);
        $genesis = (new Block())->getByHeight(1);
        $stmt = $copy->prepare(
            'INSERT INTO blocks (`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,' .
            '`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan`,`accepted`) VALUES ' .
            "(:n,:b,:p,1,:h,'0',16,'',0,'','',0,1)"
        );
        $previous = $genesis['block_id'];

        for ($height = 2; $height <= 61; $height++) {
            $stmt->execute([':n' => Config::getNetworkIdentifier(), ':b' => 'run' . $height, ':p' => $previous, ':h' => $height]);
            $previous = 'run' . $height;
        }

        try {
            $socket = $this->ready();

            $this->send($socket, ['type' => 'block_req', 'height' => 2, 'count' => 5]);
            $heights = [];

            for ($i = 0; $i < 5; $i++) {
                $heights[] = (int)$this->waitFor($socket, 'block')['block']['height'];
            }

            $this->assertEquals([2, 3, 4, 5, 6], $heights);

            // capped at 50, and it stops at the tip
            $this->send($socket, ['type' => 'block_req', 'height' => 2, 'count' => 500]);
            $received = 0;

            while ($this->waitFor($socket, 'block', 1.0) !== null) {
                $received++;
            }

            $this->assertEquals(50, $received);

            $this->send($socket, ['type' => 'block_req', 'height' => 58, 'count' => 50]);
            $received = 0;

            while ($this->waitFor($socket, 'block', 1.0) !== null) {
                $received++;
            }

            $this->assertEquals(4, $received);
        } finally {
            $copy->exec("DELETE FROM blocks WHERE block_id LIKE 'run%'");
        }
    }

    public function testLogShowsPacketTypesNotContents(): void
    {
        $socket = $this->ready();
        $this->send($socket, ['type' => 'block_req', 'height' => 1]);
        $this->waitFor($socket, 'block');

        $log = (string)file_get_contents(self::$logFile);
        $this->assertMatchesRegularExpression('/SEND: block \(\d+ bytes\)/', $log);
        $this->assertStringNotContainsString('"transactions"', $log);
    }

    public function testTooManyConnectionsFromOneAddressAreRefused(): void
    {
        $open = [];

        for ($i = 0; $i < 8; $i++) {
            $open[] = $this->ready();
        }

        $extra = $this->connect();
        $this->assertTrue($this->isClosed($extra, 2.0));

        // once some close there is room again
        foreach ($open as $socket) {
            fclose($socket);
        }

        usleep(500000);
        $socket = $this->ready();
        $this->send($socket, ['type' => 'ping']);
        $this->assertNotNull($this->waitFor($socket, 'pong'));
    }

    public function testPeerSendingBadDataIsBanned(): void
    {
        $socket = $this->ready();

        for ($i = 0; $i < 10; $i++) {
            $this->send($socket, '{not json');
        }

        $this->assertTrue($this->isClosed($socket));

        // a new connection from the same address is refused while the ban lasts
        $again = $this->connect();
        $this->assertTrue($this->isClosed($again, 2.0));

        // the test node bans for 2 seconds
        sleep(3);
        $socket = $this->ready();
        $this->send($socket, ['type' => 'ping']);
        $this->assertNotNull($this->waitFor($socket, 'pong'));
    }

    public function testAFewHonestMistakesDoNotBan(): void
    {
        foreach (range(1, 3) as $i) {
            $socket = $this->ready();
            $this->send($socket, '{not json');
            $this->send($socket, ['type' => 'mempool_transaction', 'transaction' => 'x']);
            $this->send($socket, ['type' => 'ping']);
            $this->assertNotNull($this->waitFor($socket, 'pong'), 'connection ' . $i);
            fclose($socket);
        }
    }

    public function testNodeLoggedNoFatalErrors(): void
    {
        $socket = $this->ready();
        $this->send($socket, ['type' => 'ping']);
        $this->waitFor($socket, 'pong');

        $log = (string)file_get_contents(self::$logFile);
        $this->assertDoesNotMatchRegularExpression('/Fatal error|Uncaught|Warning:|Deprecated:/', $log);
    }
}
