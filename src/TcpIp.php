<?php declare(strict_types=1);

namespace Blockchain;

use JetBrains\PhpStorm\Pure;
use function filter_var;
use function socket_close;
use function socket_create_listen;
use function socket_getsockname;

/**
 * Class TcpIp
 * @package Blockchain
 */
class TcpIp
{
    /**
     * Don't allow invalid ports, or ports that require privileged access
     * @param int $port
     * @return bool
     */
    public function isValidPort(int $port): bool
    {
        return ($port < 1024 || $port > 65535) === false;
    }

    /**
     * @param string|null $ip
     * @return bool
     */
    #[Pure]
    public function isValidIp(?string $ip): bool
    {
        return (
            $this->isIpv4($ip) || $this->isIpv6($ip)
        );
    }

    /**
     * @param string|null $ip
     * @return bool
     */
    #[Pure]
    public function isPrivateIp(?string $ip): bool
    {
        return (
            $this->isPrivateIpv4($ip) || $this->isPrivateIpv6($ip)
        );
    }

    /**
     * @param string|null $ip
     * @return bool
     */
    public function isIpv4(?string $ip): bool
    {
        return (
            $ip === filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4
            )
        );
    }

    /**
     * @param string|null $ip
     * @return bool
     */
    public function isIpv6(?string $ip): bool
    {
        return (
            $ip === filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6
            )
        );
    }

    /**
     * @param string|null $ip
     * @return bool
     */
    public function isPrivateIpv4(?string $ip): bool
    {
        $result = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($result !== false) {
            $result = true;
        }

        return $result;
    }

    /**
     * @param string|null $ip
     * @return bool
     */
    public function isPrivateIpv6(?string $ip): bool
    {
        $result = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($result !== false) {
            $result = true;
        }

        return $result;
    }

    /**
     * Get a free/available port from the system
     *
     * @param string $address
     * @return string
     */
    private function getFreePort(string $address): string
    {
        $port = 0;
        $sock = socket_create_listen(0);
        socket_getsockname($sock, $address, $port);
        socket_close($sock);

        return (string)$port;
    }
}
