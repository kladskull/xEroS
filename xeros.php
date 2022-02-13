<?php declare(strict_types=1);

namespace Xeros;

// Allow posix signal handling
declare(ticks=1);

// Set default timezone
date_default_timezone_set('UTC');

// Tell PHP that we're using UTF-8 strings until the end of the script
mb_internal_encoding('UTF-8');
$utf_set = ini_set('default_charset', 'utf-8');

// get application directory
$appDir = __DIR__;
if (!str_ends_with($appDir, DIRECTORY_SEPARATOR)) {
    $appDir = __DIR__ . DIRECTORY_SEPARATOR;
}
define('APP_DIR', $appDir);

// includes
require APP_DIR . '/vendor/autoload.php';

// signal app to quit
function shutdown(): void
{
    if (!defined('APP_QUIT')) {
        define('APP_QUIT', true);
    }
}

// Catch SIGINT, run self::shutdown()
pcntl_signal(SIGINT, 'shutdown');

// get the interpreter version
$phpVersion = ((PHP_MAJOR_VERSION * 10000) + (PHP_MINOR_VERSION * 1000) + PHP_RELEASE_VERSION);
if ($phpVersion < 81001) {
    Console::console('The minimum version of PHP must be 8.1');
    exit(1);
}


$db = Database::getDbConn();