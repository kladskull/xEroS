<?php declare(strict_types=1);

namespace Xeros;

use JetBrains\PhpStorm\Pure;

class Pow
{
    private function doubleSha256(string $data): bool|string
    {
        return hash('sha256', hash('sha256', $data, true));
    }

    public function doubleSha256ToBase58(string $subject): string
    {
        return (new TransferEncoding())->binToBase58($this->doubleSha256($subject));
    }

    #[Pure]
    public function verifyPow(string $hash, string $data, string $nonce): bool
    {
        return $hash === bin2hex($this->doubleSha256($data . $nonce));
    }

    // function for proof of work
    #[Pure]
    public function calculate(string $data, string $nonce): string
    {
        return $this->doubleSha256($data . $nonce);
    }
}