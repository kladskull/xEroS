<?php declare(strict_types=1);

namespace Xeros;

include 'bootstrap.php';

echo PHP_EOL, Config::getProductName(), ' Node ', PHP_EOL;
echo Config::getProductCopyright(), PHP_EOL, PHP_EOL;

Console::log('ip and port can be passed into the app: e.g. php xero.php 1.2.3.4 7747');

// get address and port to use
$listenIp = Config::getListenAddress();
$listenPort = Config::getListenPort();

// if we have an address provided, use the command line value instead
if (!empty($argv[1])) {
    $listenIp = $argv[1];
}

// if we have a port provided, use the command line value instead
if (!empty($argv[2])) {
    $listenPort = (int)$argv[2];
}

// create the node and get online
$node = new Node();
$node->listen($listenIp, $listenPort);
