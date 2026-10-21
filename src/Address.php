<?php declare(strict_types=1);

namespace Blockchain;

use Throwable;

use function hash;
use function hash_equals;
use function hex2bin;
use function rtrim;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Class Address
 */
class Address
{
    private const BINARY_LENGTH = 25;

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
        // 4 bytes (32 bits), so about one mistyped address in 4 billion passes
        return substr(hash('sha256', hash('sha256', $addressRaw, true), true), 0, 4);
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
     * An address is the header, then base58 of version (1 byte) + ripemd160 (20 bytes) + checksum (4 bytes).
     *
     * @param string $address
     * @return bool
     */
    public function validateAddress(string $address): bool
    {
        $header = Config::getAddressHeader();

        if (!str_starts_with($address, $header) || strlen($address) > 64) {
            return false;
        }

        try {
            $binaryAddress = $this->transferEncoding->decodeBase58(substr($address, strlen($header)));
        } catch (Throwable) {
            return false;
        }

        if (strlen($binaryAddress) !== self::BINARY_LENGTH) {
            return false;
        }

        // an address from another chain (testnet vs live) is not valid here
        if ($binaryAddress[0] !== hex2bin(Config::getChainVersion())) {
            return false;
        }

        $checkSum = substr($binaryAddress, -4);
        $generatedCheckSum = $this->getAddressChecksum(substr($binaryAddress, 0, -4));

        return hash_equals($generatedCheckSum, $checkSum);
    }
}
