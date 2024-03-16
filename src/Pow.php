<?php declare(strict_types=1);

namespace Blockchain;

/**
 * Class Pow
 * @package Blockchain
 */
class Pow
{
    /**
     * Computes the double SHA256 hash of the given data.
     *
     * @param string $data The input data.
     * @return string The double SHA256 hash.
     */
    public function doubleSha256(string $data): string
    {
        return hash('sha256', hash('sha256', $data, true), true);
    }

    /**
     * Verifies the proof of work for the given hash, data, and nonce.
     *
     * @param string $hash The hash to verify.
     * @param string $data The data.
     * @param string $nonce The nonce.
     * @return bool True if the proof of work is valid, false otherwise.
     */
    public function verifyPow(string $hash, string $data, string $nonce): bool
    {
        return $hash === bin2hex($this->doubleSha256($data . $nonce));
    }

    /**
     * Calculates the proof of work for the given data and nonce.
     *
     * @param string $data The data.
     * @param string $nonce The nonce.
     * @return string The calculated proof of work.
     */
    public function calculate(string $data, string $nonce): string
    {
        return $this->doubleSha256($data . $nonce);
    }
}
