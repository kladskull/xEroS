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

    private PDO $db;
    public Transaction $transaction;
    private Pow $pow;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->transaction = new Transaction();
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


        $publicKeyRaw = $openSsl->stripPem(file_get_contents(APP_DIR. 'public.key'));
        $privateKeyRaw = $openSsl->stripPem(file_get_contents(APP_DIR . 'private.key'));

        //$publicKeyRaw = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA2rurp8hen6Wepcz0X+4kWqtqEk7e+46Mx4hjDDlYw2j7XXiEpSHAz0ZWwKfDlBSBV8yYVBSGI+URjoBn7+ZH/cuHaSNCxg5JTxSG5DyWgeG1OHUnILnfLdJpo0H/mGscVdf/Nws21j/XbG9eXICFIVfojKKqZWLax8XLyuf/Gl4Oj7RAuRVseN7CRiq73x8kcMSzUgLyitefWaH1GxmATTm3ygey5itn8ddf4iow78lM56hPHXl5id0JV+WsRL6QbuFvrC5Eo42iAyN0dsHrpqkK1+2fKVrfedJy3aa6LqjQZdfebJtw4PCdKBpn1ZVIeDJILy2lQUuBXu52Qc93QQIDAQAB';
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
                    'hash' => bin2hex($this->pow->doubleSha256($transactionId . $txId . $toAddress . $amount . $lockHeight)),
                ]
            ],
        ];

        // sign
        //$transactionRecord['signature'] = $signature;
        $transactionRecord['signature'] = $transaction->signTransaction($transactionRecord, $openSsl->formatPem($publicKeyRaw, false), $openSsl->formatPem($privateKeyRaw, true));

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
        return bin2hex($this->pow->doubleSha256(
            $previousBlockId . $date . $height
        ));
    }

    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'block_id', $id, DatabaseHelpers::INT);
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
        $query = 'SELECT `id`,`network_id`,`block_id`,`previous_block_id`,`date_created`,`height`,`nonce`,`difficulty`,`merkle_root`,`transaction_count`,`previous_hash`,`hash`,`orphan` FROM blocks WHERE `previous_block_id` = :previous_block_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'previous_block_id', $previousBlockId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getCurrentHeight(): int
    {
        $query = 'SELECT `height` FROM blocks ORDER BY `height` DESC LIMIT 1';
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
            unset($currBlock['id'], $currBlock['orphan']);

            // get the current height to calculate confirmations
            $currentHeight = $this->getCurrentHeight();
            $currBlock['confirmations'] = $currentHeight - $currBlock['height'];
            $currBlock['transactions'] = $transaction->getTransactionsByBlockId($blockId);
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
     * Add a block, and its transactions. It's all done in here to avoid PDO transactions being done
     * over several external calls, etc. We're going to lock the tables, and do it in one go.
     *
     * @param array $block
     * @param bool $validate
     * @return bool
     */
    public function addFullBlock(array $block, bool $validate = true): bool
    {
        $result = false;

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

            // lock tables
            // $this->db->exec('LOCK TABLES accounts WRITE, blocks WRITE, transactions WRITE,transaction_inputs WRITE,transaction_outputs WRITE,peers WRITE;');

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
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'orphan', value: 0, pdoType: DatabaseHelpers::INT);
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
                if ((int)$transaction['date_created'] <= 0) {
                    $transaction['date_created'] = time();
                }

                // delete mempool transactions with same transaction id's
                $stmt = $this->db->prepare('DELETE from mempool_transactions WHERE transaction_id=:transaction_id;');
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transaction['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt->execute();

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
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'public_key', value: $transaction['signature'], pdoType: DatabaseHelpers::TEXT);
                $stmt->execute();

                $transactionId = (int)$this->db->lastInsertId();
                if ($transactionId <= 0) {
                    throw new RuntimeException('failed to add transaction to the database: ' . $transaction['block_id'] . ' - ' . $transaction['transaction_id']);
                }

                // add txIn
                foreach ($transaction[Transaction::Inputs] as $txIn) {
                    $txIn['transaction_id'] = $transaction['transaction_id'];

                    // make sure there is a previous unspent transaction
                    $query = 'SELECT `transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`hash` FROM transaction_outputs WHERE spent=0 AND transaction_id=:transaction_id AND tx_id=:tx_id';
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

                    // add the txIn record to the db
                    $query = 'INSERT INTO transaction_inputs (`transaction_id`,`tx_id`,`previous_transaction_id`,`previous_tx_out_id`,`script`) VALUES (:transaction_id,:tx_id,:previous_transaction_id,:previous_tx_out_id,:script)';
                    $stmt = $this->db->prepare($query);
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

                    // delete mempool transactions
                    $stmt = $this->db->prepare('DELETE from mempool_inputs WHERE transaction_id=:transaction_id AND tx_id=:tx_id');
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txIn['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txIn['tx_id'], pdoType: DatabaseHelpers::INT);
                    $stmt->execute();
                }

                // add txOut
                foreach ($transaction[Transaction::Outputs] as $txOut) {
                    $txOut['transaction_id'] = $transaction['transaction_id'];
                    $txOut['spent'] = 0; // set this to zero, it will be updated on the spent transaction

                    // add the txIn record to the db
                    $query = 'INSERT INTO transaction_outputs (`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`spent`,`hash`) VALUES (:transaction_id,:tx_id,:address,:value,:script,:lock_height,:spent,:hash)';
                    $stmt = $this->db->prepare($query);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txOut['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txOut['tx_id'], pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'address', value: $txOut['address'] ?: '', pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 40);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'value', value: $txOut['value'], pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'script', value: $txOut['script'], pdoType: DatabaseHelpers::TEXT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'lock_height', value: $txOut['lock_height'], pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'spent', value: $txOut['spent'] ?: 0, pdoType: DatabaseHelpers::INT);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'hash', value: $txOut['hash'], pdoType: DatabaseHelpers::TEXT);
                    $stmt->execute();
                    $transactionTxId = (int)$this->db->lastInsertId();
                    if ($transactionTxId <= 0) {
                        throw new RuntimeException('failed to add a new transaction tx as unspent in the database: ' . $txOut['transaction_id'] . ' - ' . $txOut['$txIn']);
                    }

                    // delete mempool transactions
                    $stmt = $this->db->prepare('DELETE from mempool_outputs WHERE transaction_id=:transaction_id AND tx_id=:tx_id');
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txOut['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txOut['tx_id'], pdoType: DatabaseHelpers::INT);
                    $stmt->execute();
                }
            }

            // unlock tables and commit
            //$this->db->exec('UNLOCK TABLES');

            $this->db->commit();

            $result = true;
        } catch (Exception $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            //$this->db->exec('UNLOCK TABLES');
            $this->db->rollback();
        }

        return $result;
    }

    // add a new block to the db
    public function identifyOrphans(int $height, string $blockId): void
    {
        // mark all other blocks as orphans
        $query = 'UPDATE blocks SET `orphan`=1 WHERE `height`= :height AND `block_id` != :block_id';
        $stmt = $this->db->prepare($query);
        $height = self::filterBlockHeight(filter_var($height, FILTER_SANITIZE_NUMBER_INT));
        $blockId = preg_replace("/[^a-zA-Z0-9]/", '', $blockId);
        $stmt->bindParam(param: ':height', var: $height, type: PDO::PARAM_INT);
        $stmt->bindParam(param: ':block_id', var: $blockId, maxLength: 64);
        $stmt->execute();

        // ensure this block is not an orphan
        $query = 'UPDATE blocks SET `orphan`=0 WHERE `height`= :height AND `block_id` = :block_id';
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(param: ':height', var: $height, type: PDO::PARAM_INT);
        $stmt->bindParam(param: ':block_id', var: $blockId, maxLength: 64);
        $stmt->execute();
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
    public
    function getDifficulty(int $height = 0, array $latestBlock = null, array $oldestBlock = null): int
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
                // REVERSE ANY SPENT TRANSACTIONS!!
                $spentItems = $this->db->query('SELECT previous_transaction_id, previous_tx_out_id from transaction_inputs WHERE transaction_id=%s;', $transaction['transaction_id']);
                foreach ($spentItems as $spentItem) {
                    // mark the transaction as unspent
                    $stmt = $this->db->prepare('UPDATE transaction_outputs SET spent=0 WHERE transaction_id=:transaction_id AND tx_id=:tx_id;');
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $spentItem['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                    $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $spentItem['previous_tx_out_id'], pdoType: DatabaseHelpers::INT);
                    $stmt->execute();
                    $transactionTxId = (int)$this->db->lastInsertId();
                    if ($transactionTxId <= 0) {
                        throw new RuntimeException('failed to update transaction tx as unspent in the database: ' . $spentItem['transaction_id'] . ' - ' . $spentItem['transaction_id']);
                    }
                }

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
     * Validate a remote block against x peers
     *
     * TODO: Needs to be refactored
     *
     * @param array $blk
     * @param Peer $peer
     * @param Block $block
     * @return bool
     */
    public function verifyRemoteBlock(array $blk, Peer $peer, Block $block): bool
    {
        // we need to check the block other peers...
        $result = false;

        $count = 0;
        $correct = 0;

        // check the block against all of our peers
        foreach ($peer->getAll(Config::getMaxRebroadcastPeers()) as $p) {
            $checkBlk = null;// $block->getRemoteBlockById($p['address'], $blk['block_id']);
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
}