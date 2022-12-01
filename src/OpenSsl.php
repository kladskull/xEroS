<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use JetBrains\PhpStorm\ArrayShape;
use RuntimeException;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function hex2bin;
use function implode;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function openssl_verify;
use function random_bytes;
use function str_replace;
use function str_split;
use function str_starts_with;

// todo: reduce the size of the signature (dechex, and base58?)

class OpenSsl
{
    public const BEGIN_PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----";
    public const END_PUBLIC_KEY = "-----END PUBLIC KEY-----";
    public const BEGIN_PRIVATE_KEY = "-----BEGIN PRIVATE KEY-----";
    public const END_PRIVATE_KEY = "-----END PRIVATE KEY-----";

    public function pemToBin(string $text): string
    {
        return base64_decode(str_replace([self::BEGIN_PUBLIC_KEY, self::END_PUBLIC_KEY,
            self::BEGIN_PRIVATE_KEY, self::END_PRIVATE_KEY, PHP_EOL], "", $text));
    }

    public function binToPem(string $data, bool $privateKey): string
    {
        $data = implode(PHP_EOL, str_split(base64_encode($data), 64));

        if ($privateKey) {
            $formatted = self::BEGIN_PRIVATE_KEY . PHP_EOL . $data . PHP_EOL . self::END_PRIVATE_KEY . PHP_EOL;
        } else {
            $formatted = self::BEGIN_PUBLIC_KEY . PHP_EOL . $data . PHP_EOL . self::END_PUBLIC_KEY . PHP_EOL;
        }

        return $formatted;
    }

    public function stripPem(string $text): string
    {
        return str_replace([self::BEGIN_PUBLIC_KEY, self::END_PUBLIC_KEY,
            self::BEGIN_PRIVATE_KEY, self::END_PRIVATE_KEY, PHP_EOL], "", $text);
    }

    public function formatPem(string $data, bool $privateKey): string
    {
        if ($privateKey) {
            $formatted = self::BEGIN_PRIVATE_KEY . PHP_EOL . $data . PHP_EOL . self::END_PRIVATE_KEY . PHP_EOL;
        } else {
            $formatted = self::BEGIN_PUBLIC_KEY . PHP_EOL . $data . PHP_EOL . self::END_PUBLIC_KEY . PHP_EOL;
        }

        return $formatted;
    }

    #[ArrayShape(['public_key' => "mixed", 'public_key_raw' => "string", 'private_key' => ""])]
    public function createRsaKeyPair(): array
    {
        // create new private and public key
        $new_key_pair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        // export the private key
        openssl_pkey_export($new_key_pair, $private_key_pem);

        // export the public key
        $details = openssl_pkey_get_details($new_key_pair);
        $public_key_pem = $details['key'];

        return [
            'public_key' => $public_key_pem,
            'public_key_raw' => $this->stripPem($public_key_pem),
            'private_key' => $private_key_pem,
        ];
    }

    /**
     * @throws Exception
     */
    public function signAndVerifyData(string $textToSign, string $publicPemKey, string $privatePemKey): string
    {
        // create signature
        openssl_sign($textToSign, $signature, $privatePemKey, OPENSSL_ALGO_SHA256);

        //verify signature
        $result = openssl_verify($textToSign, $signature, $publicPemKey, "sha256WithRSAEncryption");

        // can't sign
        if ($result === 0) {
            throw new RuntimeException("Signature not verified, couldn't sign the data.");
        }

        // error
        if ($result === -1) {
            throw new RuntimeException("Signature not verified, error signing the data.");
        }

        return bin2hex($signature);
    }

    /**
     * @throws Exception
     */
    public function verifySignature(string $textToSign, string $signature, string $publicPemKey): bool
    {
        // check if the signature needs to be formatted
        if (!str_starts_with($publicPemKey, self::BEGIN_PUBLIC_KEY)) {
            $publicPemKey = $this->formatPem($publicPemKey, false);
        }

        //verify signature
        $result = openssl_verify($textToSign, hex2bin($signature), $publicPemKey, "sha256WithRSAEncryption");

        if ($result === -1) {
            return false;
        }

        // invalid signature
        return ($result === 1);
    }

    /**
     * @throws Exception
     */
    #[ArrayShape(['bytes' => "string", 'strong' => ""])]
    public function getRandomBytesBinary(int $byteCount = 32): string
    {
        return random_bytes($byteCount);
    }

    /**
     * @throws Exception
     */
    public function getRandomBytesHex($byteCount = 32): string
    {
        return bin2hex($this->getRandomBytesBinary($byteCount));
    }
}
