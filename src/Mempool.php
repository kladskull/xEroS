<?php declare(strict_types=1);

namespace Xeros;

use Exception;
use PDO;
use RuntimeException;

class Mempool
{
    protected PDO $db;
    protected Transaction $transaction;

    public const Inputs = 'txIn';
    public const Outputs = 'txOut';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->transaction = new Transaction();
    }

    public function add(array $transaction): bool
    {
        $result = false;

        try {
            $validationResult = $this->transaction->validate($transaction);
            if ($validationResult['validated'] === false) {
                return false;
            }
        } catch (Exception $ex) {
            Console::log('Error ' . $ex->getMessage());
            return false;
        }

        // store and clip off the spent
        $txIns = $transaction[Transaction::Inputs];
        unset($transaction[Transaction::Inputs],);

        // store and clip off the unspent
        $txOuts = $transaction[Transaction::Outputs];
        unset($transaction[Transaction::Outputs]);

        try {
            $this->db->beginTransaction();

            // prepare the statement and execute
            $query = 'INSERT INTO mempool_transactions (`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key`) VALUES (:transaction_id,:date_created,:peer,:height,:version,:signature,:public_key)';
            $stmt = $this->db->prepare($query);
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
            foreach ($txIns as $txIn) {
                $txIn['transaction_id'] = $transaction['transaction_id'];

                // make sure there is a previous unspent transaction
                $query = 'SELECT `transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`hash` FROM transaction_outputs WHERE spent=0 AND transaction_id=:transaction_id AND tx_id=:tx_id';
                $stmt = $this->db->prepare($query);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_transaction_id', value: $txIn['previous_transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'previous_tx_out_id', value: $txIn['previous_tx_out_id'], pdoType: DatabaseHelpers::INT);
                $stmt->execute();
                $txOut = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($txOut) <= 0) {
                    throw new RuntimeException('Cannot double spent transaction for: ' . $txIn['previous_transaction_id'] . ' - ' . $txIn['previous_tx_out_id']);
                }

                // run script
                $scriptResult = $this->transaction->unlockTransaction($txIn, $txOut);
                if (!$scriptResult) {
                    throw new RuntimeException("Cannot unlock script for: " . $txIn['transaction_id']);
                }

                // add the txIn record to the db
                $query = 'INSERT INTO mempool_inputs (`transaction_id`,`tx_id`,`previous_transaction_id`,`previous_tx_out_id`,`script`) VALUES (:transaction_id,:tx_id,:previous_transaction_id,:previous_tx_out_id,:script)';
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
            }

            // add txOut
            foreach ($txOuts as $txOut) {
                $txOut['transaction_id'] = $transaction['transaction_id'];

                // add the txIn record to the db
                $query = 'INSERT INTO mempool_outputs (`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`spent`,`hash`) VALUES (:transaction_id,:tx_id,:address,:value,:script,:lock_height,:spent,:hash)';
                $stmt = $this->db->prepare($query);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $txOut['transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'tx_id', value: $txOut['tx_id'], pdoType: DatabaseHelpers::INT);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'address', value: $txOut['previous_transaction_id'], pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 40);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'value', value: $txOut['value'], pdoType: DatabaseHelpers::INT);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'script', value: $txOut['script'], pdoType: DatabaseHelpers::TEXT);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'lock_height', value: $txOut['lock_height'], pdoType: DatabaseHelpers::INT);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'spent', value: $txOut['spent'], pdoType: DatabaseHelpers::INT);
                $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'script', value: $txOut['hash'], pdoType: DatabaseHelpers::TEXT);
                $stmt->execute();
                $transactionTxId = (int)$this->db->lastInsertId();
                if ($transactionTxId <= 0) {
                    throw new RuntimeException('failed to add a new transaction tx as unspent in the database: ' . $txOut['transaction_id'] . ' - ' . $txOut['$txIn']);
                }
            }

            // unlock tables and commit
            $this->db->commit();
            $result = true;
        } catch (Exception $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }

        return $result;
    }

    public function get(int $id): array|null
    {
        $query = 'SELECT `id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key` FROM mempool_transactions WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'block_id', $id, DatabaseHelpers::INT, 0);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByTransactionId(string $transactionId): array|null
    {
        $query = 'SELECT `id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key` FROM mempool_transactions WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'transaction_id', $transactionId, DatabaseHelpers::ALPHA_NUMERIC, 64);
        $stmt->execute();
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($transaction !== null) {
            $txIns = $this->getTransactionInputs($transactionId);
            $txOuts = $this->getTransactionOutputs($transactionId);

            // attach the details
            $transaction[self::Inputs] = $txIns;
            $transaction[self::Outputs] = $txOuts;
        }

        return $transaction;
    }

    public function getTransactionInputs(string $transactionId): array
    {
        $query = 'SELECT `id`,`transaction_id`,`tx_id`,`previous_transaction_id`,`previous_tx_out_id`,`script` FROM mempool_inputs WHERE transaction_id=:transaction_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        return Transaction::sortTx($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    public function getTransactionOutputs(string $transactionId): bool|array|null
    {
        $query = 'SELECT `id`,`transaction_id`,`tx_id`,`address`,`value`,`script`,`lock_height`,`spent`,`hash` FROM mempool_outputs WHERE transaction_id=:transaction_id;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        return Transaction::sortTx($stmt->fetchAll(PDO::FETCH_ASSOC)) ?: [];
    }

    public function delete(string $transactionId): bool
    {
        // delete mempool transactions with same transaction id's
        $stmt = $this->db->prepare('DELETE from mempool_transactions WHERE transaction_id=:transaction_id;');
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        // delete mempool transactions with same transaction id's
        $stmt = $this->db->prepare('DELETE from mempool_inputs WHERE transaction_id=:transaction_id;');
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        // delete mempool transactions with same transaction id's
        $stmt = $this->db->prepare('DELETE from mempool_outputs WHERE transaction_id=:transaction_id;');
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'transaction_id', value: $transactionId, pdoType: DatabaseHelpers::ALPHA_NUMERIC, maxLength: 64);
        $stmt->execute();

        return true;
    }

    public function getAllTransactions(int $height): array
    {
        $returnTransactions = [];
        $stmt = $this->db->query('SELECT `id`,`transaction_id`,`date_created`,`peer`,`height`,`version`,`signature`,`public_key` FROM mempool_transactions WHERE height=:height');
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'height', value: $height, pdoType: DatabaseHelpers::INT);
        $stmt->execute();

        $transactions = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach ($transactions as $transaction) {
            // attach the details
            $transaction[self::Inputs] = $this->getTransactionInputs($transaction['transaction_id']);
            $transaction[self::Outputs] = $this->getTransactionOutputs($transaction['transaction_id']);
            $returnTransactions[] = $transaction;
        }

        return $returnTransactions;
    }

    public function getMempoolCount(int $height): int
    {
        $stmt = $this->db->query('SELECT count(1) FROM mempool_transactions WHERE height=:height;');
        $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'height', value: $height, pdoType: DatabaseHelpers::INT);
        $stmt->execute();
        return $stmt->fetchColumn() ?: 0;
    }

}