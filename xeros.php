<?php declare(strict_types=1);

namespace Xeros;

// Allow posix signal handling
declare(ticks=1);

// includes
require __DIR__ . '/vendor/autoload.php';

function shutdown(): void
{
    if (!defined('QUIT')) {
        define('QUIT', true);
    }
}

// Catch SIGINT, run self::shutdown()
//pcntl_signal(SIGINT, 'shutdown');

// get the interpreter version
$phpVersion = ((PHP_MAJOR_VERSION * 10000) + (PHP_MINOR_VERSION * 1000) + PHP_RELEASE_VERSION);
if ($phpVersion < 81001) {
    Log::console('The minimum version of PHP must be 8.1');
    exit(1);
}

$peer = new Node();
