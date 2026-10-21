<?php declare(strict_types=1);

namespace Blockchain;

use JetBrains\PhpStorm\NoReturn;
use JsonException;
use Throwable;

/**
 * Class Console
 * @package Blockchain
 */
class Node
{
    private Peer $peer;
    private Block $block;
    private Queue $queue;
    private Mempool $mempool;
    private DataStore $dataStore;

    public const SYNCING = 'sync';
    public const MINING = 'mine';

    // the largest packet a peer may send before a newline, anything bigger closes the connection
    private const MAX_PACKET_SIZE = 4194304;
    private const MAX_PEER_LIST = 100;
    private const MAX_PENDING_BLOCKS = 500;
    private const SYNC_REQUEST_TIMEOUT = 30;
    private const DIAL_INTERVAL = 10;
    private const SOLO_GRACE_PERIOD = 30;

    // blocks whose parent we have not seen yet, by block id
    private array $pendingBlocks = [];

    // the block request we are waiting on while syncing
    private ?array $syncRequest = null;
    private string $state = '';
    private int $startTime = 0;

    private array $clientInfoArray = [
        'version' => '0',
        'init' => 0,
        'connect_time' => 0,
        'last_ping' => 0,
        'current_height' => 0,
        'last_height' => 0,
        'mempool_size' => 0,

    ];

    public function __construct()
    {
        $this->peer = new Peer();
        $this->block = new Block();
        $this->mempool = new Mempool();
        $this->dataStore = new DataStore();
        $this->queue = new Queue();
    }

    /**
     * @param $client
     * @param string $data
     * @return void
     */
    #[NoReturn]
    public function send($client, string $data): void
    {
        // add a newline for back-to-back sends
        Console::log('SEND: ' . trim($data));
        $data = trim($data) . "\n";

        if (is_resource($client) && fwrite($client, $data, strlen($data)) === false) {
            Console::log('Cannot write to socket');
        }
    }

    /**
     * @param $client
     * @return string
     */
    public function receive($client): string
    {
        // todo: add a loop with timeout
        $data = null;

        if (is_resource($client)) {
            $data = fread($client, 16384);
        }

        return $data;
    }

    /**
     * @param $client
     * @throws JsonException
     * @return void
     */
    #[NoReturn]
    public function sendHandshake($client): void
    {
        Console::log('Sending handshake request to peer');
        $packet = [
            'type' => 'handshake',
            'network' => Config::getNetworkIdentifier(),
            'version' => Config::getVersion(),
        ];
        $this->send($client, json_encode($packet, JSON_THROW_ON_ERROR));
    }

    /**
     * @param $data
     * @throws JsonException
     * @return array
     */
    public function parseResponse($data): array
    {
        $packets = [];
        $tokens = explode(PHP_EOL, $data);

        foreach ($tokens as $token) {
            $packets[] = json_decode(trim($token), true, 512, JSON_THROW_ON_ERROR);
        }

        return $packets;
    }

