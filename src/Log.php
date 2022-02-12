<?php

namespace Xeros;

class Log
{
    public static function console(string $message): void
    {
        echo '[ ', date('Y-m-d H:i:s'), ' ] -> ', $message, PHP_EOL;
    }
}