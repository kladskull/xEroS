<?php

namespace Xeros;

class BcmathExtensions
{
    public static function bcabs($number)
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