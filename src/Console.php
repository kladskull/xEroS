<?php declare(strict_types=1);

namespace Xeros;

class Console
{
    public static function log(string $message): void
    {
        echo '[', date('Y-m-d H:i:s'), ']:  ', $message, PHP_EOL;
    }
}