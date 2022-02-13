<?php declare(strict_types=1);

namespace Xeros;

use PDO;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;

class Block
{
    public const maxLifeTimeBlocks = 9999999999999;

    private PDO $db;
    private Pow $pow;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->pow = new Pow();
    }

    public static function filterBlockHeight(int $height): int
    {
        if ($height <= 0 || $height > self::maxLifeTimeBlocks) {
            $height = 1;
        }
        return $height;
    }

    /**
     * @throws Exception
     */
    public function genesis(): array
    {
        $transaction = new Transaction();
        $openSsl = new OpenSsl();

        // block details
        $height = 1;
        $date = 1644364863;
        $previousBlockId = '';

        $publicKeyRaw = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA2rurp8hen6Wepcz0X+4kWqtqEk7e+46Mx4hjDDlYw2j7XXiEpSHAz0ZWwKfDlBSBV8yYVBSGI+URjoBn7+ZH/cuHaSNCxg5JTxSG5DyWgeG1OHUnILnfLdJpo0H/mGscVdf/Nws21j/XbG9eXICFIVfojKKqZWLax8XLyuf/Gl4Oj7RAuRVseN7CRiq73x8kcMSzUgLyitefWaH1GxmATTm3ygey5itn8ddf4iow78lM56hPHXl5id0JV+WsRL6QbuFvrC5Eo42iAyN0dsHrpqkK1+2fKVrfedJy3aa6LqjQZdfebJtw4PCdKBpn1ZVIeDJILy2lQUuBXu52Qc93QQIDAQAB';

        //$publicKeyRaw = $openSsl->stripPem(file_get_contents(__DIR__ . '/../../public.key'));
        //$privateKeyRaw = $openSsl->stripPem(file_get_contents(__DIR__ . '/../../private.key'));

        $hash = '0000000ddc4bae812455272176cdf3c8b7ed8d6a1e0eb7ba2709c14741fbb732';
        $merkleRoot = '737a608f997acacaad87a2665ff32dfc5c436963cb9b15096a435f341266fa33';
        $signature = '0287167c3f5fb62a9a416a414a008ee322a337ca8650b114c146e014be36769855ecaf019b37b20ccaaade079ead27d317156d4da3b3824029711d7acfed0dd1d88ec9faaf77da9184c968b7503e81fa504d5271592f75c4d9d9232d3e6627752add52384228e1ca01163b3be1036ab9672ca9eae7a58eded6ea3afb9df3333638f6a2fd6c261db3fe6e5ae8ca3d9848f19d03c5b42868f088b62a74ef867dd63654bcb9b28c0757828254102799968a8a7e8680752b50e33d4527e59009c4c065d23ac9c8230b6bb692574ae05c597799f19c0a50ae01d8dea3de39ed6274519f6a10964aa37970f5a35c2e4ebf9935a5898ff79762d23f8557365385142814';
        $nonce = '5406e60';

        // create a block ID
        $blockId = $this->generateId($previousBlockId, $date, $height);

        // transaction details
        $amount = $this->getRewardValue(1);

        // prepare script
        $address = new Address();
        $transferEncoding = new TransferEncoding();
        $script = new Script([]);
        $partialAddress = $transferEncoding->binToHex($address->createPartial($openSsl->formatPem($publicKeyRaw, false)));
        $scriptText = 'mov ax,' . $partialAddress . ';adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;rem 466F7274756E65202D2043727970746F2069732066756C6C792062616E6E656420696E204368696E6120616E642038206F7468657220636F756E7472696573202D204A616E7561727920342C2032303232;';
        $txId = 0;
        $lockHeight = $height + Config::getLockHeight();
        $toAddress = $address->create($openSsl->formatPem($publicKeyRaw, false));

        $transactionId = $transaction->generateId($date, $blockId, $publicKeyRaw);
        $transactionRecord = [
            'id' => 1,
            'block_id' => $blockId,
            'transaction_id' => $transactionId,
            'date_created' => $date,
            'public_key' => $publicKeyRaw,
            'peer' => 'genesis',
            'version' => TransactionVersion::Coinbase,
            'height' => 1,
            Transaction::Inputs => [],
            Transaction::Outputs => [
                [
                    'tx_id' => $txId,
                    'address' => $toAddress,
                    'value' => $amount,
                    'script' => $script->encodeScript($scriptText),
                    'lock_height' => $lockHeight,
                    'hash' => $this->pow->doubleSha256ToBase58($transactionId . $txId . $toAddress . $amount . $lockHeight),
                ]
            ],
        ];

        // sign
        $transactionRecord['signature'] = $signature;
        //$transactionRecord['signature'] = $transaction->signTransaction($transactionRecord, $ossl->formatPem($publicKeyRaw, false), $ossl->formatPem($privateKeyRaw, true));

        return [
            'network_id' => Config::getNetworkIdentifier(),
            'block_id' => $blockId,
            'previous_block_id' => '',
            'date_created' => $date,
            'height' => $height,
            'difficulty' => Config::getDefaultDifficulty(),
            'merkle_root' => $merkleRoot,
            'transactions' => [
                $transactionRecord
            ],
            'transaction_count' => 1,
            'previous_hash' => '',
            'hash' => $hash,
            'nonce' => $nonce
        ];
    }

    public function generateId(string $previousBlockId, int $date, int $height): string
    {
        return $this->pow->doubleSha256ToBase58(
            $previousBlockId . $date . $height
        );
    }

    public function get(int $id): ?array
    {
        // prepare the statement
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transactions`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query, PDO::FETCH_ASSOC);

        // filter and bind parameters
        $id = self::filterBlockHeight(filter_var($id, FILTER_SANITIZE_NUMBER_INT));
        $stmt->bindParam(param: ':id', var: $id, type: PDO::PARAM_INT);

        // execute the query
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    public function getCurrentHeight(): int
    {
        // prepare the statement
        $query = 'SELECT `height` FROM blocks ORDER BY `height` DESC LIMIT 1';
        $stmt = $this->db->query($query, PDO::FETCH_ASSOC);

        // execute the query
        return max(1, $stmt->fetchColumn());
    }

    public function getByBlockId(string $blockId): ?array
    {
        // prepare the statement
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transactions`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `block_id` = :block_id LIMIT 1';
        $stmt = $this->db->prepare($query, PDO::FETCH_ASSOC);

        // filter and bind parameters
        $blockId = preg_replace("/[^a-zA-Z0-9]/", '', $blockId);
        $stmt->bindParam(param: ':block_id', var: $blockId, maxLength: 64);

        // execute the query
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    public function getByPreviousBlockId(string $previousBlockId): ?array
    {
        // prepare the statement
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transactions`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `previous_block_id` = :previous_block_id LIMIT 1';
        $stmt = $this->db->prepare($query, PDO::FETCH_ASSOC);

        // filter and bind parameters
        $previousBlockId = preg_replace("/[^a-zA-Z0-9]/", '', $previousBlockId);
        $stmt->bindParam(param: ':previous_block_id', var: $previousBlockId, maxLength: 64);

        // execute the query
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    public function getByHeight(int $height): ?array
    {
        // prepare the statement
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transactions`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `orphan` = 0 AND `height` = :height LIMIT 1';
        $stmt = $this->db->prepare($query, PDO::FETCH_ASSOC);

        // filter and bind parameters
        $height = self::filterBlockHeight(filter_var($height, FILTER_SANITIZE_NUMBER_INT));
        $stmt->bindParam(param: ':height', var: $height, type: PDO::PARAM_INT);

        // execute the query
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    public function getCurrent(): ?array
    {
        // prepare the statement
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transactions`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `orphan`=0 ORDER BY height DESC LIMIT 1';
        $stmt = $this->db->query($query, PDO::FETCH_ASSOC);

        // execute the query
        return max(1, $stmt->fetchColumn());
    }

    private function injectConfirmationsAndClean(array &$blockData): void
    {
        /**
         * You were looking why we define a new block, and then wondering if we need to return the FULL block array
         */
        $block = new Block();
        $currentHeight = $block->getCurrentHeight();

        unset($blockData['id'], $blockData['orphan']);

        // add confirmations
        $blockData['confirmations'] = $currentHeight - $blockData['height'];
    }

    public function returnFullBlock(string $blockId, bool $previousBlockId = false): array
    {
        $transaction = new Transaction();
        if (!$previousBlockId) {
            $currBlock = $this->getByBlockId($blockId);
        } else {
            $currBlock = $this->getByPrevBlockId($blockId);
        }
        if (count($currBlock)) {
            $currBlock['transactions'] = $transaction->getTransactionsByBlockId($blockId);
            $this->injectConfirmationsAndClean($currBlock);
        } else {
            $currBlock = [];
        }
        return $currBlock;
    }

    /**
     * @throws Exception
     */
    public function validateFullBlock(array $block): array
    {
        $transactions = $block['transactions'];
        unset($block['transactions']);
        return $this->validate($block, $transactions, count($transactions));
    }

    #[ArrayShape(['validated' => "bool", 'reason' => "string"])]
    private function returnValidateError(string $reason, bool $result): array
    {
        return [
            'validated' => $result,
            'reason' => $reason
        ];
    }

    function verifyRemoteBlock(array $blk, Peer $peer, Block $block): bool
    {
        $result = false;

        // we need to check the block other peers...
        $count = 0;
        $correct = 0;

        // check the block against all of our peers
        foreach ($peer->getAll(Config::getMaxRebroadcastPeers()) as $p) {
            $checkBlk = $block->getRemoteBlockById($p['address'], $blk['block_id']);
            if ($checkBlk['hash'] === $blk['hash'] && $checkBlk['previous_hash'] === $blk['previous_hash']) {
                $correct++;
            }
            $count++;
        }

        // check the score and add the block
        $score = $correct / $count * 100;
        if ($score >= 51) {
            $result = true;
        }

        return $result;
    }

    public function computeMerkleHash(array $transactions): ?string
    {
        // sort it
        $transactions = Transaction::sort($transactions); // sort the array

        // calculate the merkle root
        $tree = new drupol\phpmerkle\Merkle();
        foreach ($transactions as $tx) {
            $tree[] = $tx['transaction_id'];
        }

        // compute the merkle root
        return $tree->hash();
    }

    /**
     * @throws Exception
     */
    public function validate(array $block, array $transactions, int $transactionCount): array
    {
        if ($block['network_id'] !== Config::getNetworkIdentifier()) {
            return $this->returnValidateError('networkId mismatch', false);
        }

        // ensure a sane time
        if ($block['date_created'] > time() + 60) {
            return $this->returnValidateError('block from the future', false);
        }

        // no transactions before the genesis
        if ($block['date_created'] < Config::getGenesisDate()) {
            return $this->returnValidateError('block precedes genesis block', false);
        }

        // check difficulty
        if ($this->getDifficulty((int)$block['height']) !== (int)$block['difficulty']) {
            return $this->returnValidateError('difficulty difference', false);
        }

        // check if the previous block values match (if not we have an orphan)
        /*if ($block['height'] > 1) {
            $prevBlock = $this->getByHeight($block['height'] - 1);
            if ($block['previous_block_id'] !== $prevBlock['block_id'] || $block['previous_hash'] !== $prevBlock['hash']) {
                return $this->returnValidateError('previous hash mismatch', false);
            }
        }*/

        // check the proof of work
        $pow = new Pow();
        if (!$pow->verifyPow($block['hash'], $this->generateBlockHeader($block), $block['nonce'])) {
            return $this->returnValidateError("Proof of work fail", false);
        }

        // we must have all the transactions
        $transactions = Transaction::sort($transactions);
        if ($transactionCount !== count($transactions)) {
            return $this->returnValidateError("transaction count mismatch", false);
        }

        // check for a valid height
        if ($block['height'] < 1) {
            return $this->returnValidateError("invalid height", false);
        }

        // check the transactions
        $t = new Transaction();
        $coinbaseRecords = 0;

        foreach ($transactions as $transaction) {
            if ($transaction['version'] === TransactionVersion::Coinbase) {
                $coinbaseRecords++;
            }
            $reason = $t->validate($transaction);
            if (!$reason) {
                return $reason;
            }
        }

        if ($coinbaseRecords !== 1) {
            return $this->returnValidateError("blocks must have exactly 1 coinbase record.", false);
        }

        // test the merkle root
        if ($block['merkle_root'] !== $this->computeMerkleHash($transactions)) {
            return $this->returnValidateError("merkle root issue", false);
        }

        return $this->returnValidateError("ok", true);
    }

    // add a new block to the db
    public function add(array $block): int
    {
        $transaction = new Transaction();

        // remove whatever is there...
        //$this->delete($block['block_id']);

        $id = 0;
        try {
            $this->db->startTransaction();

            // lock table to avoid race conditions on blocks?
            $this->db->query("LOCK TABLES accounts WRITE, blocks WRITE, transactions WRITE,transaction_inputs WRITE,transaction_outputs WRITE,peers WRITE;");

            // add the transactions
            foreach ($block['transactions'] as $t) {
                $t['block_id'] = $block['block_id'];
                $id = $transaction->add($t);
                if ($id <= 0) {
                    throw new MeekroDBException("failed to add transaction");
                }
            }

            // remove transactions & confirmations
            unset($block['transactions'], $block['confirmations']);

            // add the block
            $this->db->replace('blocks', $block);
            $id = $this->db->insertId();

            // release the locking as everything is finished
            $this->db->query("UNLOCK TABLES");
            $this->db->commit();

        } catch (MeekroDBException|Exception $ex) {
            $this->db->query("UNLOCK TABLES");
            $this->db->rollback();
            var_dump($ex->getMessage());
        }

        return $id;
    }


    // add a new block to the db
    public function identifyOrphans(int $height, string $blockId): void
    {
        try {
            $this->db->startTransaction();
            $this->db->query("UPDATE blocks SET orphan=1 WHERE height=%i AND block_id != %s;", $height, $blockId);
            $this->db->query("UPDATE blocks SET orphan=0 WHERE height=%i AND block_id = %s;", $height, $blockId);
            $this->db->commit();

        } catch (MeekroDBException|Exception $ex) {
            var_dump("Block302: " . $ex->getMessage());
            $this->db->rollback();
        }
    }

    #[Pure]
    function getRewardValue(int $nHeight): string
    {
        if ($nHeight <= 0) {
            $nHeight = 1;
        }
        $strHeight = (string)$nHeight;

        $targetModulus = (3600 / Config::getDesiredBlockTime()) * 24 * 365;
        $reductions = bcdiv($strHeight, (string)$targetModulus, 0);

        $nSubsidy = Config::getDefaultBlockReward(); // 100 coins

        if (bccomp($reductions, "0") === 1) {
            $reductions = (int)$reductions;
            for ($i = 0; $i < $reductions; $i++) {
                $reduction = bcmul($nSubsidy, "0.04");
                $nSubsidy = bcsub($nSubsidy, $reduction, 0);
                if (bccomp($nSubsidy, '100000000') <= 0) {
                    break;
                }
            }
        }

        return $nSubsidy;
    }

    public function getBlockTime($currentTimeSeconds, $previousTimeSeconds, $blocksCreated): float
    {
        return ceil(($currentTimeSeconds - $previousTimeSeconds) / $blocksCreated);
    }

    /**
     * calculates the difficulty
     *
     * the higher the difficulty number, the harder it is.
     * the lower the difficulty number, the easier it is.
     *
     * @param int $height
     * @param array|null $latestBlock
     * @param array|null $oldestBlock
     * @return int
     */
    public function getDifficulty(int $height = 0, array $latestBlock = null, array $oldestBlock = null): int
    {
        // get the current height, if not given
        if ($height === 0) {
            $height = $this->getCurrentHeight();
        }

        // get blocks per period
        $blocksPerPeriod = (int)(3600 / Config::getDesiredBlockTime()) * 24;

        // if less than 144 use the genesis difficulty
        if ($height < $blocksPerPeriod) {
            $genesisBlock = $this->getByHeight(1);
            return (int)$genesisBlock['difficulty'];
        }

        // get the desired block height and get the current difficulty
        if (empty($latestBlock)) {
            $latestBlock = $this->getByHeight($height);
            if (empty($latestBlock)) {
                $latestBlock = $this->getByHeight($this->getCurrentHeight());
            }
        }

        // use the difficulty of the latest block
        $difficulty = (int)$latestBlock['difficulty'];

        // adjust when it's been 144
        if ($height % $blocksPerPeriod === 0) {
            if ($oldestBlock === null) {
                $oldestBlock = $this->getByHeight(max(1, $height - $blocksPerPeriod));
            }

            $blockTime = $this->getBlockTime($latestBlock['date_created'], $oldestBlock['date_created'], $blocksPerPeriod);

            // block time was quick, increase difficulty
            if ($blockTime <= Config::getDesiredBlockTime() - 30) {
                ++$difficulty;
            }

            // block time was slow, decrease difficulty
            if ($blockTime >= Config::getDesiredBlockTime() + 30) {
                --$difficulty;
            }
        }

        // never go less than the initial difficulty
        $minimumDifficulty = Config::getDefaultDifficulty();
        if ($difficulty < $minimumDifficulty) {
            $difficulty = $minimumDifficulty;
        }

        // max out at 255 ~ almost impossible
        if ($difficulty > 255) {
            $difficulty = 255;
        }

        return $difficulty;
    }

    public function generateBlockHeader(array $block): string
    {
        return $block['network_id'] . $block['block_id'] . $block['previous_block_id'] . $block['date_created'] .
            $block['height'] . $block['difficulty'] . $block['merkle_root'] . $block['transaction_count'] . $block['previous_hash'];
    }

    public function deleteSeries(string $blockId): void
    {
        while (1) {
            $this->delete($blockId);
            $nextBlockId = $this->db->queryFirstField('select block_id from blocks where previous_block_id=%s', $blockId);
            if ($nextBlockId === null) {
                break;
            }
            $blockId = $nextBlockId;
        }
    }

    public function delete(string $blockId): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();

            $this->db->delete('blocks', "block_id=%s", $blockId);

            $transactions = $this->db->query('SELECT transaction_id FROM transactions WHERE block_id=%s;', $blockId);
            foreach ($transactions as $transaction) {
                $this->db->delete('transactions', "transaction_id=%s", $transaction['transaction_id']);

                // REVERSE ANY SPENT TRANSACTIONS!!
                $spentItems = $this->db->query('SELECT previous_transaction_id, previous_tx_out_id from transaction_inputs WHERE transaction_id=%s;', $transaction['transaction_id']);
                foreach ($spentItems as $spentItem) {
                    $this->db->query(
                        'UPDATE transaction_outputs SET spent=0 WHERE transaction_id=%s AND tx_id=%i',
                        $spentItem['transaction_id'],
                        (int)$spentItem['tx_id'],
                    );
                }

                // clear the transactions
                $this->db->delete('transaction_inputs', "transaction_id=%s", $transaction['transaction_id']);
                $this->db->delete('transaction_outputs', "transaction_id=%s", $transaction['transaction_id']);
            }
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception $ex) {
            $this->db->rollback();
        }
        return $result;
    }

    /**
     * @throws JsonException
     */
    public function getRemoteBlockByHeight(string $peer, int $height)
    {
        $http = new Http();

        $response = $http->get($peer . 'block.php?height=' . $height);
        $data = Api::decodeResponse($response);

        return $data['data'] ?? [];
    }

    /**
     * @throws JsonException
     */
    public function getRemoteBlockById(string $peer, string $blockId = '')
    {
        $http = new Http();

        $query = '';
        if (!empty($blockId)) {
            $query = '?block_id=' . $blockId;
        }

        $response = $http->get($peer . 'block.php' . $query);
        $data = Api::decodeResponse($response);

        return $data['data'] ?? [];
    }
}