<?php declare(strict_types=1);

namespace Xeros;

require __DIR__ . '../vendor/autoload.php';
require __DIR__ . '../bootstrap.php';

$miningUrl = 'http://localhost/';

$transaction = new Transaction();
$address = new Address();
$ossl = new OpenSsl();
$transferEncoding = new TransferEncoding();
$script = new Script([]);
$http = new Http();
$peer = new Peer();

echo "\n" . Config::getProductName() . " Genesis Miner v" . Config::getVersion() . "\n";
echo Config::getProductCopyright() . "\n\n";

$date = time();
$publicKey = file_get_contents(__DIR__ . '/../../public.key');
$privateKey = file_get_contents(__DIR__ . '/../../private.key');

$miningData = $http->get($miningUrl . 'mining_info.php');
$data = json_decode($miningData, true, 512, JSON_THROW_ON_ERROR);
$currentHeight = $data['height'];

// prepare script variables - we're going to use PayToPublicKeyHash to hide the public key

$publicKeyRaw = $ossl->stripPem($publicKey);
$transactionId = $transaction->generateId($date, '', $publicKeyRaw);
$partialAddress1 = $transferEncoding->binToHex($address->createPartial('-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAomFwRrFzpckBQkxvTzz8
m9H6BLQyDK8ynRAE2OqfDW+imt2YyIK0LsoPjs0S7PCUqa807sXMB+BkPiCsrfjS
+Pc5w1VjYNjGwX0TDkd9sHQjrnOq7+eFe1GXW/JWW51nuBT2YsbXzSo9MYDlETZJ
NGRatuUj58x4AZand2J/KCtfk49NhYhU6kj9EYY8otrMtICZdy+Umd+OuBO2KWxZ
3H2Re8ng63pqjuO+73Nkv1/igDhuKvSV5spQElitk8vN7HHOO6hHseoXwrlfUvqn
OT6hWYn5vR9z5HvmBzS3zMC53SfVRcBLbRUx0fmsQhD0Y8OSLojaoSO4fBUo1n9S
twIDAQAB
-----END PUBLIC KEY-----
'));
$partialAddress2 = $transferEncoding->binToHex($address->createPartial('-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAoRlHheqr5lUrQXUjkrVR
pxgRyyiVDo4I3EgsO3jzrfiJoXtLO4dl9P4F4XVmBAhJwWgifJYUA8h3ORBnRR+q
K4h+APdLBJxZ+mWGe686ooEm5Joff9IgFn2gN6fYruraFYdNInQIV1d79mEoAJYS
1J6r25WUHlaPXU+6pNp0CyFi7x6RN+xrr4NskbSEmwjssslCiLL2xqxDyINXerUA
4HGbIIIGI5XlbO3C5rRRKnIH6d2ChIBqDSZ8tgd/ddzKb4rcaOBziwhYREyo9a3U
zDRve0pp2h/h9P1UmULqp56yapa8+61JCR2Z1LSapms9AoDECxMsoQXt8uiJp9gt
1wIDAQAB
-----END PUBLIC KEY-----
'));

// locking scripts
$outScript1 = $script->encodeScript('mov ax,' . $partialAddress1 . ';adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;');
$outScript2 = $script->encodeScript('mov ax,' . $partialAddress2 . ';adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;');

// unlocking script
$scriptHash = '7jUPw8DgusndhurCtD3YdpcbrgvN5TLSM8UzZqu345Fk';
$scriptSig = $ossl->signAndVerifyData($scriptHash, $publicKey, $privateKey);
$inScript = 'push ' . $scriptSig . ';push ' . $publicKeyRaw . ';dup;';

$transactionRecord = [
    'transaction_id' => $transactionId,
    'date_created' => $date,
    'public_key' => $publicKeyRaw,
    'version' => Version::Transfer,
    'height' => $currentHeight + 1,
    'peer' => $peer->getUniquePeerId(),
    Transaction::OUTPUTS => [
        [
            'tx_id' => 0,
            'address' => 'Bc12ZJd4TGoKDhJEJXFJ4geu3dsjjDxonGXs', // address id: 22 (block 5678)
            'value' => '2500000000', // 25 coin
            'lock_height' => '0',
            'script' => $outScript1,
            'hash' => Hash::doubleSha256ToBase58($transactionId . '0' . 'Bc12ZJd4TGoKDhJEJXFJ4geu3dsjjDxonGXs' . '2510000000' . '0'),
        ],
        [
            'tx_id' => 1,
            'address' => 'Bc1BaWb8V13hNYWRzSDYf6Jwj6ytkqVhQUiC', // address id: 21
            'value' => '2490000000', // 24.9 coin to another address
            'lock_height' => '0',
            'script' => $outScript2,
            'hash' => Hash::doubleSha256ToBase58($transactionId . '1' . 'Bc1BaWb8V13hNYWRzSDYf6Jwj6ytkqVhQUiC' . '2490000000' . '0'),
        ],
    ],
    Transaction::INPUTS => [
        [
            'tx_id' => 0,
            'previous_transaction_id' => 'Bi6GupYi4g1xGQrkcXnJjf57SAuFFTmn3oFhBVTerXba',
            'previous_tx_out_id' => '0',
            'script' => $script->encodeScript($inScript),
        ]
    ],
];

//print_r($transactionRecord);
// sign the transaction and send it
$transactionRecord['signature'] = $transaction->signTransaction($transactionRecord, $publicKey, $privateKey);

$packet = [
    'server' => Config::getProductName(),
    'time' => time(),
    'data' => $transactionRecord,
];

$post = json_encode($packet, JSON_THROW_ON_ERROR);
print_r($http->post($miningUrl . 'mempool.php', $post));