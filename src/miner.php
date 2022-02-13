<?php declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../bootstrap.php';

$block = new Block();
$transaction = new Transaction();
$miner = new Miner();
$pow = new Pow();
$http = new Http();
$ossl = new OpenSsl();
$transferEncoding = new TransferEncoding();
$address = new Address();
$script = new Script([]);
$peer = new Peer();

$account = [];

$publicKey = file_get_contents(__DIR__ . '/../../public.key');
$privateKey = file_get_contents(__DIR__ . '/../../private.key');

echo "\n" . Config::getProductName() . " Miner v" . Config::getVersion() . "\n";
echo Config::getProductCopyright() . "\n\n";

$miningUrl = 'http://localhost/';

// if there is no state, we're likely needing to sync from the network
$store = new KeyValStore();
$state = $store->getKey('state', '');
if ($state !== 'mine') {
    Log::console('Synchronization is required before mining may begin, is the sync cron running?');
    exit(0);
}

while (1) {
    $blockHeader = '';
    $difficulty = $block->getDifficulty();
    $nonce = 0;

    while (1) {
        try {
            // todo: change this to a command line option
            $work = $http->get($miningUrl . 'mining_work.php');
            if ($work !== false) {
                $packet = json_decode($work, true);
                if ($packet !== null) {
                    $prevBlock = $packet['data']['last_block'];
                    $transactions = $packet['data']['transactions'];

                    $date = time();
                    $height = (int)$prevBlock['height'] + 1;
                    $blockId = $block->generateId($prevBlock['block_id'], $date, $height);
                    $publicKeyRaw = $ossl->stripPem($publicKey);

                    // add reward block
                    $transactionId = $transaction->generateId($date, $blockId, $publicKey);
                    $partialAddress = $transferEncoding->binToHex($address->createPartial($ossl->formatPem($publicKeyRaw, false)));

                    // pay to public Key hash
                    $scriptText = 'mov ax,' . $partialAddress . ';adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;';
                    $toAddress = $address->create($publicKey);
                    $reward = $block->getRewardValue($height);
                    $reward = bcadd($block->getRewardValue($height), $transaction->calculateMinerFee($transactions), 0);
                    $lockHeight = $height + Config::getLockHeight();

                    $coinbase = [
                        'block_id' => $blockId,
                        'transaction_id' => $transactionId,
                        'date_created' => $date,
                        'public_key' => $publicKeyRaw,
                        'peer' => $peer->getUniquePeerId(),
                        'version' => Version::Coinbase,
                        'height' => $height,
                        Transaction::Inputs => [],
                        Transaction::Outputs => [
                            [
                                'tx_id' => '0',
                                'address' => $address->create($publicKey),
                                'value' => $reward,
                                'script' => $script->encodeScript($scriptText),
                                'lock_height' => $lockHeight,
                                'hash' => Hash::doubleSha256ToBase58($transactionId . '0' . $toAddress . $reward . $lockHeight),
                            ]
                        ],
                    ];

                    $coinbase['signature'] = $transaction->signTransaction(
                        $coinbase,
                        $publicKey,
                        $privateKey
                    );

                    // put the coinbase as the first transaction
                    array_unshift($transactions, $coinbase);

                    $validTransactions = [];
                    foreach ($transactions as $tx) {
                        $result = $transaction->validate($tx);
                        if ($result['validated'] === true) {
                            $validTransactions[] = $tx;
                        } else {
                            print_r($result);
                            exit(0);
                        }
                    }

                    // calculate the merkle root
                    $validTransactions = Transaction::sort($validTransactions); // sort the array
                    $merkleRoot = $block->computeMerkleHash($validTransactions);

                    $newBlock = [
                        'network_id' => Config::getNetworkIdentifier(),
                        'block_id' => '',
                        'previous_block_id' => $prevBlock['block_id'],
                        'date_created' => $date,
                        'height' => $height,
                        'difficulty' => $difficulty, // use the current block to decide
                        'merkle_root' => $merkleRoot,
                        'transaction_count' => count($validTransactions),
                        'transactions' => $validTransactions,
                        'nonce' => '',
                        'hash' => '',
                        'previous_hash' => $prevBlock['hash'],
                    ];
                    $newBlock['block_id'] = $block->generateId($newBlock['previous_block_id'], $date, $height);
                    $blockHeader = $block->generateBlockHeader($newBlock);

                    // assembled the block, now find a hash
                    $result = $miner->mineBlock($blockHeader, $difficulty, $height, $miningUrl, $nonce);

                    // hash found
                    if ($result['result'] === true && !empty($result['nonce'])) {
                        $newBlock['nonce'] = dechex($result['nonce']);
                        $newBlock['hash'] = $result['hash'];
                        break;
                    }

                    // something changed
                    if ($result['result'] === false && empty($result['nonce'])) {
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            echo $e->getMessage(), "\n";
            exit(1);
        }
    }

    if ($result['result']) {
        $verified = $pow->verifyPow($result['hash'], $blockHeader, dechex($result['nonce']));
        if ($verified) {
            Log::console('Block proof of work accepted for new block');
            $result = $block->validateFullBlock($newBlock);
            if ($result['validated']) {
                Log::console('***** NEW BLOCK FOUND *****');
                Log::console('Storing new block with block id ' . $newBlock['block_id']);
                $newBlock = json_encode($newBlock);

                // storing locally
                $result = $http->post($miningUrl . 'block.php', $newBlock);
            }
        }
    }
}

