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

    /**
     * Counts the leading zero bits of a hex encoded hash.
     *
     * @param string $hashHex The hex encoded hash.
     * @return int The number of leading zero bits.
     */
    public function leadingZeroBits(string $hashHex): int
    {
        $bits = 0;

        foreach (str_split(strtolower($hashHex)) as $nibble) {
            $value = hexdec($nibble);

            if ($value === 0) {
                $bits += 4;
                continue;
            }

            // count the zero bits in the first non zero nibble
            $bits += 4 - strlen(decbin($value));
            break;
        }

        return $bits;
    }

    /**
     * Checks that a hash has at least $difficulty leading zero bits, the same rule the miner uses.
     *
     * @param string $hashHex The hex encoded hash.
     * @param int $difficulty The required number of leading zero bits.
     * @return bool True if the hash meets the difficulty.
     */
    public function meetsDifficulty(string $hashHex, int $difficulty): bool
    {
        if (strlen($hashHex) !== 64 || !ctype_xdigit($hashHex)) {
            return false;
        }

        return $this->leadingZeroBits($hashHex) >= $difficulty;
    }

    /**
     * The expected number of hashes needed to find a block at the given difficulty (2^difficulty).
     *
     * @param int $difficulty The difficulty in leading zero bits.
     * @return string The work as a bcmath number.
     */
    public function blockWork(int $difficulty): string
    {
        return bcpow('2', (string)$difficulty, 0);
    }
}
