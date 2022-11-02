<?php declare(strict_types=1);

namespace Blockchain;

use function date;
use function sprintf;

class Console
{
    public static function log(string $message): void
    {
        echo sprintf('[%s]:  %s %s', date('Y-m-d H:i:s'), $message, PHP_EOL);
    }
}
