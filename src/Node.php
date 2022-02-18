<?php declare(strict_types=1);

namespace Xeros;

class Node
{
    private array $connections;
    private string $ip;
    private int $port;
    private string $externalIp;

    private TcpIp $tcpIp;
    private Peer $peer;
    private Block $block;
    private Transaction $transaction;
    private Mempool $mempool;

    public const Syncing = 'sync';
    public const Mining = 'mine';

    private array $clientInfoArray = [
        'version' => '0',
        'init' => 0,
        'connect_time' => 0,
        'last_ping' => 0,
        'current_height' => 0,
        'last_height' => 0
    ];

    public function __construct()
    {
        $this->tcpIp = new TcpIp();
        $this->peer = new Peer();
        $this->block = new Block();
        $this->transaction = new Transaction();
        $this->mempool = new Mempool();
    }

    private function send($client, string $data)
    {
        // add a newline for back-to-back sends
        $data = trim($data) . "\n";
        if (is_resource($client)) {
            fwrite($client, $data, strlen($data));
        }
    }

    public function listen(string $address, int $port)
    {
        Console::log('Opening a server socket on address: ' . $address . ' port: ' . $port);
        $sock = stream_socket_server("tcp://$address:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN) or die("Cannot create socket.\n");
        stream_set_blocking($sock, false);

        $this->port = $port;
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

                // Handle Input
                foreach ($clients as $key => $client) { // for each client
                    if (in_array($client, $read)) {
                        //if (false == ($data = trim(socket_read($client, 2048, PHP_BINARY_READ)))) {
                        $data = fread($client, 8192);
                        if ($data !== '') {
                            $data = json_decode(trim($data), true);

                            if (isset($data['type'])) {

                                // only allow uninitialized connections access to these commands
                                if ($clientInfo[$key]['init'] < 2) {
                                    switch ($data['type']) {
                                        case 'handshake':
                                            Console::log('Received handshake');
                                            if (Config::getNetworkIdentifier() !== ($data['network'] ?: '')) {
                                                Console::log('Incompatible client <net_id>');
                                                $this->send($client, json_encode(['type' => 'error', 'message' => 'wrong network id']));
                                                fclose($client);
                                                unset($clientInfo[$key]);
                                                unset($clients[$key]);
                                            }

                                            if (bccomp($data['version'] ?: '', Config::getVersion(), 4) < 0) {
                                                Console::log('Incompatible client <version>');
                                                $this->send($client, json_encode(['type' => 'error', 'message' => 'incompatible version']));
                                                fclose($client);
                                                unset($clientInfo[$key]);
                                                unset($clients[$key]);
                                            }

                                            // store values
                                            $clientInfo[$key]['network'] = $data['network'];
                                            $clientInfo[$key]['version'] = $data['version'];

                                            // we have a handshake, allow more commands
                                            $clientInfo[$key]['init'] = 1;
                                            $this->send($client, json_encode(['type' => 'handshake_ok']));
                                            break;

                                        case 'handshake_ok':
                                            if ($clientInfo[$key]['init'] !== 1) {
                                                Console::log('Received handshake before `ok` response');
                                                $this->send($client, json_encode([
                                                    'type' => 'handshake_resp_nok',
                                                    'result' => 'nok'
                                                ]));
                                                break;
                                            }

                                            Console::log('Received handshake');

                                            // request a peer list
                                            $this->send($client, json_encode([
                                                'type' => 'peer_list_req'
                                            ]));

                                            //  send our peer details (IP doesn't matter, as other peer gets the real address)
                                            $this->send($client, json_encode([
                                                'type' => 'peer_invite_req',
                                                'address' => "127.0.0.1",
                                                'port' => $this->port
                                            ]));

                                            // complete the handshake process
                                            $clientInfo[$key]['init'] = 2;
                                            break;

                                        default:
                                            $this->send($client, json_encode(['type' => 'error', 'message' => 'expected handshake']));
                                            fclose($client);
                                            unset($clientInfo[$key]);
                                            unset($clients[$key]);
                                            break;
                                    }
                                }

                                // only allow initialized connections for these commands
                                if ($clientInfo[$key]['init'] >= 2) {
                                    switch ($data['type']) {
                                        case 'ping':
                                            Console::log('Received ping');
                                            $this->send($client, json_encode([
                                                'type' => 'pong'
                                            ]));

                                            // renew the connection timeout
                                            $clientInfo[$key]['last_ping'] = time();
                                            break;

                                        case 'pong':
                                            Console::log('Received pong');
                                            break;

                                        case 'peer_list_req':
                                            Console::log('Received peer_list_req');
                                            $peerList = $this->peer->getAll();
                                            Console::log('Sent peer_list');
                                            $this->send($client, json_encode([
                                                'type' => 'peer_list',
                                                'peers' => $peerList
                                            ]));
                                            break;

                                        case 'peer_invite_req':
                                            Console::log('Received peer_invite_req');
                                            // get the IP we see, not what was given
                                            $packet = null;
                                            socket_getpeername($client, $peerAddress);
                                            $peerPort = (int)$data['port'] ?: null;
                                            if (!empty($peerAddress) && !empty($peerPort)) {
                                                if (filter_var($peerAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $peerPort > 0 && $peerPort <= 65535) {
                                                    $hostAddress = $peerAddress . ':' . $port;
                                                    if ($this->peer->getByHostAddress($hostAddress) === null) {
                                                        $this->peer->add($hostAddress);
                                                        Console::log('Sending response OK to peer_invite_req');
                                                    }
                                                    $packet = json_encode([
                                                        'type' => 'peer_inv_resp',
                                                        'result' => 'ok'
                                                    ]);
                                                }
                                            }
                                            if ($packet === null) {
                                                Console::log('Sending response NOK to peer_invite_req');
                                                $packet = json_encode([
                                                    'type' => 'peer_inv_resp',
                                                    'result' => 'nok'
                                                ]);
                                            }
                                            $this->send($client, $packet);
                                            break;

                                        case 'peer_list':
                                            $peerList = $data['peers'] ?: [];
                                            foreach ($peerList as $peer) {
                                                $this->peer->add($peer['address']);
                                            }
                                            break;

                                        case 'height_req':
                                            Console::log('Received a height request from: ' . $key);
                                            $this->send($client, json_encode([
                                                'type' => 'height',
                                                'height' => 11
                                            ]));
                                            break;

                                        case 'height':
                                            $height = $data['height'] ?: 0;
                                            Console::log('Received a height of ' . $height . ' request from: ' . $key);
                                            $clientInfo[$key]['height'] = $height;
                                            break;

                                        default:
                                            break;
                                    }
                                }
                            } else {
                                Console::log('Unknown command received: ' . $key);
                                $this->send($client, json_encode(['type' => 'error', 'message' => 'unknown command']));
                            }
                        } else {
                            Console::log('Client disconnected');
                            fclose($client);
                            unset($clientInfo[$key]);
                            unset($clients[$key]);
                        }
                    }
                }
            }

            // handshakes, pings, etc
            foreach ($clients as $key => $client) { // for each client
                if ($server === $client) {
                    continue;
                }

                // send a handshake and set the peer up
                if (!isset($clientInfo[$key])) {
                    $clientInfo[$key] = $this->clientInfoArray;
                    $clientInfo[$key]['last_ping'] = time();

                    Console::log('Sending handshake for client: ' . $key);
                    $packet = [
                        'type' => 'handshake',
                        'network' => Config::getNetworkIdentifier(),
                        'version' => Config::getVersion(),
                    ];
                    $this->send($client, json_encode($packet));
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
                        $this->send($client, json_encode($packet));
                    }

                    /**
                     * refresh connections
                     */
                    $lastPing = (int)$clientInfo[$key]['last_ping'];
                    if (time() - $lastPing >= 60) {
                        $clientInfo[$key]['last_ping'] = time();
                        Console::log('Pinging client: ' . $key);
                        $packet = ['type' => 'ping'];
                        $this->send($client, json_encode($packet));
                    }

                }

                /**
                 * Time-out any quiet nodes
                 */
                $time = time() - (int)$clientInfo[$key]['last_ping'];
                if ($time > 300) {
                    Console::log('Closing client for lack of activity ID: ' . $key);
                    fclose($client);
                    unset($clientInfo[$key]);
                    unset($clients[$key]);
                }
            }

        }

    }


}