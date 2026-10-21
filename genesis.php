<?php declare(strict_types=1);

namespace Blockchain;

include 'bootstrap.php';

echo PHP_EOL, Config::getProductName(), ' Genesis Miner ', PHP_EOL;
echo Config::getProductCopyright(), PHP_EOL, PHP_EOL;

if (count($argv) < 3) {
    echo "usage: php genesis.php <live|test|dev> <private key file>\n\n";
    echo "Mines a new genesis block paid to the key and prints the values to paste into Block::GENESIS.\n";
    exit(1);
}

$environment = Config::normalizeEnvironment($argv[1]);
$privateKey = @file_get_contents($argv[2]);
$keyResource = $privateKey === false ? false : openssl_pkey_get_private($privateKey);

if ($keyResource === false) {
    echo 'Cannot read a private key from ', $argv[2], PHP_EOL;
    exit(1);
}

$publicKey = openssl_pkey_get_details($keyResource)['key'];
$block = new Block();
$pow = new Pow();
$transaction = new Transaction();

$genesis = $block->createGenesis($environment, time(), $publicKey, $privateKey);
$header = $block->generateBlockHeader($genesis);
$difficulty = (int)$genesis['difficulty'];

Console::log('Mining the ' . $environment . ' genesis block at difficulty ' . $difficulty);
$start = microtime(true);

for ($nonce = 0; ; $nonce++) {
    $hash = bin2hex($pow->calculate($header, dechex($nonce)));

    if ($pow->meetsDifficulty($hash, $difficulty)) {
        break;
    }

    if ($nonce > 0 && $nonce % 1000000 === 0) {
        Console::log(number_format($nonce) . ' hashes');
    }
}

$genesis['nonce'] = dechex($nonce);
$genesis['hash'] = $hash;
$coinbase = $genesis['transactions'][0];
$check = $transaction->validate($coinbase, 1);

if (!$pow->verifyPow($hash, $header, $genesis['nonce']) || $check['validated'] !== true) {
    echo 'The new block does not verify: ', $check['reason'], PHP_EOL;
    exit(1);
}

Console::log('Found in ' . number_format($nonce + 1) . ' hashes, ' . round(microtime(true) - $start, 1) . 's');

echo PHP_EOL, "Paste this into Block::GENESIS, then start from a new database:", PHP_EOL, PHP_EOL;
echo "        '", $environment, "' => [", PHP_EOL;
echo "            'date' => ", $genesis['date_created'], ",", PHP_EOL;
echo "            'public_key' => '", $coinbase['public_key'], "',", PHP_EOL;
echo "            'signature' => '", $coinbase['signature'], "',", PHP_EOL;
echo "            'nonce' => '", $genesis['nonce'], "',", PHP_EOL;
echo "            'hash' => '", $genesis['hash'], "',", PHP_EOL;
echo "        ],", PHP_EOL, PHP_EOL;
echo 'Block id: ', $genesis['block_id'], PHP_EOL;
echo 'Paid to:  ', $coinbase[Transaction::OUTPUTS][0]['address'], PHP_EOL;
