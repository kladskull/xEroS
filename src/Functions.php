<?php declare(strict_types=1);

function bcabs($number)
{
    if (bccomp($number, '0') === -1) {
        return bcmul($number, '-1');
    }
    return $number;
}

function bchexdec(string $hex): string
{
    $dec = "0";
    $len = strlen($hex);
    for ($i = 1; $i <= $len; $i++) {
        $dec = bcadd($dec, bcmul((string)hexdec($hex[$i - 1]), bcpow('16', (string)($len - $i))));
    }
    return $dec;
}

function toArray($data): array
{
    if (!is_array($data)) {
        $data = [];
    }
    return $data;
}

function filterLimit(int $value, int $min, int $max): int
{
    if ($value < $min) {
        $value = $min;
    } else if ($value > $max) {
        $value = $max;
    }
    return $value;
}
