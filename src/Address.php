<?php declare(strict_types=1);

namespace Blockchain;

use function hash;
use function hex2bin;
use function rtrim;
use function str_starts_with;
use function substr;

/**
 * Class Address
 */
class Address
{
    private TransferEncoding $transferEncoding;
    private OpenSsl $openssl;

    public function __construct()
    {
        $this->transferEncoding = new TransferEncoding();
        $this->openssl = new OpenSsl();
    }

    /**
     * @param string $addressRaw
     * @return string
     */
    public function getAddressChecksum(string $addressRaw): string
    {
        return substr(hash('sha256', $addressRaw), 0, 4);
    }

    /**
     * @param string $publicKey
     * @return string
     */
    public function create(string $publicKey): string
    {
        if (!str_starts_with($publicKey, OpenSsl::BEGIN_PUBLIC_KEY)
            && !str_ends_with(rtrim($publicKey), OpenSsl::END_PUBLIC_KEY)
        ) {
            $publicKey = $this->openssl->formatPem($publicKey, false);
        }

        $sha256hash = $this->createPartial($publicKey);

        return $this->createAddressFromPartial($sha256hash);
    }

    /**
     * @param string $publicKey
     * @return string
     */
    public function createPartial(string $publicKey): string
    {
        $publicKeyRaw = $this->openssl->pemToBin($publicKey);
        $binaryNonce = '';

        return hash('sha256', $binaryNonce . $publicKeyRaw, true);
    }

    /**
     * @param string $sha256Hash
     * @return string
     */
    public function createAddressFromPartial(string $sha256Hash): string
    {
        $ripeMdHash = hash('ripemd160', $sha256Hash, true);

        // [00/01] = live/testnet
        $addressRaw = hex2bin(Config::getChainVersion()) . $ripeMdHash;
        $checkSum = $this->getAddressChecksum($addressRaw);

        return Config::getAddressHeader() . $this->transferEncoding->binToBase58($addressRaw . $checkSum);
    }

    /**
     * @param string $address
     * @return bool
     */
    public function validateAddress(string $address): bool
    {
        if (!str_starts_with($address, Config::getAddressHeader())) {
            return false;
        }

        $b58Part = substr($address, 2); // Bc
        $binaryAddress = $this->transferEncoding->decodeBase58($b58Part);
        $checkSum = substr($binaryAddress, -4);
        $binaryAddressForChecksum = substr($binaryAddress, 0, -4);

        $generatedCheckSum = $this->getAddressChecksum($binaryAddressForChecksum);

        return ($generatedCheckSum === $checkSum);
    }
}
