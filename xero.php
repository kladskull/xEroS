<?php declare(strict_types=1);

namespace Xero;

use JetBrains\PhpStorm\NoReturn;
use JetBrains\PhpStorm\Pure;

/** ************************************************************************************************************** **/

// Allow posix signal handling
declare(ticks=1);

function shutdown(): void
{
    if (!defined('QUIT')) {
        define('QUIT', true);
    }
}

// Catch SIGINT, run self::shutdown()
pcntl_signal(SIGINT, 'shutdown');

/** ************************************************************************************************************** **/
class Log
{
    public static function console(string $message): void
    {
        echo '[ ', date('Y-m-d H:i:s'), ' ] -> ', $message, PHP_EOL;
    }
}

class Message
{
    private const COMMAND = 'c';
    private const DATA = 'd';
    private const HASH = 'h';
    private const RESULT = 'r';
    private const TIME = 't';
    private const VERSION = 'v';

    private function format(array $message): string
    {
        try {
            $message[self::VERSION] = '1.0.0';
            $message[self::TIME] = time();
            $message[self::HASH] = hash('ripemd160', json_encode($message, JSON_THROW_ON_ERROR));
            $packet = json_encode($message, JSON_THROW_ON_ERROR);
        } catch (Exception) {
            $packet = null;
        }
        return $packet;
    }

    public function send(string $command, array $data): string
    {
        try {
            $packet = $this->format([
                self::COMMAND => $command,
                self::DATA => $data,
            ]);
        } catch (Exception) {
            $packet = null;
        }
        return $packet;
    }

    public function sendResponse(bool $result, array $data): string
    {
        try {
            $packet = json_encode([
                self::RESULT => $result,
                self::DATA => $data,
            ], JSON_THROW_ON_ERROR);
        } catch (Exception) {
            $packet = null;
        }
        return $packet;
    }
}

class Networking
{
    #[Pure]
    public static function isIp(?string $ip): bool
    {
        return (
            self::isIpv4($ip) || self::isIpv6($ip)
        );
    }

    #[Pure]
    public static function isPrivateIp(?string $ip): bool
    {
        return (
            self::isPrivateIpv4($ip) || self::isPrivateIpv6($ip)
        );
    }

    public static function isIpv4(?string $ip): bool
    {
        return (
            $ip === filter_var(
                $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4
            )
        );
    }

    public static function isIpv6(?string $ip): bool
    {
        return (
            $ip === filter_var(
                $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6
            )
        );
    }

    public static function isPrivateIpv4(?string $ip): bool
    {
        $result = filter_var(
            $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($result !== false) {
            $result = true;
        }
        return $result;
    }

    public static function isPrivateIpv6(?string $ip): bool
    {
        $result = filter_var(
            $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($result !== false) {
            $result = true;
        }
        return $result;
    }
}

class Peer
{
    private string $ip;
    private string $port;
    private array $localPeerList;
    private array $clients;

    public function __construct(string $ip = '0.0.0.0', string $port = '7477')
    {
        $this->ip = $ip;
        $this->port = $port;

        $this->clients = [];
        $this->localPeerList = [];
    }

    private function getFreePort(): string
    {
        $sock = socket_create_listen(0);
        socket_getsockname($sock, $this->address, $port);
        socket_close($sock);
        return (string)$port;
    }

    public function connectToPeer(string $ip, string $port)
    {
        // we are not at our max peer limit, so lets connect to some more...
        if ($this->port === '7777') {
            $peerIp = 'tcp://' . $ip . ':' . $port;
            if (!isset($this->localPeerList[$peerIp])) {
                $stream = stream_socket_client($peerIp);
                $this->clients[] = $stream;
                $this->localPeerList[$peerIp] = $peerIp;
            }
        }
    }

    public function listen(): void
    {
        $server = stream_socket_server('tcp://' . $this->ip . ':' . $this->port, $errNumber, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (!$server) {
            die("$errorMessage ($errNumber)");
        }

        stream_set_blocking($server, false);

        $this->clients = [$server];

        $maxPeers = 5;

        while (true) {
            if (!defined('QUIT')) {

                // connect to other peers, if we can
                if (count($this->localClients) < $maxPeers) {
                    // we should connect to another peer, we have room
                }

                $changed = $this->clients;
                stream_select($changed, $write, $except, 0, 250);
                //if (in_array($stream, $changed)) {
                //    echo "wut?!\n";
                //} else {
                if (in_array($server, $changed, true)) {
                    $client = @stream_socket_accept($server);
                    if (!$client) {
                        continue;
                    }
                    $this->clients[] = $client;
                    $ip = stream_socket_get_name($client, true);
                    Log::console("New Client connected from $ip");

                    //stream_set_blocking($client, true);
                    //$headers = fread($client, 1500);
                    //handshake($client, $headers, $host, $port);
                    //stream_set_blocking($client, false);
                    @fwrite($client, 'time');

                    $found_socket = array_search($server, $changed, true);
                    unset($changed[$found_socket]);
                }
                //}

                foreach ($changed as $changed_socket) {
                    $ip = stream_socket_get_name($changed_socket, true);
                    //$buffer = stream_get_contents($changed_socket, 8192);
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

                        $this->send_message($this->clients, 'hi' . PHP_EOL, $changed_socket);
                    }
                    //$response = $this->mask($unmasked);
                    //$this->send_message($this->clients, $buffer, $changed_socket);

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

    public function unmask($text): string
    {
        $length = @ord($text[1]) & 127;
        if ($length === 126) {
            $masks = substr($text, 4, 4);
            $data = substr($text, 8);
        } elseif ($length === 127) {
            $masks = substr($text, 10, 4);
            $data = substr($text, 14);
        } else {
            $masks = substr($text, 2, 4);
            $data = substr($text, 6);
        }
        $text = '';
        for ($i = 0, $iMax = strlen($data); $i < $iMax; ++$i) {
            $text .= $data[$i] ^ $masks[$i % 4];
        }
        return $text;
    }

    public function mask($text): string
    {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($text);
        if ($length <= 125)
            $header = pack('CC', $b1, $length);
        elseif ($length > 125 && $length < 65536)
            $header = pack('CCn', $b1, 126, $length);
        elseif ($length >= 65536)
            $header = pack('CCNN', $b1, 127, $length);
        return $header . $text;
    }

    public function handshake($client, $rcvd, $host, $port): void
    {
        $headers = array();
        $lines = preg_split("/\r\n/", $rcvd);
        foreach ($lines as $line) {
            $line = rtrim($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $headers[$matches[1]] = $matches[2];
            }
        }
        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        //hand shaking header
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "WebSocket-Origin: $host\r\n" .
            "WebSocket-Location: wss://$host:$port\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
        fwrite($client, $upgrade);
    }

    public function send_message($clients, $msg, $ignore): void
    {
        foreach ($clients as $changed_socket) {
            if ($changed_socket !== $ignore) {
                @fwrite($changed_socket, $msg);
            }
        }
    }

}

$peer = new Peer($argv[1], $argv[2]);
$peer->listen();


