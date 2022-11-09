<?php declare(strict_types=1);

namespace Blockchain;

use function array_key_exists;

// todo: add this to the database, no hard coded values in code
class Blacklist
{
    // official blacklisted public keys
    public const PUBLIC_KEYS = [
        'key' => 'description'
    ];

    // official blacklisted addresses
    public const ADDRESSES = [
        'address' => 'description'
    ];

    /**
     * Check if a public key is blacklisted
     */
    public static function checkPublicKey(string $publicKey): bool
    {
        return array_key_exists($publicKey, static::PUBLIC_KEYS);
    }

    /**
     * Check if an address is blacklisted
     */
    public static function checkAddress(string $address): bool
    {
        return array_key_exists($address, static::ADDRESSES);
    }
}
