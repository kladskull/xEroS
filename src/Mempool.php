<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Class Mempool
 * @package Blockchain
 */
class Mempool
{
    protected PDO $db;
    protected Transaction $transaction;

    public const INPUTS = 'txIn';
    public const OUTPUTS = 'txOut';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->transaction = new Transaction();
    }

    /**
     * @param array $transaction
     * @return bool
     */
    public function add(array $transaction): bool
    {
        try {
            $validationResult = $this->transaction->validate($transaction);

            if ($validationResult['validated'] === false) {
                Console::log('Mempool rejected transaction: ' . $validationResult['reason']);

                return false;
            }

            if ((string)$transaction['version'] === TransactionVersion::COINBASE) {
                return false;
            }

            if ($this->getByTransactionId($transaction['transaction_id']) !== null) {
                return false;
            }

            if ($this->getMempoolCount(PHP_INT_MAX) >= Config::getMaxMempoolTransactions()) {
                Console::log('Mempool is full');

                return false;
            }

            // a transaction for a far off height would sit here and never be mined
            if ((int)$transaction['height'] > (new Block())->getCurrentHeight() + 1) {
                return false;
            }

            if ((int)$transaction['date_created'] < time() - Config::getMempoolExpiry()) {
                return false;
            }

            // first seen wins, a second transaction spending the same output is not accepted
            foreach ($transaction[Transaction::INPUTS] as $txIn) {
                if ($this->isOutputSpent($txIn['previous_transaction_id'], (int)$txIn['previous_tx_out_id'])) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            Console::log('Error ' . $e->getMessage());

            return false;
        }

        $ownsTransaction = !$this->db->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

            $stmt = $this->db->prepare(
                'INSERT INTO mempool_transactions (`transaction_id`,`date_created`,`peer`,`height`,`version`' .
                ',`signature`,`public_key`) VALUES (:transaction_id,:date_created,:peer,:height,:version,' .
                ':signature,:public_key)'
            );
            $stmt = DatabaseHelpers::filterBindAll($stmt, [
                ['name' => 'transaction_id', 'value' => $transaction['transaction_id'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 64],
                ['name' => 'date_created', 'value' => (int)$transaction['date_created'],
                    'type' => DatabaseHelpers::INT, 'max_length' => 20],
                ['name' => 'peer', 'value' => $transaction['peer'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 64],
                ['name' => 'height', 'value' => (int)$transaction['height'],
                    'type' => DatabaseHelpers::INT, 'max_length' => 20],
                ['name' => 'version', 'value' => (string)$transaction['version'],
                    'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 2],
                ['name' => 'signature', 'value' => $transaction['signature'],
                    'type' => DatabaseHelpers::TEXT, 'max_length' => 8192],
                ['name' => 'public_key', 'value' => $transaction['public_key'],
                    'type' => DatabaseHelpers::TEXT, 'max_length' => 8192],
            ]);
            $stmt->execute();

            foreach ($transaction[Transaction::INPUTS] as $txIn) {
                $stmt = $this->db->prepare(
                    'INSERT INTO mempool_inputs (`transaction_id`,`tx_id`,`previous_transaction_id`,' .
                    '`previous_tx_out_id`,`script`) VALUES (:transaction_id,:tx_id,:previous_transaction_id,' .
                    ':previous_tx_out_id,:script)'
                );
                $stmt = DatabaseHelpers::filterBindAll($stmt, [
                    ['name' => 'transaction_id', 'value' => $transaction['transaction_id'],
                        'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 64],
                    ['name' => 'tx_id', 'value' => (int)$txIn['tx_id'],
                        'type' => DatabaseHelpers::INT, 'max_length' => 20],
                    ['name' => 'previous_transaction_id', 'value' => $txIn['previous_transaction_id'],
                        'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 64],
                    ['name' => 'previous_tx_out_id', 'value' => (int)$txIn['previous_tx_out_id'],
                        'type' => DatabaseHelpers::INT, 'max_length' => 20],
                    ['name' => 'script', 'value' => $txIn['script'],
                        'type' => DatabaseHelpers::TEXT, 'max_length' => 16384],
                ]);
                $stmt->execute();
            }

            foreach ($transaction[Transaction::OUTPUTS] as $txOut) {
                $stmt = $this->db->prepare(
                    'INSERT INTO mempool_outputs (`transaction_id`,`tx_id`,`address`,`value`,`script`,' .
                    '`lock_height`,`spent`,`hash`) VALUES (:transaction_id,:tx_id,:address,:value,:script,' .
                    ':lock_height,0,:hash)'
                );
                $stmt = DatabaseHelpers::filterBindAll($stmt, [
                    ['name' => 'transaction_id', 'value' => $transaction['transaction_id'],
                        'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 64],
                    ['name' => 'tx_id', 'value' => (int)$txOut['tx_id'],
                        'type' => DatabaseHelpers::INT, 'max_length' => 20],
                    ['name' => 'address', 'value' => $txOut['address'],
                        'type' => DatabaseHelpers::ALPHA_NUMERIC, 'max_length' => 64],
                    ['name' => 'value', 'value' => (string)$txOut['value'],
                        'type' => DatabaseHelpers::TEXT, 'max_length' => 64],
                    ['name' => 'script', 'value' => $txOut['script'],
                        'type' => DatabaseHelpers::TEXT, 'max_length' => 16384],
                    ['name' => 'lock_height', 'value' => (int)$txOut['lock_height'],
                        'type' => DatabaseHelpers::INT, 'max_length' => 20],
                    ['name' => 'hash', 'value' => (string)($txOut['hash'] ?? ''),
                        'type' => DatabaseHelpers::TEXT, 'max_length' => 8192],
                ]);
                $stmt->execute();
            }

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());

            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollback();
            }

            return false;
        }

        return true;
    }

    /**
     * Drops transactions that are too old or no longer valid on the active chain, e.g. their inputs were spent
     * by a block. Returns how many were removed.
     *
     * @param int $height the height of the next block
     * @return int
     */
    public function evictStale(int $height): int
    {
        $stmt = $this->db->prepare('SELECT `transaction_id`,`date_created`,`peer`,`height`,`version`,' .
            '`signature`,`public_key` FROM mempool_transactions ORDER BY `date_created`, `id`');
        $stmt->execute();
        $expiry = time() - Config::getMempoolExpiry();
        $evicted = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $transaction) {
            $transaction[self::INPUTS] = $this->stripRow($this->getTransactionInputs($transaction['transaction_id']));
            $transaction[self::OUTPUTS] = $this->stripRow($this->getTransactionOutputs($transaction['transaction_id']));

            if ((int)$transaction['date_created'] < $expiry
                || $this->transaction->validate($transaction, $height)['validated'] !== true) {
                $this->delete($transaction['transaction_id']);
                $evicted++;
            }
        }

        return $evicted;
    }

    /**
     * True if a transaction in the mempool already spends this output.
     *
     * @param string $transactionId
     * @param int $txId
     * @return bool
     */
    public function isOutputSpent(string $transactionId, int $txId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM mempool_inputs WHERE previous_transaction_id=:transaction_id AND ' .
            'previous_tx_out_id=:tx_id LIMIT 1'
        );
        $stmt = DatabaseHelpers::filterBind($stmt, 'transaction_id', $transactionId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt = DatabaseHelpers::filterBind($stmt, 'tx_id', $txId, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function get(int $id): array|null
    {
        $query = 'SELECT `id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key` ' .
            'FROM mempool_transactions WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT, 0);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param string $transactionId
     *
     * @return array|null
     */
    public function getByTransactionId(string $transactionId): array|null
    {
        $query = 'SELECT `id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key` ' .
            'FROM mempool_transactions WHERE `transaction_id` = :transaction_id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            $stmt, 'transaction_id',
            $transactionId,
            DatabaseHelpers::ALPHA_NUMERIC,
            64
        );
        $stmt->execute();
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($transaction !== null) {
            $txIns = $this->getTransactionInputs($transactionId);
            $txOuts = $this->getTransactionOutputs($transactionId);

            // attach the details
            $transaction[self::INPUTS] = $txIns;
            $transaction[self::OUTPUTS] = $txOuts;
        }

        return $transaction;
    }

    /**
     * @param string $transactionId
     * @return array
     */
    public function getTransactionInputs(string $transactionId): array
    {
        $query = 'SELECT `id`,`transaction_id`,`tx_id`,`previous_transaction_id`,`previous_tx_out_id`,`script` ' .
            'FROM mempool_inputs WHERE transaction_id=:transaction_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt->execute();

        return Transaction::sortTx($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    /**
     * @param string $transactionId
     * @return bool|array|null
     */
    public function getTransactionOutputs(string $transactionId): bool|array|null
    {
        $query = 'SELECT `id`,`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`spent`,`hash` ' .
            'FROM mempool_outputs WHERE transaction_id=:transaction_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt->execute();

        return Transaction::sortTx($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    /**
     * @param string $transactionId
     * @return bool
     */
    public function delete(string $transactionId): bool
    {
        // delete mempool transactions with same transaction id's
        $stmt = $this->db->prepare('DELETE from mempool_transactions WHERE transaction_id=:transaction_id;');
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt->execute();

        // delete mempool transactions with same transaction id's
        $stmt = $this->db->prepare('DELETE from mempool_inputs WHERE transaction_id=:transaction_id;');
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64);
        $stmt->execute();

        // delete mempool transactions with same transaction id's
        $stmt = $this->db->prepare('DELETE from mempool_outputs WHERE transaction_id=:transaction_id;');
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'transaction_id',
            value: $transactionId,
            pdoType: DatabaseHelpers::ALPHA_NUMERIC,
            maxLength: 64
        );
        $stmt->execute();

        return true;
    }

    /**
     * All mempool transactions, oldest first. They are validated against the chain when a block is built.
     *
     * @param int $height
     * @return array
     */
    public function getAllTransactions(int $height): array
    {
        $returnTransactions = [];
        $stmt = $this->db->prepare('SELECT `transaction_id`,`date_created`,`peer`,`height`,`version`,' .
            '`signature`,`public_key` FROM mempool_transactions WHERE height<=:height ORDER BY `date_created`, `id`');
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'height',
            value: $height,
            pdoType: DatabaseHelpers::INT
        );
        $stmt->execute();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $transaction) {
            // attach the details
            $transaction[self::INPUTS] = $this->stripRow($this->getTransactionInputs($transaction['transaction_id']));
            $transaction[self::OUTPUTS] = $this->stripRow($this->getTransactionOutputs($transaction['transaction_id']));
            $returnTransactions[] = $transaction;
        }

        return $returnTransactions;
    }

    /**
     * @param array $rows
     * @return array
     */
    private function stripRow(array $rows): array
    {
        foreach (array_keys($rows) as $key) {
            unset($rows[$key]['id'], $rows[$key]['transaction_id'], $rows[$key]['spent']);
        }

        return $rows;
    }

    /**
     * @param int $height
     * @return int
     */
    public function getMempoolCount(int $height): int
    {
        $stmt = $this->db->prepare('SELECT count(1) FROM mempool_transactions WHERE height<=:height;');
        $stmt = DatabaseHelpers::filterBind(
            stmt: $stmt,
            fieldName: 'height',
            value: $height,
            pdoType: DatabaseHelpers::INT
        );
        $stmt->execute();

        return (int)$stmt->fetchColumn();
    }
}
