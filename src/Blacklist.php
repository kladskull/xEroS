<?php declare(strict_types=1);

namespace Xeros;

// todo: add this to the database, no hard coded values in code
class Blacklist
{
    // official blacklisted public keys
    public const PublicKeys = [
        'key' => 'description'
    ];

    // official blacklisted addresses
    public const Addresses = [
        'address' => 'description'
    ];

    /**
     * Check if a public key is blacklisted
     *
     * @param string $publicKey
     * @return bool
     */
    public static function checkPublicKey(string $publicKey): bool
    {
        return array_key_exists($publicKey, static::PublicKeys);
    }

    /**
     * Check if an address is blacklisted
     *
     * @param string $address
     * @return bool
     */
    public static function checkAddress(string $address): bool
    {
        return array_key_exists($address, static::Addresses);
    }

}