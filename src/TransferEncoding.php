<?php declare(strict_types=1);

namespace Xeros;

use Exception;
use StephenHill\Base58;

class TransferEncoding
{
    private Base58 $base58;

    public function __construct()
    {
        $this->base58 = new Base58();
    }

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

    public function encodeBase58(string $data): string
    {
        return $this->base58->encode($data);
    }

    public function decodeBase58(string $base58): string
    {
        return $this->base58->decode($base58);
    }

    public function binToBase58(string $binary): string
    {
        return $this->encodeBase58($binary);
    }

    public function base58ToBin(string $base58): string
    {
        return $this->decodeBase58($base58);
    }

    public function binToHex(string $bin): string
    {
        return implode('', array_map(static fn($x) => sprintf("%02s", strtolower(dechex(ord($x)))), str_split($bin)));
    }

    public function hexToBin(string $hex): string
    {
        $string = '';
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            $string .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }
        return $string;
    }
}