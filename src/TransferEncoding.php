<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use StephenHill\Base58;
use function array_map;
use function chr;
use function dechex;
use function hexdec;
use function implode;
use function ord;
use function sprintf;
use function str_split;
use function strlen;
use function strtolower;

/**
 * Class TransactionEncoding
 */
class TransferEncoding
{
    private Base58 $base58;

    public function __construct()
    {
        $this->base58 = new Base58();
    }

    /**
     * @param string $base58
     * @return bool
     */
    public function isBase58(string $base58): bool
    {
        $result = true;

        if (!empty($base58)) {
            try {
                $this->decodeBase58($base58);
            } catch (Exception) {
                $result = false;
            }
        } else {
            $result = false;
        }

        return $result;
    }

    /**
     * @param string $data
     * @return string
     */
    public function encodeBase58(string $data): string
    {
        return $this->base58->encode($data);
    }

    /**
     * @param string $base58
     * @return string
     */
    public function decodeBase58(string $base58): string
    {
        return $this->base58->decode($base58);
    }

    /**
     * @param string $binary
     * @return string
     */
    public function binToBase58(string $binary): string
    {
        return $this->encodeBase58($binary);
    }

    /**
     * @param string $base58
     * @return string
     */
    public function base58ToBin(string $base58): string
    {
        return $this->decodeBase58($base58);
    }

    /**
     * @param string $bin
     * @return string
     */
    public function binToHex(string $bin): string
    {
        return implode(
            '',
            array_map(static fn($x) => sprintf("%02s", strtolower(dechex(ord($x)))), str_split($bin))
        );
    }

    /**
     * @param string $hex
     * @return string
     */
    public function hexToBin(string $hex): string
    {
        $string = '';

        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            $string .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }

        return $string;
    }
}
