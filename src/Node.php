<?php declare(strict_types=1);

namespace Blockchain;

use JetBrains\PhpStorm\NoReturn;
use JsonException;

class Node
{
    private Peer $peer;
    private Block $block;
    private Queue $queue;
    private Mempool $mempool;
    private DataStore $dataStore;

    public const SYNCING = 'sync';
    public const MINING = 'mine';

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
     * @throws JsonException
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
     * @throws JsonException
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
                        $data = fread($client, 8192);
                        if ($data !== '') {
                            if (is_bool($data)) {
                                continue;
                            }

                            $data = json_decode(trim($data), true, 512, JSON_THROW_ON_ERROR);

                            if (isset($data['type'])) {
                                // only allow uninitialized connections access to these commands
                                if (isset($clientInfo[$key]['init']) && $clientInfo[$key]['init'] < 2) {
                                    switch ($data['type']) {
                                        case 'handshake':
                                            Console::log('Received handshake');

                                            if (Config::getNetworkIdentifier() !== ($data['network'] ?: '')) {
                                                Console::log('Incompatible client <net_id>');
                                                $this->send(
                                                    $client,
                                                    json_encode(
                                                        ['type' => 'error', 'message' => 'wrong network id'],
                                                        JSON_THROW_ON_ERROR
                                                    )
                                                );
                                                fclose($client);
                                                unset($clientInfo[$key], $clients[$key]);
                                            }

                                            if (bccomp($data['version'] ?: '', Config::getVersion(), 4) < 0) {
                                                Console::log('Incompatible client <version>');
                                                $this->send($client, json_encode([
                                                    'type' => 'error',
                                                    'message' => 'incompatible version'
                                                ], JSON_THROW_ON_ERROR));
                                                fclose($client);
                                                unset($clientInfo[$key], $clients[$key]);
                                            }

                                            // store values
                                            $clientInfo[$key]['network'] = $data['network'];
                                            $clientInfo[$key]['version'] = $data['version'];

                                            // we have a handshake, allow more commands
                                            $clientInfo[$key]['init'] = 1;
                                            $this->send($client, json_encode(
                                                ['type' => 'handshake_ok'],
                                                JSON_THROW_ON_ERROR
                                            ));
                                            break;

                                        case 'handshake_ok':
                                            Console::log('Received positive handshake response');

                                            // request a peer list
                                            $this->send($client, json_encode([
                                                'type' => 'peer_list_req'
                                            ], JSON_THROW_ON_ERROR));

                                            //  send our peer details
                                            $this->send($client, json_encode([
                                                'type' => 'peer_invite_req',
                                                'address' => '127.0.0.1',
                                                'port' => $port1,
                                            ], JSON_THROW_ON_ERROR));

                                            // complete the handshake process
                                            $clientInfo[$key]['init'] = 2;
                                            break;

                                        case 'handshake_resp_nok':
                                            Console::log('Received invalid handshake');
                                            break;

                                        /**
                                         * misc
                                         */
                                        case 'error':
                                            $message = $data['message'];
                                            Console::log('Received error: ' . $message);
                                            break;

                                        default:
                                            $this->send(
                                                $client,
                                                json_encode(['type' => 'error', 'message' => 'expected handshake'])
                                            );
                                            fclose($client);
                                            unset($clientInfo[$key], $clients[$key]);
                                            break;
                                    }
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
                                            $block = $data['block'] ?: null;

                                            // invalid or duplicate? if so, ignore...
                                            $id = 0;

                                            if (empty($block)
                                                || $this->block->getByBlockId($block['block_id']) !== null) {
                                                // we will not be forwarding this block
                                                $this->send($client, json_encode([
                                                    'type' => 'block_resp',
                                                    'result' => 'ok',
                                                ], JSON_THROW_ON_ERROR));
                                                break;
                                            }

                                            $validate = $block->validateFullBlock($block);

                                            if ($validate['validated'] && $block['height'] > 1) {
                                                // check to current block
                                                $localHeight = $this->block->getCurrentHeight();
                                                $remoteHeight = (int)$block['height'];

                                                // received a competing block
                                                if ($remoteHeight === $localHeight) {
                                                    /**
                                                     * Competing Block Received!
                                                     */
                                                    $currBlock = $block->getCurrent();

                                                    // add it
                                                    $id = $this->block->add($block, false, true);

                                                    // choose the block based on features
                                                    $selectedBlock = $this->block->blockSelector(
                                                        $currBlock,
                                                        $block
                                                    );
                                                    $this->block->acceptBlock(
                                                        $selectedBlock['block_id'],
                                                        $selectedBlock['height']
                                                    );
                                                } else if ($remoteHeight > $localHeight) {
                                                    /**
                                                     * New Block Received!
                                                     */
                                                    $id = $this->block->add($block, false);
                                                    $this->block->acceptBlock(
                                                        $block['block_id'],
                                                        $block['height']
                                                    );

                                                    // check if we are behind
                                                    if ($this->block->isCurrentHeightOrphan()) {
                                                        $this->queue->add('new_block', $block['block_id']);
                                                    }
                                                } else {
                                                    /**
                                                     * Got an older block... just add it
                                                     */
                                                    // we have an older block...
                                                    $id = $this->block->add($block, false);
                                                    $this->queue->add('height_test', $block['height']);
                                                }
                                            }

                                            // propagate the new block?
                                            if ($id > 0) {
                                                $this->send($client, json_encode([
                                                    'type' => 'block_resp',
                                                    'result' => 'ok'
                                                ], JSON_THROW_ON_ERROR));

                                                // don't overwrite client/etc
                                                foreach ($clients as $c) {
                                                    // don't send to self or current client
                                                    if ($server === $c || $client === $c) {
                                                        continue;
                                                    }

                                                    $this->send($c, json_encode([
                                                        'type' => 'block',
                                                        'block' => $block,
                                                    ], JSON_THROW_ON_ERROR));
                                                }
                                            } else {
                                                // new block is invalid...
                                                $this->send($client, json_encode([
                                                    'type' => 'block_resp',
                                                    'result' => 'nok'
                                                ], JSON_THROW_ON_ERROR));
                                            }
                                            break;

                                        case 'block_req':
                                            Console::log('Received a block request from: ' . $key);
                                            $blockId = $data['block_id'] ?: null;
                                            $height = $data['height'] ?: 0;

                                            if ($blockId !== null) {
                                                $this->send($client, json_encode([
                                                    'type' => 'block',
                                                    'block' => $this->block->assembleFullBlock($blockId['block_id'])
                                                ], JSON_THROW_ON_ERROR));
                                            } else {
                                                if ($height !== 0) {
                                                    $block = $this->block->getByHeight((int)$height);
                                                } else {
                                                    $block = $this->block->getCurrent();
                                                }

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

                                            // propagate transaction
                                            foreach ($clients as $k => $c) {
                                                // don't send to self or current client
                                                if ($server === $c || $client === $c) {
                                                    continue;
                                                }

                                                // new block is invalid...
                                                $blockId = $data['block_id'] ?: null;
                                                $this->send($c, json_encode([
                                                    'type' => 'block',
                                                    'block' => $this->block->assembleFullBlock($blockId['block_id']),
                                                ], JSON_THROW_ON_ERROR));
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
                                            // get the IP we see, not what was given
                                            $packet = null;
                                            socket_getpeername($client, $peerAddress);
                                            $peerPort = (int)$data['port'] ?: null;

                                            if (!empty($peerAddress) && $peerPort !== null && filter_var(
                                                    $peerAddress,
                                                    FILTER_VALIDATE_IP,
                                                    FILTER_FLAG_IPV4
                                                ) && $peerPort > 0 && $peerPort <= 65535) {
                                                $hostAddress = $peerAddress . ':' . $port;

                                                if ($this->peer->getByHostAddress($hostAddress) === null) {
                                                    $this->peer->add($hostAddress);
                                                    Console::log('Sending response OK to peer_invite_req');
                                                }

                                                $packet = json_encode([
                                                    'type' => 'peer_inv_resp',
                                                    'result' => 'ok'
                                                ], JSON_THROW_ON_ERROR);
                                            }

                                            if ($packet === null) {
                                                Console::log('Sending response NOK to peer_invite_req');
                                                $packet = json_encode([
                                                    'type' => 'peer_inv_resp',
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
                                            $peerList = $data['peers'] ?: [];

                                            foreach ($peerList as $peer) {
                                                $this->peer->add($peer['address']);
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
                        } else {
                            Console::log('Client disconnected');
                            fclose($client);
                            unset($clientInfo[$key], $clients[$key]);
                        }
                    }
                }
            }

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
}
