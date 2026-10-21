<?php declare(strict_types=1);

namespace Blockchain;

use Throwable;

define('PROGRAM', 'Wallet');
include 'bootstrap.php';

echo PHP_EOL, Config::getProductName(), ' Wallet ', PHP_EOL;
echo Config::getProductCopyright(), PHP_EOL, PHP_EOL;

$usage = "usage:\n" .
    "  php send.php balance\n" .
    "  php send.php encrypt     encrypt the stored private keys with WALLET_PASSPHRASE\n" .
    "  php send.php <address> <amount in coins> [node ip] [node port]\n\n" .
    "Pays from the newest account (the one the miner uses) through a running node.\n";

if (($argv[1] ?? '') === 'encrypt') {
    try {
        echo 'Encrypted ', (new Account())->encryptStoredKeys(Config::getWalletPassphrase()), ' private keys.', PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        echo $e->getMessage(), PHP_EOL;
        exit(1);
    }
}

try {
    $account = (new Account())->getNewestAccount();
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
    exit(1);
}

if ($account === null) {
    echo "No account yet, run the miner once to create one.\n";
    exit(1);
}

$wallet = new Wallet();

if (($argv[1] ?? '') === 'balance') {
    echo 'Address: ', $account['address'], PHP_EOL;
    echo 'Spendable: ', Wallet::toCoins($wallet->getBalance($account['address'])), PHP_EOL;
    exit(0);
}

if (count($argv) < 3) {
    echo $usage;
    exit(1);
}

$amount = Wallet::toUnits($argv[2]);

if ($amount === null) {
    echo "Invalid amount, use coins with up to 8 decimals, e.g. 1.5\n";
    exit(1);
}

$ip = $argv[3] ?? Config::getListenAddress();
$ip = $ip === '0.0.0.0' ? '127.0.0.1' : $ip;
$port = (int)($argv[4] ?? Config::getListenPort());

try {
    $transaction = $wallet->createTransfer($account, $argv[1], $amount);
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
    exit(1);
}

$client = @stream_socket_client('tcp://' . $ip . ':' . $port, $errorCode, $errorMessage, 5);

if ($client === false) {
    echo 'Cannot reach the node at ', $ip, ':', $port, ': ', $errorMessage, PHP_EOL;
    exit(1);
}

// the node handles packets in order, so the handshake and the transaction can go together
$packets = [
    ['type' => 'handshake', 'network' => Config::getNetworkIdentifier(), 'version' => Config::getVersion()],
    ['type' => 'handshake_ok'],
    ['type' => 'mempool_transaction', 'transaction' => $transaction],
];

foreach ($packets as $packet) {
    fwrite($client, json_encode($packet, JSON_THROW_ON_ERROR) . "\n");
}

// wait for the node to say whether it took the transaction
stream_set_timeout($client, 10);
$accepted = null;

while ($accepted === null && ($line = fgets($client)) !== false) {
    $data = json_decode($line, true);

    if (is_array($data) && ($data['type'] ?? '') === 'mempool_transaction_result'
        && ($data['transaction_id'] ?? '') === $transaction['transaction_id']) {
        $accepted = (bool)$data['accepted'];
    }

    if (is_array($data) && ($data['type'] ?? '') === 'error') {
        echo 'Node error: ', $data['message'] ?? '', PHP_EOL;
        break;
    }
}

fclose($client);

if ($accepted !== true) {
    echo $accepted === false ? "The node refused the transaction.\n" : "No answer from the node.\n";
    exit(1);
}

echo 'Sent ', Wallet::toCoins($amount), ' to ', $argv[1], PHP_EOL;
echo 'Transaction: ', $transaction['transaction_id'], PHP_EOL;
