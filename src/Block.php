<?php declare(strict_types=1);

namespace Xeros;

use PDO;
use Exception;
use JetBrains\PhpStorm\ArrayShape;
use JetBrains\PhpStorm\Pure;
use RuntimeException;

class Block
{
    public const maxLifeTimeBlocks = 9999999999999;

    private Address $address;
    private Mempool $mempool;
    private Merkle $merkle;
    private OpenSsl $openSsl;
    private PDO $db;
    private Peer $peer;
    private Pow $pow;
    private Script $script;
    public Transaction $transaction;
    private TransferEncoding $transferEncoding;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->address = new Address();
        $this->openSsl = new OpenSsl();
        $this->peer = new Peer();
        $this->script = new Script([]);
        $this->merkle = new Merkle();
        $this->transaction = new Transaction();
        $this->transferEncoding = new TransferEncoding();
        $this->mempool = new Mempool();
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
        // block details
        $height = 1;
        $date = 1644364863;
        $previousBlockId = '';

        //$publicKeyRaw = $openSsl->stripPem(file_get_contents(APP_DIR. 'public.key'));
        //$privateKeyRaw = $openSsl->stripPem(file_get_contents(APP_DIR . 'private.key'));

        $publicKeyRaw = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA6iS30IjUFtg5UdqGn7bMWtO2yf3nAhG85xJK9Ro0DByJhr6WB94TDhrAMjWK4bwcYO08M6yHvsO6wVUxB6KuONNYrEHOmHqA3lYS+Kz9dfwWN+ymwLbOgDevZ33PKahV6JpXTu5wHKFdPJctLcaWmy0gI2YjsrrZw8mXiiArNOT3662UvZdFgyMM1SSuPoE/ZXvDjRIXz/4RMnKCE1GH5cbtBheC1yy8XvuZkK9IN+he1osz29hEFlYVtIF6g+wh59ZGxzrZoA7bcO5mGY491mZFCzHMu0nVXnokAsiKx4YK0KxyV5ETk4P8qili7MizbKVhS9mUinVkU9l16dhreQIDAQAB';
        $hash = '0000000c7a00d1ea28b8986d5a1b3226e4493d502f68b302d074c97489c06c67';
        $merkleRoot = '737a608f997acacaad87a2665ff32dfc5c436963cb9b15096a435f341266fa33';
        $signature = '0aa6338178f42f3fc6a688c7a284c6d9f3bd4f973c067e3c71d6f49d6a4ccab40753f799e6ea3ad61a678727dbd7329067af7c6626775083bbe323ee8baa61fb586808cb012fa3592018e0f1397ff636ad85c05553c1079f38b071e9d52b8da5c39fa85a12ebbdd6224e008addaaaa149932ceed3b8bb307937530f91a54edaf7095877bc13116c79ef1bb480e35812ad77e4c049ec6858f461017fd3c0ce27956971c13c73aca745b1d61f8a0700b0d5a17470c4d983622c525d6691a80d027e79018ae618ea8ab597c19e7d030b497fdffdeef308bc51d69e2ccc068223a713e67b2114fc609b70263a9562a72ab1ce182686447c2369ddc6c69295c476414';
        $nonce = '1192faa7';

        // create a block ID
        $blockId = $this->generateId(Config::getNetworkIdentifier(), $previousBlockId, $date, $height, $merkleRoot);

        // transaction details
        $amount = $this->getRewardValue(1);

        // prepare script
        $address = new Address();
        $transferEncoding = new TransferEncoding();
        $script = new Script([]);
        $partialAddress = $transferEncoding->binToHex($address->createPartial($this->openSsl->formatPem($publicKeyRaw, false)));
        $scriptText = 'mov ax,' . $partialAddress . ';adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;rem 466F7274756E65202D2043727970746F2069732066756C6C792062616E6E656420696E204368696E6120616E642038206F7468657220636F756E7472696573202D204A616E7561727920342C2032303232;';
        $txId = 0;
        $lockHeight = $height + Config::getLockHeight();
        $toAddress = $address->create($this->openSsl->formatPem($publicKeyRaw, false));

