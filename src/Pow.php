<?php declare(strict_types=1);

namespace Blockchain;

use JetBrains\PhpStorm\Pure;

class Pow
{
    public function doubleSha256(string $data): bool|string
    {
        return hash(
            algo: 'sha256',
            data: hash(
                algo: 'sha256',
                data: $data,
                binary: true
            ),
            binary: true,
        );
    }

    #[Pure]
    public function verifyPow(string $hash, string $data, string $nonce): bool
    {
        return $hash === bin2hex(string: $this->doubleSha256(data: $data . $nonce));
    }

    // function for proof of work
    #[Pure]
    public function calculate(string $data, string $nonce): string
    {
        return $this->doubleSha256(data: $data . $nonce);
    }
}
