<?php declare(strict_types=1);

namespace Xeros;

use JetBrains\PhpStorm\Pure;

class Node
{
    private string $ip;
    private string $port;
    private array $clients;
    private int $maxPeers = 5;
    private int $peers = 0;

    private TcpIp $tcpIp;

    #[Pure]
    public function __construct()
    {
        $this->tcpIp = new TcpIp();
    }

    private function getConnectUri(string $ip, string $port): string
    {
        return 'tcp://' . $ip . ':' . $port;
    }

    private function connect(string $ip, string $port): bool
    {
        $socket = $this->getConnectUri($ip, $port);
        $connection = stream_socket_client($socket);
        if ($connection !== false) {
            $this->clients[] = $connection;
        }

        return ($connection !== false);
    }

    public function start(string $ip, int $port): bool
    {
        $result = false;
        if ($this->tcpIp->isValidIp($ip) && $this->tcpIp->isValidPort($port)) {
            $this->ip = $ip;
            $this->port = $port;

            $result = true;
            $this->listen();
        }
        return $result;
    }

    private function listen(): void
    {
        $server = stream_socket_server($this->getConnectUri($this->ip, $this->port), $errNumber, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!$server) {
            Log::console("$errorMessage ($errNumber)");
            exit(1);
        }

        $this->clients = [$server];
        stream_set_blocking($server, false);

        while (true) {
            if (!defined('APP_QUIT')) {

                // connect to other peers, if we can
                if ($this->peers < $this->maxPeers) {
                    // we should connect to another peer, we have room
                }

                $changed = $this->clients;
                stream_select($changed, $write, $except, 0, 250);
                if (in_array($server, $changed, true)) {
                    $client = @stream_socket_accept($server);
                    if (!$client) {
                        continue;
                    }
                    $this->clients[] = $client;
                    $ip = stream_socket_get_name($client, true);
                    Log::console("New Client connected from $ip");

                    $found_socket = array_search($server, $changed, true);
                    unset($changed[$found_socket]);
                }

                foreach ($changed as $changed_socket) {
                    $ip = stream_socket_get_name($changed_socket, true);

                    // todo: make sure we get all of it! (while !== false?)
                    $buffer = fread($changed_socket, 8192);
                    if (empty($buffer)) {
                        Log::console("Client Disconnected from $ip");
                        @fclose($changed_socket);
                        $found_socket = array_search($changed_socket, $this->clients, true);
                        unset($this->clients[$found_socket]);
                    }
                    //$unmasked = $this->unmask($buffer);
                    if ($buffer !== '') {
                        Log::console("data from $ip");
                        if ($buffer === 'time') {
                            Log::console("Received a time request from $ip");
                            Log::console("Sending a time response to $ip");
                            @fwrite($changed_socket, time() . '');
                        }

                        $this->send($this->clients, 'hi' . PHP_EOL, $changed_socket);
                    }
                }
            } else {
                // intercepted ctrl-c
                break;
            }
        }

        // close the socket
        Log::console('Closing server port');
        fclose($server);
    }

    private function getSocketName($client): string
    {
        $name = stream_socket_get_name($client, true);
        if ($name === false) {
            $name = 'disconnected client';
        }
        return $name;
    }

    /**
     * Send to a client
     *
     * @param $client
     * @param $message
     * @param bool $compress
     * @return bool
     */
    public function send($client, $message, bool $compress = true): bool
    {
        if (fwrite($client, $message) === false) {
            Log::console('Error sending packet to a client');
            return false;
        }
        return true;
    }

    /**
     * Send to all clients, except ignored
     *
     * Don't bother with a status, as there will be too many, this is basically fire and forget
     *
     * @param array $clients
     * @param string $message
     * @param array $ignore
     * @return void
     */
    public function sendAll(array $clients, string $message, array $ignore = []): void
    {
        $recipientClients = array_diff($clients, $ignore);
        foreach ($recipientClients as $client) {
            if (fwrite($client, gzcompress($message, 9)) === false) {
                Log::console('Error sending packet to client: `' . $this->getSocketName($client) . '`');
            }
        }
    }
}