        $transactionId = $this->transaction->generateId($date, $blockId, $publicKeyRaw);
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
                    'block_id' => $blockId,
                    'tx_id' => $txId,
                    'address' => $toAddress,
                    'value' => $amount,
                    'script' => $script->encodeScript($scriptText),
                    'lock_height' => $lockHeight,
                    'hash' => bin2hex($this->pow->doubleSha256($transactionId . $txId . $toAddress . $amount . $lockHeight)),
                ]
            ],
        ];

        // sign
        $transactionRecord['signature'] = $signature;
        //$transactionRecord['signature'] = $transaction->signTransaction($transactionRecord, $openSsl->formatPem($publicKeyRaw, false), $openSsl->formatPem($privateKeyRaw, true));

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

    public function getCandidateBlock($height, $publicKey, $privateKey): array
    {
        $difficulty = $this->getDifficulty($height);
        $prevBlock = $this->getByHeight($height - 1);
        $transactions = $this->mempool->getAllTransactions($height);

        $date = time();
        $blockId = $this->generateId(Config::getNetworkIdentifier(), $prevBlock['block_id'], $date, $height);
        $publicKeyRaw = $this->openSsl->stripPem($publicKey);

        // add reward block
        $transactionId = $this->transaction->generateId($date, $blockId, $publicKey);
        $partialAddress = $this->transferEncoding->binToHex($this->address->createPartial($this->openSsl->formatPem($publicKeyRaw, false)));

        // pay to public Key hash
        $scriptText = 'mov ax,' . $partialAddress . ';adha ax;pop bx;adpk bx;vadr ax,bx;pop ax;pop bx;vsig ax,<hash>,bx;';
        $toAddress = $this->address->create($publicKey);
        $reward = bcadd($this->getRewardValue($height), $this->transaction->calculateMinerFee($transactions), 0);
        $lockHeight = $height + Config::getLockHeight();

        $coinbase = [
            'block_id' => $blockId,
            'transaction_id' => $transactionId,
            'date_created' => $date,
            'public_key' => $publicKeyRaw,
            'peer' => $this->peer->getUniquePeerId(),
            'version' => TransactionVersion::Coinbase,
            'height' => $height,
            Transaction::Inputs => [],
            Transaction::Outputs => [
                [
                    'tx_id' => '0',
                    'address' => $this->address->create($publicKey),
                    'value' => $reward,
                    'script' => $this->script->encodeScript($scriptText),
                    'lock_height' => $lockHeight,
                    'hash' => base64_encode($this->pow->doubleSha256($transactionId . '0' . $toAddress . $reward . $lockHeight)),
                ]
            ],
        ];

        try {
            $coinbase['signature'] = $this->transaction->signTransaction(
                $coinbase,
                $publicKey,
                $privateKey
            );
        } catch (Exception) {
        }

        // put the coinbase as the first transaction
        array_unshift($transactions, $coinbase);

        $validTransactions = [];
        foreach ($transactions as $tx) {
            try {
                $result = $this->transaction->validate($tx);
                if ($result['validated'] === true) {
                    $validTransactions[] = $tx;
                } else {
                    print_r($result);
                    exit(0);
                }
            } catch (Exception) {
            }
        }

        // calculate the merkle root
        $validTransactions = Transaction::sort($validTransactions); // sort the array
        $merkleRoot = $this->merkle->computeMerkleHash($validTransactions);

        return [
            'network_id' => Config::getNetworkIdentifier(),
            'block_id' => $blockId,
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
    }

    #[Pure]
    public function generateId(string $networkId, string $previousBlockId, int $date, int $height): string
    {
        return bin2hex($this->pow->doubleSha256(
            $networkId . $previousBlockId . $date . $height
        ));
    }

    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByBlockId(string $blockId): ?array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `block_id` = :block_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'block_id', $blockId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByPreviousBlockId(string $previousBlockId): ?array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `orphan`=0 AND `previous_block_id` = :previous_block_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'previous_block_id', $previousBlockId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getCurrentHeight(): int
    {
        $query = 'SELECT `height` FROM blocks WHERE `orphan`=0 ORDER BY `height` DESC LIMIT 1';
        $stmt = $this->db->query($query);
        return $stmt->fetchColumn() ?: 0;
    }

    public function getByHeight(int $height): ?array
    {
        // prepare the statement
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `orphan` = 0 AND `height` = :height LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'height', $height, DatabaseHelpers::INT, 0);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getCurrent(): ?array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `orphan`=0 ORDER BY height DESC LIMIT 1';
        $stmt = $this->db->query($query);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function assembleFullBlock(string $blockId, bool $previousBlockId = false): array
    {
        $transaction = new Transaction();
        if (!$previousBlockId) {
            $currBlock = $this->getByBlockId($blockId);
        } else {
            $currBlock = $this->getByPreviousBlockId($blockId);
        }

        if ($currBlock !== null) {
            // remove internal fields
            $currBlock = $this->stripInternalFields($currBlock);

            // get the current height to calculate confirmations
            $currentHeight = $this->getCurrentHeight();
            $currBlock['confirmations'] = $currentHeight - $currBlock['height'];
            $currBlock['transactions'] = $transaction->stripInternalFields($transaction->getTransactionsByBlockId($blockId));
        } else {
            $currBlock = [];
        }
        return $currBlock;
    }

    public function stripInternalFields(array $block): array
    {
        // remove internal columns
        unset($block['id'], $block['orphan']);
        return $block;
    }

    /**
     * @throws Exception
     */
    public function validateFullBlock(array $block): array
    {
        $transactions = $block['transactions'];
        unset($block['transactions']);
        return $this->validate($block, $transactions, $block['transaction_count']);
    }

    #[ArrayShape(['validated' => "bool", 'reason' => "string"])]
    private function returnValidateResult(string $reason, bool $result): array
    {
        return [
            'validated' => $result,
            'reason' => $reason
        ];
    }

    public function validate(array $block, array $transactions, int $transactionCount): array
    {
        if ($block['network_id'] !== Config::getNetworkIdentifier()) {
            return $this->returnValidateResult('networkId mismatch', false);
        }

        // ensure a sane time
        if ($block['date_created'] > time() + 60) {
            return $this->returnValidateResult('block from the future', false);
        }

        // no transactions before the genesis
        if ($block['date_created'] < Config::getGenesisDate()) {
            return $this->returnValidateResult('block precedes genesis block', false);
        }

        // check difficulty
        if ($this->getDifficulty((int)$block['height']) !== (int)$block['difficulty']) {
            return $this->returnValidateResult('difficulty difference', false);
        }

        // check the proof of work
        $pow = new Pow();
        if (!$pow->verifyPow($block['hash'], $this->generateBlockHeader($block), $block['nonce'])) {
            return $this->returnValidateResult("Proof of work fail", false);
        }

        // we must have all the transactions
        $transactions = Transaction::sort($transactions);
        if ($transactionCount !== count($transactions)) {
            return $this->returnValidateResult("transaction count mismatch", false);
        }

        // check for a valid height
        if ($block['height'] < 1) {
            return $this->returnValidateResult("invalid height", false);
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
            return $this->returnValidateResult("blocks must have exactly 1 coinbase record.", false);
        }

        // test the merkle root
        $merkle = new Merkle();
        if ($block['merkle_root'] !== $merkle->computeMerkleHash($transactions)) {
            return $this->returnValidateResult("merkle root issue", false);
        }

        return $this->returnValidateResult("ok", true);
    }

    /**
     * This function goes through all blocks and makes sure that all the records are sound.
     * @return void
     */
    public function checkIntegrity(): void
    {
        // TODO: Check all blocks, and transactions and compare to external nodes
        // - foreach block (from top to bottom) get full block, and validate it - if it fails, we request it
    }

    /**
     * This function determines if the two given blocks are at a fork
     *
     * [block_1]     [block_2]
     *          \   /
     *           \/
     *           |
     * @param array|null $block1
     * @param array|null $block2
     * @return bool
     */
    private function isFork(?array $block1, ?array $block2): bool
    {
        if ($block1 == null || $block2 == null) {
            return false;
        }

        // same block?
        if ($block1['block_id'] == $block2['block_id']) {
            return false;
        }

        // not the same height?
        if ((int)$block1['height'] != (int)$block2['height']) {
            return false;
        }

        // is this a fork? If not, get out
        if ($block1['previous_block_id'] != $block2['previous_block_id']) {
            return false;
        }

        return true;
    }

    /**
     * This cleans the blockchain by removing all orphan blocks & transactions. This should be called
     * AFTER resolveForks() so that you don't delete the wrong blocks.
     *
     * @return void
     */
    public function cleanBlockchain(): void
    {
        /**
         * Get the highest height and work down
         */
        // get top 2 blocks by height
        $height = $this->getCurrentHeight();
        if ($height > 1) {

            /**
             * iterate from the highest to the lowest (or lowest save)
             * TODO: Need to store progress so we don't have to do the whole blockchain
             */
            $query = 'SELECT `block_id` FROM blocks WHERE orphan=1 ORDER BY `height` DESC LIMIT 1';
            $stmt = $this->db->query($query);
            $rows = $stmt->fetchAll();
            $counter = 0;
            foreach ($rows as $row) {
                $this->delete($row['block_id']);

                // let's not kill the CPU
                if ($counter++ % 100 === 0) {
                    usleep(5000);
                }
            }
        }
    }

    /**
     * This just tests if our highest block is an orphan
     *
     * @return bool
     */
    public function isCurrentHeightOrphan(): bool
    {
        $query = 'SELECT `orphan` FROM blocks ORDER BY `height` DESC LIMIT 1';
        $stmt = $this->db->query($query);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * This goes from TOP to BOTTOM and resolves all forks. It chooses the highest height to start with.
     * @return void
     */
    public function resolveForks(): void
    {
        /**
         * We need to make sure that the two highest blocks are not competing blocks
         */
        // get top 2 blocks by height
        $query = 'SELECT `block_id`,`height` FROM blocks ORDER BY `height` DESC LIMIT 2';
        $stmt = $this->db->query($query);
        $rows = $stmt->fetchAll();

        if ($rows != null) {

            // check to see if there are two competing blocks at the same height
            $block1 = $this->getByBlockId($rows[0]['block_id']);
            $block2 = $this->getByBlockId($rows[1]['block_id']);

            // resolve
            $selectedBlock = $this->blockSelector($block1, $block2);
            if ($selectedBlock != null) {
                $block = $selectedBlock;
            } else {
                $block = $block1;
            }

            /**
             * iterate from the highest to the lowest (or lowest save)
             * TODO: Need to store progress so we don't have to do the whole blockchain
             */
            $height = (int)$block['height'];
            $blockId = $block['block_id'];
            $this->acceptBlock($blockId, $height);

            while ($height > 1) {
                $block = $this->getByBlockId($block['previous_block_id']);
                $this->acceptBlock($block['block_id'], $height--);
            }
        }
    }

    /**
     * This function chooses BLOCK_1 or BLOCK_2 to break any ties based on a couple rules.
     *
     * @param array $block1
     * @param array $block2
     * @return array|null
     */
    public function blockSelector(array $block1, array $block2): ?array
    {
        $block = null;
        if ($this->isFork($block1, $block2)) {
            // two valid blocks, get the better one and adjust the chain
            $strength = bccomp(BcmathExtensions::bchexdec($block1['hash']), BcmathExtensions::bchexdec($block2['hash']));

            // always prefer stronger hashes with more transactions
            if ($strength >= 0 && (int)$block1['transaction_count'] > (int)$block2['transaction_count']) {
                $block = $block1;
            } else {
                $block = $block2;
            }
        }
        return $block;
    }

    public function generateBlockHeader(array $block): string
    {
        return
            $block['network_id'] .
            $block['block_id'] .
            $block['previous_block_id'] .
            $block['date_created'] .
            $block['height'] .
            $block['difficulty'] .
            $block['merkle_root'] .
            $block['transaction_count'] .
            $block['previous_hash'];
    }

    #[Pure]
    public function getRewardValue(int $nHeight): string
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
                $difficulty--;
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

    /**
     * Warning, this is a simple "add a block and its transactions to the database". If you want the block to be active,
     * you need to '$this->acceptBlock(block_id)'.
     *
     * @param array $block
     * @param bool $validate
     * @param bool $orphan
     * @return bool
     */
    public function add(array $block, bool $validate = true, bool $orphan = false): bool
    {
        $result = false;

        $orphanVal = 0;
        if ($orphan) {
            $orphanVal = 1;
        }

        if ($validate) {
            try {
                $result = $this->validateFullBlock($block);
            } catch (Exception|RuntimeException $ex) {
                Console::log('Exception thrown validating block: ' . $ex->getMessage());
                exit(0);
            }
            if (!$result['validated']) {
                Console::log('Invalid block: ' . $result['reason']);
                exit(0);
            }
        }

        // ensure we have transactions
        if (!isset($block['transactions'])) {
            throw new RuntimeException('Missing transactions');
        }

        // clip transactions
        $transactions = $block['transactions'];
        unset($block['transactions']);

        // defaults
        if ((int)$block['date_created'] <= 0) {
            $block['date_created'] = time();
        }

        try {
            // start a transaction
            $this->db->beginTransaction();

            // prepare the statement and execute
            $query = 'INSERT INTO blocks (`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan`) VALUES (:network_id,:block_id,:previous_block_id,:date_created,:height,:nonce,:difficulty,:merkle_root,:transaction_count,:previous_hash,:hash,:orphan)';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'network_id', value: $block['network_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 4);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $block['block_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_block_id', value: $block['previous_block_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'date_created', value: $block['date_created'], pdoType: DatabaseHelpers::INT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'height', value: $block['height'], pdoType: DatabaseHelpers::INT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'nonce', value: $block['nonce'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 16);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'difficulty', value: $block['difficulty'], pdoType: DatabaseHelpers::INT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'merkle_root', value: $block['merkle_root'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_count', value: $block['transaction_count'], pdoType: DatabaseHelpers::INT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'hash', value: $block['hash'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_hash', value: $block['previous_hash'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'orphan', value: $orphanVal, pdoType: DatabaseHelpers::INT);
            $stmt->execute();

            // ensure the block was stored
            $blockInsertId = (int)$this->db->lastInsertId();
            if ($blockInsertId <= 0) {
                throw new RuntimeException("failed to add block to the database: " . $block['block_id']);
            }

            // add the transactions
            foreach ($transactions as $transaction) {
                $transaction['block_id'] = $block['block_id'];

                // defaults
                if ((int)$transaction['date_created'] < Config::getGenesisDate()) {
                    throw new RuntimeException("failed to add block to the database: " . $block['block_id']);
                }

                // prepare the statement and execute
                $query = 'INSERT INTO transactions (`block_id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key`) VALUES (:block_id,:transaction_id,:date_created,:peer,:height,:version,:signature,:public_key)';
                $stmt = $this->db->prepare($query);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $transaction['block_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transaction['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'date_created', value: $transaction['date_created'], pdoType: DatabaseHelpers::INT);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'peer', value: $transaction['peer'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'height', value: $transaction['height'], pdoType: DatabaseHelpers::INT);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'version', value: $transaction['version'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 2);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'signature', value: $transaction['signature'], pdoType: DatabaseHelpers::TEXT);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'public_key', value: $transaction['public_key'], pdoType: DatabaseHelpers::TEXT);
                $stmt->execute();

                $transactionId = (int)$this->db->lastInsertId();
                if ($transactionId <= 0) {
                    throw new RuntimeException('failed to add transaction to the database: ' . $transaction['block_id'] . ' - ' . $transaction['transaction_id']);
                }

                // add txIn
                foreach ($transaction[Transaction::Inputs] as $txIn) {
                    $txIn['block_id'] = $transaction['block_id'];
                    $txIn['transaction_id'] = $transaction['transaction_id'];

                    // make sure there is a previous unspent transaction
                    $query = 'SELECT `block_id`,`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`hash` FROM transaction_outputs WHERE spent=0 AND transaction_id=:transaction_id AND tx_id=:tx_id';
                    $stmt = $this->db->prepare($query);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_transaction_id', value: $txIn['previous_transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_tx_out_id', value: $txIn['previous_tx_out_id'], pdoType: DatabaseHelpers::INT);
                    $stmt->execute();
                    $txOut = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($txOut) <= 0) {
                        throw new RuntimeException('failed to get unspent transaction for: ' . $txIn['previous_transaction_id'] . ' - ' . $txIn['previous_tx_out_id']);
                    }

                    // run script
                    $result = $this->transaction->unlockTransaction($txIn, $txOut);
                    if (!$result) {
                        throw new RuntimeException("Cannot unlock script for: " . $txIn['transaction_id']);
                    }

                    // add the txIn record to the db
                    $query = 'INSERT INTO transaction_inputs (`block_id`,`transaction_id`,`tx_id`,`previous_transaction_id`,`previous_tx_out_id`,`script`) VALUES (:block_id,:transaction_id,:tx_id,:previous_transaction_id,:previous_tx_out_id,:script)';
                    $stmt = $this->db->prepare($query);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $transaction['block_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txIn['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txIn['tx_id'], pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_transaction_id', value: $txIn['previous_transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_tx_out_id', value: $txIn['previous_tx_out_id'], pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'script', value: $txIn['script'], pdoType: DatabaseHelpers::TEXT);
                    $stmt->execute();
                    $transactionTxId = (int)$this->db->lastInsertId();
                    if ($transactionTxId <= 0) {
                        throw new RuntimeException('failed to add a new transaction tx: ' . $txIn['transaction_id'] . ' - ' . $txIn['$txIn']);
                    }
                }

                // add txOut
                foreach ($transaction[Transaction::Outputs] as $txOut) {
                    $txOut['block_id'] = $transaction['block_id'];
                    $txOut['transaction_id'] = $transaction['transaction_id'];
                    $txOut['spent'] = 0; // set this to zero, it will be updated on the spent transaction

                    // add the txIn record to the db
                    $query = 'INSERT INTO transaction_outputs (`block_id`,`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`spent`,`hash`) VALUES (:block_id,:transaction_id,:tx_id,:address,:value,:script,:lock_height,:spent,:hash)';
                    $stmt = $this->db->prepare($query);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $transaction['block_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txOut['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txOut['tx_id'], pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'address', value: $txOut['address'] ?: '', pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 40);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'value', value: $txOut['value'], pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'script', value: $txOut['script'], pdoType: DatabaseHelpers::TEXT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'lock_height', value: $txOut['lock_height'], pdoType: DatabaseHelpers::INT);
                    // any new block added is NOT spent
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'spent', value: 0, pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'hash', value: $txOut['hash'], pdoType: DatabaseHelpers::TEXT);
                    $stmt->execute();
                    $transactionTxId = (int)$this->db->lastInsertId();
                    if ($transactionTxId <= 0) {
                        throw new RuntimeException('failed to add a new transaction tx as unspent in the database: ' . $txOut['transaction_id'] . ' - ' . $txOut['$txIn']);
                    }
                }
            }

            $this->db->commit();
            $result = true;
        } catch (Exception $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }

        return $result;
    }

    /**
     * This function effectively processes a block and all of its transactions, and makes it active.
     *
     * @param string $blockId
     * @param int $height
     * @return bool
     */
    public function acceptBlock(string $blockId, int $height): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();

            // set this block as an orphan (if this is a duplicate)
            $query = 'UPDATE blocks SET `orphan`=0 WHERE `height`= :height AND `block_id` = :block_id';
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(param: ':height', var: $height, type: PDO::PARAM_INT);
            $stmt->bindParam(param: ':block_id', var: $blockId, maxLength: 64);
            $stmt->execute();

            // get all other blocks at this height and reverse them
            $query = 'SELECT block_id FROM transactions WHERE `height`= :height AND `block_id` != :block_id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt->execute();
            $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // reverse all blocks at this height
            foreach ($blocks as $block) {
                $this->reverseBlock($block['block_id'], $block['height']);
            }

            // get all transactions associated with this block
            $query = 'SELECT transaction_id FROM transactions WHERE `block_id` = :block_id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($transactions as $transaction) {

                // add txIn
                foreach ($transaction[Transaction::Inputs] as $txIn) {
                    $txIn['block_id'] = $transaction['block_id'];
                    $txIn['transaction_id'] = $transaction['transaction_id'];

                    // make sure there is a previous unspent transaction
                    $query = 'SELECT `block_id`,`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`hash` FROM transaction_outputs WHERE spent=0 AND transaction_id=:transaction_id AND tx_id=:tx_id';
                    $stmt = $this->db->prepare($query);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_transaction_id', value: $txIn['previous_transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_tx_out_id', value: $txIn['previous_tx_out_id'], pdoType: DatabaseHelpers::INT);
                    $stmt->execute();
                    $txOut = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (count($txOut) <= 0) {
                        throw new RuntimeException('failed to get unspent transaction for: ' . $txIn['previous_transaction_id'] . ' - ' . $txIn['previous_tx_out_id']);
                    }

                    // run script
                    $result = $this->transaction->unlockTransaction($txIn, $txOut);
                    if (!$result) {
                        throw new RuntimeException("Cannot unlock script for: " . $txIn['transaction_id']);
                    }

                    // mark the transaction as spent
                    $stmt = $this->db->prepare('UPDATE transaction_outputs SET spent=1 WHERE transaction_id=:transaction_id AND tx_id=:tx_id;');
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txIn['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txIn['previous_tx_out_id'], pdoType: DatabaseHelpers::INT);
                    $stmt->execute();
                    $transactionTxId = (int)$this->db->lastInsertId();
                    if ($transactionTxId <= 0) {
                        throw new RuntimeException('failed to update transaction tx as spent in the database: ' . $txIn['transaction_id'] . ' - ' . $txIn['transaction_id']);
                    }

                    // delete mempool transactions
                    $stmt = $this->db->prepare('DELETE from mempool_inputs WHERE transaction_id=:transaction_id AND tx_id=:tx_id');
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txIn['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txIn['tx_id'], pdoType: DatabaseHelpers::INT);
                    $stmt->execute();
                }

                // add txOut
                foreach ($transaction[Transaction::Outputs] as $txOut) {
                    $txOut['block_id'] = $transaction['block_id'];
                    $txOut['transaction_id'] = $transaction['transaction_id'];
                    $txOut['spent'] = 0; // set this to zero, it will be updated on the spent transaction

                    // delete mempool transactions
                    $stmt = $this->db->prepare('DELETE from mempool_outputs WHERE transaction_id=:transaction_id AND tx_id=:tx_id');
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txOut['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txOut['tx_id'], pdoType: DatabaseHelpers::INT);
                    $stmt->execute();
                }
            }

            $this->db->commit();
            $result = true;
        } catch (Exception|RuntimeException $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }
        return $result;
    }

    /**
     * WARNING --- This will delete any block given, and does NOT reverse anything - generally safest to call
     * reverseBlock() with delete flag set to true.
     *
     * @param string $blockId
     * @param bool $restoreMempool
     * @return bool
     */
    public function delete(string $blockId): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();

            // delete the block
            $query = 'DELETE FROM blocks WHERE `block_id` = :block_id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt->execute();

            // get all transactions associated with this block
            $query = 'SELECT transaction_id FROM transactions WHERE `block_id` = :block_id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
            $stmt->execute();
            $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($transactions as $transaction) {
                // clear the transaction inputs
                $query = 'DELETE FROM transaction_inputs WHERE `transaction_id` = :transaction_id;';
                $stmt = $this->db->prepare($query);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transaction['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt->execute();

                // clear the transaction outputs
                $query = 'DELETE FROM transaction_outputs WHERE `transaction_id` = :transaction_id;';
                $stmt = $this->db->prepare($query);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transaction['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt->execute();
            }

            $this->db->commit();
            $result = true;
        } catch (Exception|RuntimeException $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }
        return $result;
    }

    /**
     * This is a recursive function that reverses the block given, and calls a recursive function 'reverse transactions`
     *
     * @param string $blockId
     * @param bool $delete
     * @return bool
     */
    public function reverseBlock(string $blockId, bool $delete = false): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();

            // set this block as an orphan
            $query = 'UPDATE blocks SET `orphan`=1 WHERE `block_id` = :block_id';
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(param: ':block_id', var: $blockId, maxLength: 64);
            $stmt->execute();

            $this->reverseTransactions($blockId, $delete);
            if ($delete) {
                $this->delete($blockId);
            }

            $result = true;
        } catch (Exception|RuntimeException $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }
        return $result;
    }

    private function reverseTransactions(string $blockId, bool $delete = false)
    {
        // get all transactions associated with this block
        $query = 'SELECT transaction_id FROM transactions WHERE `block_id` = :block_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($transactions as $transaction) {

            // add txIn
            foreach ($transaction[Transaction::Inputs] as $txIn) {
                $txIn['block_id'] = $transaction['block_id'];
                $txIn['transaction_id'] = $transaction['transaction_id'];

                // reverse the transaction output that we point to
                $stmt = $this->db->prepare('UPDATE transaction_outputs SET spent=0 WHERE block_id=:block_id AND transaction_id=:transaction_id AND tx_id=:tx_id;');
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $txIn['block_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txIn['previous_transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txIn['previous_tx_out_id'], pdoType: DatabaseHelpers::INT);
                $stmt->execute();
            }

            // txOut
            foreach ($transaction[Transaction::Outputs] as $txOut) {
                $txOut['block_id'] = $transaction['block_id'];
                $txOut['transaction_id'] = $transaction['transaction_id'];

                // reverse this blocks output
                $stmt = $this->db->prepare('UPDATE transaction_outputs SET spent=0 WHERE block_id=:block_id AND transaction_id=:transaction_id AND tx_id=:tx_id;');
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $blockId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txOut['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txOut['previous_tx_out_id'], pdoType: DatabaseHelpers::INT);
                $stmt->execute();

                // get the `spent` transaction that is pointing to our outputs (if there is one)
                $stmt = $this->db->prepare('SELECT `block_id` FROM transaction_inputs WHERE block_id=:block_id AND previous_transaction_id=:transaction_id AND previous_tx_out_id=:tx_id;');
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'block_id', value: $txOut['block_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txOut['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txOut['tx_id'], pdoType: DatabaseHelpers::INT);
                $stmt->execute();

                // reverse the `spent` transaction that is pointing to this block
                $rBlockId = $stmt->fetchColumn();
                $this->reverseBlock($rBlockId, $delete);
            }
        }

        /**
         * put the mempool transactions back, even if we are deleting, the transactions are not part of the block and
         * need to be taken care of.
         */
        $this->mempool->add($transactions);
    }
}