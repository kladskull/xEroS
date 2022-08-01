<?php declare(strict_types=1);

namespace Blockchain;

use Exception;

define('PROGRAM', 'Miner');
include 'bootstrap.php';

$account = new Account();
$block = new Block();
$pow = new Pow();
$miner = new Miner();
$queue = new Queue();

echo PHP_EOL, Config::getProductName(), ' Miner ', PHP_EOL;
echo Config::getProductCopyright(), PHP_EOL, PHP_EOL;

$createKeypair = false;
$forceMining = false;
foreach ($argv as $arg) {
    $arg = strtolower(trim($arg));
    switch ($arg) {
        case '-d':
        case '--developer':
            Console::log('********************************************************************************************');
            Console::log('* ');
            Console::log('* WARNING!!! Forcing the miner to just use local data without a completed blockchain sync...');
            Console::log('* ');
            Console::log('********************************************************************************************');
            $forceMining = true;
            break;
        case '-c':
        case '--create-keypair':
            $createKeypair = true;
            break;

        case '-v':
        case '--version':
            echo 'Version: ', Config::getVersion(), PHP_EOL;
            exit(0);
        case '-h':
        case '--help':
            echo "-c --create-keypair   :   create and use a new keypair\n";
            echo "-d --developer        :   used for mining from the genesis block (never should be\n";
            echo "                      :   needed outside of development)\n";
            echo "-v --version          :   get app version\n";
            echo "-h --help             :   show this help\n";
            exit(0);
    }
}

// use the newest key pair
$acct = $account->getNewestAccount();
if ($acct === null || $createKeypair) {
    // do we need to create a new key pair?
    $id = $account->create();
    $acct = $account->get($id);
    Console::log('new key pair created');
}
$publicKey = $acct['public_key'];
$privateKey = $acct['private_key'];

Console::log('detected ' . PHP_OS . ' as the OS');

// if there is no state, we're likely needing to sync from the network
$store = new DataStore();
$state = $store->getKey('state', '');
if ($state !== 'mine' && $forceMining === false) {
    Console::log('Synchronization is required before mining may begin');
    exit(0);
}

$env = $_ENV['ENVIRONMENT'] ?? 'dev';
Console::log('Working on chain: ' . $env);

while (1) {
    $nonce = 0;
    $height = $block->getCurrentHeight() + 1;
    $candidateBlock = $block->getCandidateBlock($height, $publicKey, $privateKey);
    $blockHeader = $block->generateBlockHeader($candidateBlock);

    // inner loop, only exit on an event
    while (1) {
        // assembled the block, now find a hash
        $result = $miner->mineBlock($blockHeader, $candidateBlock['difficulty'], $height, $nonce);

        // something changed
        if ($result['result'] === false && empty($result['nonce'])) {
            break;
        }

        // hash found
        if ($result['result'] === true && !empty($result['nonce'])) {
            $candidateBlock['nonce'] = dechex($result['nonce']);
            $candidateBlock['hash'] = $result['hash'];
            $verified = $pow->verifyPow($result['hash'], $blockHeader, dechex($result['nonce']));

            if ($verified) {
                Console::log('Block proof of work accepted for new block');
                try {
                    $result = $block->validateFullBlock($candidateBlock);
                    if ($result['validated']) {
                        Console::log('***** NEW BLOCK FOUND *****');
                        Console::log('Storing new block with block id ' . $candidateBlock['block_id']);

                        // store and accept locally
                        $block->add($candidateBlock);
                        $block->acceptBlock($candidateBlock['block_id'], $candidateBlock['height']);

                        // add queue item to send it out
                        $queue->add('new_block', $candidateBlock['block_id']);
                        break;
                    }
                } catch (Exception $e) {
                    Console::log('Exception in miner: ' . $e->getMessage());
                }
            }
        }
    }
}
