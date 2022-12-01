<?php declare(strict_types=1);

namespace Blockchain;

use function bcadd;
use function bccomp;
use function bcmul;
use function bcpow;
use function hexdec;
use function strlen;

class BcmathExtensions
{
    public static function bcabs(string $number): string
    {
        if (bccomp($number, '0') === -1) {
            return bcmul($number, '-1');
        }

        return $number;
    }

    public static function bchexdec(string $hex): string
    {
        $dec = "0";
        $len = strlen($hex);

        for ($i = 1; $i <= $len; $i++) {
            $dec = bcadd($dec, bcmul((string)hexdec($hex[$i - 1]), bcpow('16', (string)($len - $i))));
        }

        return $dec;
    }

}