<?php declare(strict_types=1);

namespace Xeros;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use React;
use JetBrains\PhpStorm\Pure;
use Symfony\Component\Console\Exception\CommandNotFoundException;

class Node
{
    private array $connections;
    private string $ip;
    private int $port;
    private string $externalIp;

    private TcpIp $tcpIp;
    private Peer $peer;

    public const Syncing = 'sync';
    public const Mining = 'mine';

    private array $clientInfoArray = [
        'init' => false,
        'handshake' => false,
        'version' => '0',
        'packets_in' => 0,
        'packets_out' => 0,
        'connect_time' => 0,
        'last_ping' => 0,
    ];

    public function __construct()
    {
        $this->tcpIp = new TcpIp();
        $this->peer = new Peer();
    }

    private function getPeers(): string
    {
        return '99.253.185.218:7777' . PHP_EOL . '99.253.185.218:8888' . PHP_EOL . '99.253.185.218:6666' . PHP_EOL;
    }

    private function close($client, $reason)
    {
        Console::log('Kicking client: ' . $reason);
        socket_set_block($client);
        socket_set_option($client, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 1]);
        socket_close($client);
    }

    private function send($client, string $data)
    {
        $data = trim($data);
        socket_write($client, $data, strlen($data));
    }

    public function listen(string $address, int $port)
    {
        Console::log('Opening a server socket on address: ' . $address . ' port: ' . $port);
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_set_nonblock($sock);
        socket_bind($sock, $address, $port);
        socket_listen($sock, 100);

        $this->port = $port;
        $server = $sock;
        $clients = [$sock];
        $clientInfo = [];

        while (1) {
            $read = $clients;
            $write = null;
            $except = null;

            // Set up a blocking call to socket_select
            $reads = socket_select($read, $write, $except, 0, 250000);

            if ($reads > 0) {
                Console::log('Port changes: ' . $reads);

                // check if there is a client trying to connect
                if (in_array($sock, $read)) {
                    $newSocket = socket_accept($sock);
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
                        if (false == ($data = trim(socket_read($client, 2048, PHP_BINARY_READ)))) {
                            Console::log('Error: ' . socket_strerror(socket_last_error($client)));
                            break 2;
                        }

                        if ($data !== '') {
                            $data = json_decode(trim($data), true);

                            if (isset($data['type'])) {

                                // only allow uninitialized connections access to these commands
                                if ($clientInfo[$key]['init'] === false) {
                                    switch ($data['type']) {
                                        case 'handshake':
                                            Console::log('Received handshake');
                                            if (Config::getNetworkIdentifier() !== ($data['network'] ?: '')) {
                                                Console::log('Incompatible client <net_id>');
                                                $this->send($client, json_encode(['type' => 'error', 'message' => 'wrong network id']));
                                                $this->close($client, 'wrong network id');
                                                unset($clientInfo[$key]);
                                            }

                                            if (bccomp($data['version'] ?: '', Config::getVersion(), 4) < 0) {
                                                Console::log('Incompatible client <version>');
                                                $this->send($client, json_encode(['type' => 'error', 'message' => 'incompatible version']));
                                                $this->close($client, 'incompatible version');
                                                unset($clientInfo[$key]);
                                            }

                                            // store values
                                            $clientInfo[$key]['network'] = $data['network'];
                                            $clientInfo[$key]['version'] = $data['version'];

                                            // we have a handshake, allow more commands
                                            $clientInfo[$key]['init'] = true;
                                            $this->send($client, json_encode(['type' => 'handshake_ok']));
                                            break;

                                        default:
                                            $this->send($client, json_encode(['type' => 'error', 'message' => 'expected handshake']));
                                            $this->close($client, 'expected handshake');
                                            unset($clientInfo[$key]);
                                            break;
                                    }
                                }

                                // only allow initialized connections for these commands
                                if ($clientInfo[$key]['init'] === true) {
                                    switch ($data['type']) {
                                        case 'ping':
                                            Console::log('Received ping');
                                            $this->send($client, json_encode(['type' => 'pong']));
                                            break;

                                        case 'pong':
                                            Console::log('Received pong');
                                            $clientInfo[$key]['last_ping'] = time();
                                            break;

                                        case 'handshake_ok':
                                            Console::log('Received handshake_ok <complete>');
                                            break;

                                        case 'peer_list_req':
                                            Console::log('Received peer_list_req');
                                            $peerList = $this->peer->getAll();
                                            Console::log('Sent peer_list');
                                            $this->send($client, json_encode(['type' => 'peer_list', 'peers' => $peerList]));
                                            break;

                                        case 'peer_invite_req':
                                            Console::log('Received peer_invite_req');
                                            // get the IP we see, not what was given
                                            $packet = null;
                                            $peerAddress = socket_getpeername($client, $address) ?: null;
                                            $peerPort = (int)$data['port'] ?: null;
                                            if ($peerAddress !== null && $peerPort !== null) {
                                                if (filter_var($peerAddress, FILTER_VALIDATE_IP) && $peerAddress > 0 && $peerAddress <= 65535) {
                                                    $hostAddress = $address . ':' . $port;
                                                    if ($this->peer->getByHostAddress($hostAddress)) {
                                                        $this->peer->add($hostAddress);
                                                        Console::log('Sending response OK to peer_invite_req');
                                                        $packet = json_encode(['type' => 'peer_inv_resp', 'result' => 'ok']);
                                                    }
                                                }
                                            }
                                            if ($packet === null) {
                                                Console::log('Sending response NOK to peer_invite_req');
                                                $packet = json_encode(['type' => 'peer_inv_resp', 'result' => 'nok']);
                                            }
                                            $this->send($client, $packet);
                                            break;

                                        default:
                                            break;
                                    }
                                }
                            } else {
                                Console::log('Unknown command received: ' . $key);
                                $this->send($client, json_encode(['type' => 'error', 'message' => 'unknown command']));
                                continue;
                            }
                        }

                        if ($data === '') {
                            Console::log('Client closed socket');
                            socket_close($client);
                            unset($clientInfo[$key]);
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

                // send out pings
                if ($clientInfo[$key]['init'] === true) {
                    $lastPing = (int)$clientInfo[$key]['last_ping'];
                    if (time() - $lastPing >= 60) {
                        $clientInfo[$key]['last_ping'] = time();
                        Console::log('Pinging client: ' . $key);
                        $packet = ['type' => 'ping'];
                        $this->send($client, json_encode($packet));
                    }
                }
            }

            //end
        }

    }


}