    /**
     * @param string $address
     * @param int $port
     * @return void
     */
    #[NoReturn]
    public function listen(string $address, int $port): void
    {
        Console::log('Opening a server socket on address: ' . $address . ' port: ' . $port);
        $sock = stream_socket_server(
            "tcp://$address:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        ) or die("Cannot create socket.\n");
        stream_set_blocking($sock, false);

        $port1 = $port;
        $server = $sock;
        $clients = [$sock];
        $clientInfo = [];
        $buffers = [];
        $outbound = []; // client key => peer address, for connections we opened
        $lastDial = 0;
        $this->startTime = time();

        while (1) {
            $read = $clients;
            $write = null;
            $except = null;

            // Set up a non-blocking call to socket_select
            $read[] = $sock;
            $reads = stream_select($read, $write, $except, 0, 250000);

            if ($reads > 0) {
                // check if there is a client trying to connect
                if (in_array($sock, $read)) {
                    //$newSocket = socket_accept($sock);
                    $newSocket = stream_socket_accept($sock);
                    $clients[] = $newSocket;

                    Console::log('New peer connection');

                    // remove the listening socket from the clients-with-data array
                    $key = array_search($sock, $read);
                    unset($read[$key]);
                    continue;
                }

                // do some work/get local state

                // Handle Input
                foreach ($clients as $key => $client) { // for each client
                    if (in_array($client, $read, true)) {
                        $chunk = fread($client, 8192);

                        if ($chunk === '' || $chunk === false) {
                            Console::log('Client disconnected');
                            fclose($client);
                            unset($clientInfo[$key], $clients[$key], $buffers[$key]);
                            continue;
                        }

                        // packets are one json object per line, a packet may arrive over several reads
                        $buffers[$key] = ($buffers[$key] ?? '') . $chunk;

                        if (strlen($buffers[$key]) > self::MAX_PACKET_SIZE) {
                            Console::log('Closing client for sending an oversized packet ID: ' . $key);
                            fclose($client);
                            unset($clientInfo[$key], $clients[$key], $buffers[$key]);
                            continue;
                        }

                        while (isset($clients[$key]) && ($newline = strpos($buffers[$key], "\n")) !== false) {
                            $line = trim(substr($buffers[$key], 0, $newline));
                            $buffers[$key] = substr($buffers[$key], $newline + 1);

                            if ($line === '') {
                                continue;
                            }

                            // a bad packet from one peer must never stop the node
                            try {
                                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

                                if (is_array($data) && isset($data['type'])) {
                                    // a connection can send data before the main loop has set it up, set it up now so nothing is lost
                                    if (!isset($clientInfo[$key])) {
                                        $clientInfo[$key] = $this->clientInfoArray;
                                        $clientInfo[$key]['last_ping'] = time();
                                        $this->sendHandshake($client);
                                    }

                                    // until both sides have shaken hands only these commands are handled
                                    if ($clientInfo[$key]['init'] < 2) {
                                        switch ($data['type']) {
                                            case 'handshake':
                                                Console::log('Received handshake');
                                                $network = (string)($data['network'] ?? '');
                                                $version = (string)($data['version'] ?? '');

                                                if (Config::getNetworkIdentifier() !== $network
                                                    || !is_numeric($version) || bccomp($version, Config::getVersion(), 4) < 0) {
                                                    Console::log('Incompatible client');
                                                    $this->send($client, json_encode(
                                                        ['type' => 'error', 'message' => 'wrong network id or incompatible version'],
                                                        JSON_THROW_ON_ERROR
                                                    ));
                                                    fclose($client);
                                                    unset($clientInfo[$key], $clients[$key], $buffers[$key]);
                                                    break;
                                                }

                                                $clientInfo[$key]['network'] = $network;
                                                $clientInfo[$key]['version'] = $version;
                                                $clientInfo[$key]['got_handshake'] = true;
                                                $this->send($client, json_encode(['type' => 'handshake_ok'], JSON_THROW_ON_ERROR));
                                                break;

                                            case 'handshake_ok':
                                                Console::log('Received positive handshake response');
                                                $clientInfo[$key]['handshake_acked'] = true;
                                                break;

                                            case 'error':
                                                Console::log('Received error: ' . (string)($data['message'] ?? ''));
                                                break;

                                            default:
                                                // can arrive just before the handshake finishes, it is safe to drop
                                                Console::log('Ignoring ' . $data['type'] . ' before the handshake from: ' . $key);
                                                break;
                                        }

                                        // ready once we accepted their handshake and they accepted ours
                                        if (isset($clientInfo[$key]) && !empty($clientInfo[$key]['got_handshake'])
                                            && !empty($clientInfo[$key]['handshake_acked'])) {
                                            $clientInfo[$key]['init'] = 2;

                                            // request a peer list
                                            $this->send($client, json_encode([
                                                'type' => 'peer_list_req'
                                            ], JSON_THROW_ON_ERROR));

                                            // send our peer details
                                            $this->send($client, json_encode([
                                                'type' => 'peer_invite_req',
                                                'port' => $port1,
                                            ], JSON_THROW_ON_ERROR));
                                        }

                                        continue;
                                    }

                                    // only allow initialized connections for these commands
                                    if (isset($clientInfo[$key]['init']) && $clientInfo[$key]['init'] >= 2) {
                                        switch ($data['type']) {

                                            /**
                                             * Blockchain
                                             */
                                            case 'block_resp':
                                                Console::log('Received a block response from: ' . $key);
                                                $clientInfo[$key]['block'] = $data['result'];
                                                break;

                                            case 'block':
                                                Console::log('Received a block from: ' . $key);
                                                $block = $data['block'] ?? null;

                                                if (!is_array($block) || empty($block)) {
                                                    break;
                                                }

                                                $status = $this->handleBlock($block);

                                                $this->send($client, json_encode([
                                                    'type' => 'block_resp',
                                                    'result' => $status === 'invalid' ? 'nok' : 'ok',
                                                ], JSON_THROW_ON_ERROR));

                                                // ask the same peer for the parent we are missing
                                                if ($status === 'missing_parent') {
                                                    $this->send($client, json_encode([
                                                        'type' => 'block_req',
                                                        'block_id' => (string)$block['previous_block_id'],
                                                    ], JSON_THROW_ON_ERROR));
                                                    $this->syncRequest = ['key' => $key, 'time' => time()];
                                                    break;
                                                }

                                                // the answer we were waiting for has arrived
                                                if ($this->syncRequest !== null && $this->syncRequest['key'] === $key) {
                                                    $this->syncRequest = null;
                                                }

                                                // tell everyone else about a new tip
                                                if ($status === 'tip') {
                                                    $tip = $this->block->assembleFullBlock($this->block->getCurrent()['block_id']);

                                                    foreach ($clients as $c) {
                                                        if ($server === $c || $client === $c) {
                                                            continue;
                                                        }

                                                        $this->send($c, json_encode([
                                                            'type' => 'block',
                                                            'block' => $tip,
                                                        ], JSON_THROW_ON_ERROR));
                                                    }
                                                }
                                                break;

                                            case 'block_req':
                                                Console::log('Received a block request from: ' . $key);
                                                $blockId = $data['block_id'] ?? null;
                                                $height = (int)($data['height'] ?? 0);

                                                if (is_string($blockId) && $blockId !== '') {
                                                    $block = $this->block->getByBlockId($blockId);
                                                } elseif ($height !== 0) {
                                                    $block = $this->block->getByHeight($height);
                                                } else {
                                                    $block = $this->block->getCurrent();
                                                }

                                                // only answer with blocks we have
                                                if (!empty($block)) {
                                                    $this->send($client, json_encode([
                                                        'type' => 'block',
                                                        'block' => $this->block->assembleFullBlock($block['block_id'])
                                                    ], JSON_THROW_ON_ERROR));
                                                }
                                                break;

                                            case 'height_req':
                                                Console::log('Received a height request from: ' . $key);
                                                $this->send($client, json_encode([
                                                    'type' => 'height',
                                                    'height' => $this->block->getCurrentHeight(),
                                                ], JSON_THROW_ON_ERROR));
                                                break;

                                            case 'height':
                                                $height = $data['height'] ?: 0;
                                                Console::log('Received a height of ' . $height . ' request from: ' . $key);
                                                $clientInfo[$key]['height'] = $height;
                                                break;

                                            /**
                                             * Mempool
                                             */
                                            case 'mempool_size_req':
                                                Console::log('Received a mempool size request from: ' . $key);
                                                $currentHeight = $this->block->getCurrentHeight();
                                                $this->send($client, json_encode([
                                                    'type' => 'mempool_size',
                                                    'height' => $this->mempool->getMempoolCount($currentHeight)
                                                ], JSON_THROW_ON_ERROR));
                                                break;

                                            case 'mempool_size':
                                                Console::log('Received a mempool response from: ' . $key);
                                                $clientInfo[$key]['mempool_size'] = (int)$data['mempool_size'];
                                                break;

                                            case 'mempool_transaction':
                                                Console::log('Received a new mempool transaction from: ' . $key);
                                                $transaction = $data['transaction'] ?? null;

                                                // only relay transactions we accepted
                                                if (is_array($transaction) && $this->mempool->add($transaction)) {
                                                    foreach ($clients as $c) {
                                                        // don't send to self or current client
                                                        if ($server === $c || $client === $c) {
                                                            continue;
                                                        }

                                                        $this->send($c, json_encode([
                                                            'type' => 'mempool_transaction',
                                                            'transaction' => $transaction,
                                                        ], JSON_THROW_ON_ERROR));
                                                    }
                                                }

                                                break;

                                            /**
                                             * Mining
                                             */
                                            case 'mining_info_req':
                                                Console::log('Received a mining info request from: ' . $key);
                                                $currentBlock = $this->block->getCurrent();

                                                if ($currentBlock !== null) {
                                                    $state = $this->dataStore->getKey('state');

                                                    if (empty($state)) {
                                                        $this->dataStore->add('state', self::SYNCING);
                                                    }

                                                    $this->send($client, json_encode([
                                                        'type' => 'mining_info',
                                                        'network_id' => Config::getNetworkIdentifier(),
                                                        'block_id' => $currentBlock['block_id'],
                                                        'hash' => $currentBlock['hash'],
                                                        'height' => $this->block->getCurrentHeight(),
                                                        'recommendation' => $state,
                                                        'difficulty' => $this->block->getDifficulty(),
                                                    ], JSON_THROW_ON_ERROR));
                                                } else {
                                                    $this->send($client, json_encode([
                                                        'type' => 'mining_info',
                                                        'result' => 'nok'
                                                    ], JSON_THROW_ON_ERROR));
                                                }
                                                break;

                                            case 'mining_info':
                                                Console::log('Received mining word response');
                                                break;

                                            /**
                                             * Networking
                                             */
                                            case 'ping':
                                                Console::log('Received ping');
                                                $this->send($client, json_encode([
                                                    'type' => 'pong'
                                                ], JSON_THROW_ON_ERROR));

                                                // renew the connection timeout
                                                $clientInfo[$key]['last_ping'] = time();
                                                break;

                                            case 'pong':
                                                Console::log('Received pong');
                                                break;

                                            case 'version':
                                                Console::log('Received peer version');
                                                $clientInfo[$key]['version'] = $data['version'];
                                                break;

                                            case 'version_req':
                                                Console::log('Received version req');
                                                $this->send($client, json_encode([
                                                    'type' => 'version',
                                                    'version' => Config::getVersion()
                                                ], JSON_THROW_ON_ERROR));
                                                break;

                                            /**
                                             * Peering
                                             */
                                            case 'peer_list_req':
                                                Console::log('Received peer_list_req');
                                                $peerList = $this->peer->getAll();
                                                Console::log('Sent peer_list');
                                                $this->send($client, json_encode([
                                                    'type' => 'peer_list',
                                                    'peers' => $peerList
                                                ], JSON_THROW_ON_ERROR));
                                                break;

                                            case 'peer_invite_req':
                                                Console::log('Received peer_invite_req');
                                                $packet = null;

                                                // use the address we see the connection coming from, not what was sent
                                                $remote = (string)stream_socket_get_name($client, true);
                                                $peerAddress = substr($remote, 0, (int)strrpos($remote, ':'));
                                                $peerPort = (int)($data['port'] ?? 0);

                                                if ($peerAddress !== '' && filter_var($peerAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                                                    && $peerPort > 0 && $peerPort <= 65535) {
                                                    $hostAddress = $peerAddress . ':' . $peerPort;

                                                    if ($this->peer->getByHostAddress($hostAddress) === null) {
                                                        $this->peer->add($hostAddress);
                                                        Console::log('Sending response OK to peer_invite_req');
                                                    }

                                                    $packet = json_encode([
                                                        'type' => 'peer_invite_resp',
                                                        'result' => 'ok'
                                                    ], JSON_THROW_ON_ERROR);
                                                }

                                                if ($packet === null) {
                                                    Console::log('Sending response NOK to peer_invite_req');
                                                    $packet = json_encode([
                                                        'type' => 'peer_invite_resp',
                                                        'result' => 'nok'
                                                    ], JSON_THROW_ON_ERROR);
                                                }

                                                $this->send($client, $packet);
                                                break;

                                            case 'peer_invite_resp':
                                                $result = $data['result'];
                                                Console::log('Peer invite response: ' . $result);
                                                break;

                                            case 'peer_list':
                                                $peerList = is_array($data['peers'] ?? null) ? $data['peers'] : [];

                                                foreach (array_slice($peerList, 0, self::MAX_PEER_LIST) as $peer) {
                                                    if (is_array($peer) && is_string($peer['address'] ?? null)) {
                                                        $this->peer->add($peer['address']);
                                                    }
                                                }
                                                break;

                                            /**
                                             * misc
                                             */
                                            case 'error':
                                                $message = $data['message'];
                                                Console::log('Received error: ' . $message);
                                                break;

                                            default:
                                                break;
                                        }
                                    }
                                } else {
                                    Console::log('Unknown command received: ' . $key);
                                    $this->send($client, json_encode(
                                        ['type' => 'error', 'message' => 'unknown command'],
                                        JSON_THROW_ON_ERROR)
                                    );
                                }
                            } catch (Throwable $e) {
                                Console::log('Bad packet from client ' . $key . ': ' . $e->getMessage());
                            }
                        }
                    }
                }
            }

            // forget outbound connections that have closed
            foreach (array_keys($outbound) as $outboundKey) {
                if (!isset($clients[$outboundKey])) {
                    unset($outbound[$outboundKey]);
                }
            }

            // open one new outbound connection at a time while we have room
            if (time() - $lastDial >= self::DIAL_INTERVAL && count($clients) - 1 < Config::getMaxPeers()) {
                $lastDial = time();
                $connection = $this->dialPeer($address, $port, $outbound);

                if ($connection !== null) {
                    $clients[] = $connection['stream'];
                    $outbound[array_key_last($clients)] = $connection['address'];
                }
            }

            $this->syncStep($clients, $clientInfo);

            // handshakes, pings, etc
            $propagationBlocks = [];
            $queueItems = $this->queue->getItems('new_block');

            foreach ($queueItems as $queueItem) {
                $propagationBlocks[] = $this->block->assembleFullBlock($queueItem['data']);
                $this->queue->delete($queueItem['id']);
            }

            foreach ($clients as $key => $client) { // for each client
                if ($server === $client) {
                    continue;
                }

                // send a handshake and set the peer up
                if (!isset($clientInfo[$key])) {
                    $clientInfo[$key] = $this->clientInfoArray;
                    $clientInfo[$key]['last_ping'] = time();
                    $this->sendHandshake($client);
                }

                // only allow advanced commands after we're initialized
                if ($clientInfo[$key]['init'] === 2) {
                    /**
                     * Get all peer heights
                     */
                    $lastHeight = (int)$clientInfo[$key]['last_height'];

                    if (time() - $lastHeight >= 5) {
                        $clientInfo[$key]['last_height'] = time();
                        Console::log('Requesting height from client: ' . $key);
                        $packet = ['type' => 'height_req'];
                        $this->send($client, json_encode($packet, JSON_THROW_ON_ERROR));
                    }

                    /**
                     * refresh connections
                     */
                    $lastPing = (int)$clientInfo[$key]['last_ping'];

                    if (time() - $lastPing >= 60) {
                        $clientInfo[$key]['last_ping'] = time();
                        Console::log('Pinging client: ' . $key);
                        $packet = ['type' => 'ping'];
                        $this->send($client, json_encode($packet, JSON_THROW_ON_ERROR));
                    }

                    /**
                     * propagate blocks to peers
                     */
                    foreach ($propagationBlocks as $propagationBlock) {
                        $this->send($client, json_encode([
                            'type' => 'block',
                            'block' => $propagationBlock,
                        ], JSON_THROW_ON_ERROR));
                    }
                }

                /**
                 * Time-out any quiet nodes
                 */
                $time = time() - (int)$clientInfo[$key]['last_ping'];

                if ($time > 300) {
                    Console::log('Closing client for lack of activity ID: ' . $key);
                    fclose($client);
                    unset($clientInfo[$key], $clients[$key]);
                }
            }
        }
    }

    /**
     * Takes a block from a peer. It is stored if its parent is known, made the tip if it extends our chain,
     * and switched to if its branch has more work. Blocks waiting on it are processed after it.
     *
     * @param array $block
     * @return string tip, stored, known, missing_parent or invalid
     */
    private function handleBlock(array $block): string
    {
        if (!isset($block['block_id'], $block['previous_block_id'], $block['height'], $block['transactions'])
            || !is_array($block['transactions'])) {
            return 'invalid';
        }

        unset($block['confirmations']);

        if (!empty($this->block->getByBlockId((string)$block['block_id']))) {
            return 'known';
        }

        $parent = $this->block->getByBlockId((string)$block['previous_block_id']);

        if (empty($parent)) {
            if (count($this->pendingBlocks) >= self::MAX_PENDING_BLOCKS) {
                array_shift($this->pendingBlocks);
            }

            $this->pendingBlocks[(string)$block['block_id']] = $block;

            return 'missing_parent';
        }

        // spends can only be checked against our chain when the block builds on our tip, a block on another
        // branch gets that check if the branch ever becomes the chain
        $tip = $this->block->getCurrent();
        $extendsTip = $parent['block_id'] === $tip['block_id'];
        $result = $this->block->validateFullBlock($block, $extendsTip);

        if (!$result['validated']) {
            Console::log('Rejected block ' . $block['block_id'] . ': ' . $result['reason']);

            return 'invalid';
        }

        if (!$this->block->add($block, false, true)) {
            return 'invalid';
        }

        $status = 'stored';
        $stored = $this->block->getByBlockId((string)$block['block_id']);

        if ($extendsTip) {
            if ($this->block->acceptBlock($stored['block_id'], (int)$stored['height'])) {
                $status = 'tip';
            }
        } elseif ($this->block->blockSelector($tip, $stored)['block_id'] === $stored['block_id']
            && $this->block->reorganize($stored)) {
            Console::log('Switched to a chain with more work at height ' . $stored['height']);
            $status = 'tip';
        }

        // blocks that were waiting for this one
        foreach ($this->pendingBlocks as $pendingId => $pending) {
            if ((string)$pending['previous_block_id'] === (string)$block['block_id']) {
                unset($this->pendingBlocks[$pendingId]);

                if ($this->handleBlock($pending) === 'tip') {
                    $status = 'tip';
                }
            }
        }

        return $status;
    }

    /**
     * Opens a connection to a known peer we are not already connected to.
     *
     * @param string $listenAddress
     * @param int $listenPort
     * @param array $outbound
     * @return array|null ['stream' => resource, 'address' => string]
     */
    private function dialPeer(string $listenAddress, int $listenPort, array $outbound): ?array
    {
        $self = [$listenAddress . ':' . $listenPort, '127.0.0.1:' . $listenPort, '0.0.0.0:' . $listenPort];

        foreach ($this->peer->getAll(Config::getMaxPeers()) as $candidate) {
            $address = (string)$candidate['address'];

            if (in_array($address, $outbound, true) || in_array($address, $self, true)) {
                continue;
            }

            $stream = @stream_socket_client('tcp://' . $address, $errno, $errstr, 3);

            if ($stream === false) {
                Console::log('Could not connect to peer ' . $address . ': ' . $errstr);
                $this->peer->incrementFails($address);
                continue;
            }

            Console::log('Connected to peer ' . $address);
            $this->peer->clearFails($address);

            return ['stream' => $stream, 'address' => $address];
        }

        return null;
    }

    /**
     * Asks the highest peer for our next block, one request at a time, and sets the mining state. We mine once
     * no peer is ahead of us, or when we have been alone for the grace period.
     *
     * @param array $clients
     * @param array $clientInfo
     * @return void
     * @throws JsonException
     */
    private function syncStep(array $clients, array $clientInfo): void
    {
        $localHeight = $this->block->getCurrentHeight();
        $bestKey = null;
        $bestHeight = $localHeight;
        $readyPeers = 0;

        foreach ($clientInfo as $key => $info) {
            if (!isset($clients[$key]) || (int)($info['init'] ?? 0) !== 2) {
                continue;
            }

            $readyPeers++;

            if ((int)($info['height'] ?? 0) > $bestHeight) {
                $bestHeight = (int)$info['height'];
                $bestKey = $key;
            }
        }

        if ($this->syncRequest !== null
            && (time() - $this->syncRequest['time'] > self::SYNC_REQUEST_TIMEOUT
                || !isset($clients[$this->syncRequest['key']]))) {
            $this->syncRequest = null;
        }

        if ($bestKey !== null && $this->syncRequest === null) {
            Console::log('Requesting block ' . ($localHeight + 1) . ' of ' . $bestHeight . ' from client: ' . $bestKey);
            $this->send($clients[$bestKey], json_encode([
                'type' => 'block_req',
                'height' => $localHeight + 1,
            ], JSON_THROW_ON_ERROR));
            $this->syncRequest = ['key' => $bestKey, 'time' => time()];
        }

        if ($bestKey !== null) {
            $state = self::SYNCING;
        } elseif ($readyPeers > 0 || time() - $this->startTime >= self::SOLO_GRACE_PERIOD) {
            $state = self::MINING;
        } else {
            $state = self::SYNCING;
        }

        if ($state !== $this->state) {
            Console::log('Node state: ' . $state);
            $this->dataStore->add('state', $state);
            $this->state = $state;
        }
    }
}
