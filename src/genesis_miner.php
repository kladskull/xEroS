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

$blockHeader = '';
$difficulty = Config::getDefaultDifficulty();
$nonce = "0";
$account = [];

$publicKey = file_get_contents('public.key');
$privateKey = file_get_contents('private.key');

echo "\n" . Config::getProductName() . " Blockchain Dumper v" . Config::getVersion() . "\n";
echo Config::getProductCopyright() . "\n\n";

$currentHeight = $block->getCurrentHeight();
$blocks = [];
for (; $currentHeight > 0; $currentHeight--) {
    $bh = $block->getByHeight($currentHeight);
    array_unshift($blocks, $block->returnFullBlock($bh['block_id']));
}

print_r(json_encode($blocks));
