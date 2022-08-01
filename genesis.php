<?php declare(strict_types=1);

namespace Blockchain;

use Exception;

include 'bootstrap.php';

$block = new Block();
$miner = new Miner();
$pow = new Pow();
$nonce = 0;

echo PHP_EOL, Config::getProductName(), ' Genesis Miner ', PHP_EOL;
echo Config::getProductCopyright(), PHP_EOL, PHP_EOL;

$env = $_ENV['ENVIRONMENT'] ?? 'dev';
Console::log('Working on chain: ' . $env);

// use the newest key pair
$account = new Account();
$acct = $account->getNewestAccount();
if ($acct === null) {
    // do we need to create a new key pair?
    $id = $account->create();
    $acct = $account->get($id);
    Console::log('new key pair created');
}
$publicKey = $acct['public_key'];
$privateKey = $acct['private_key'];

$candidateBlock = $block->genesis($env, $publicKey, $privateKey);
$blockHeader = $block->generateBlockHeader($candidateBlock);

while (1) {
    try {
        $result = $miner->mineBlock($blockHeader, $candidateBlock['difficulty'], 1, $nonce, false);
        if ($result['result'] === true) {
            break;
        }

        $nonce = $result['nonce'];

    } catch (Exception $e) {
        echo $e->getMessage(), "\n";
        exit(1);
    }
}

$candidateBlock['hash'] = $result['hash'];
$candidateBlock['nonce'] = dechex($result['nonce']);

$reason = $block->validateFullBlock($candidateBlock);
print_r($candidateBlock);
print_r($reason);

echo "\nFound in " . number_format($result['hashes'], 0) . " Hashes\n";
echo "Nonce: {$result['nonce']}\n";
echo "Hash: {$result['hash']}\n\n";

$verified = $pow->verifyPow($result['hash'], $blockHeader, dechex($result['nonce']));
if ($verified) {
    echo "--> Hash verified\n";
} else {
    echo "--X Hash mismatch\n";
}

