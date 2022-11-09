<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use PDO;
use RuntimeException;
use function bcadd;
use function time;

class Account
{
    private PDO $db;
    private Address $address;
    private OpenSsl $openSsl;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->address = new Address();
        $this->openSsl = new OpenSsl();
    }

    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts ' .
            'WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getNewestAccount(): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts ' .
            'ORDER BY id DESC LIMIT 1';
        $stmt = $this->db->query($query);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByAddress(string $address): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts ' .
            'WHERE `address` = :address LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'address', $address, DatabaseHelpers::TEXT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getByPublicKeyRaw(string $publicKeyRaw): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts ' .
            ' WHERE `public_key_raw` = :public_key_raw LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'public_key_raw', $publicKeyRaw, DatabaseHelpers::TEXT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function create(): int
    {
        try {
            $this->db->beginTransaction();

            $keys = $this->openSsl->createRsaKeyPair();
            $address = $this->address->create($keys['public_key']);
            $dateCreated = time();

            // prepare the statement and execute
            $query = 'INSERT INTO accounts (`public_key`,`public_key_raw`,`private_key`,`address`,`date_created`) ' .
                'VALUES (:public_key,:public_key_raw,:private_key,:address,:date_created)';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'public_key',
                value: $keys['public_key'],
                pdoType: DatabaseHelpers::TEXT
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'public_key_raw',
                value: $keys['public_key_raw'],
                pdoType: DatabaseHelpers::TEXT
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'private_key',
                value: $keys['private_key'],
                pdoType: DatabaseHelpers::TEXT
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'address',
                value: $address,
                pdoType: DatabaseHelpers::ALPHA_NUMERIC,
                maxLength: 40
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'date_created',
                value: $dateCreated,
                pdoType: DatabaseHelpers::INT
            );
            $stmt->execute();

            // ensure the block was stored
            $id = (int)$this->db->lastInsertId();

            if ($id <= 0) {
                throw new RuntimeException("failed to add account to the database");
            }

            $this->db->commit();
        } catch (Exception $e) {
            $id = 0;
            Console::log('Rolling back transaction: ' . $e->getMessage());
            $this->db->rollback();
        }

        return $id;
    }

    public function delete(int $id): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();

            // delete the block
            $query = 'DELETE FROM accounts WHERE `id` = :id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'id',
                value: $id,
                pdoType: DatabaseHelpers::INT
            );
            $stmt->execute();

            $this->db->commit();
            $result = true;
        } catch (Exception $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());
            $this->db->rollback();
        }

        return $result;
    }

    public function getBalance(string $address): string
    {
        $balance = "0";
        $query = 'SELECT `value` FROM transaction_outputs WHERE `address` = :address';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'address', $address, DatabaseHelpers::ALPHA_NUMERIC, 40);
        $stmt->execute();
        $unspentTransactions = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($unspentTransactions as $unspentTransaction) {
            $balance = bcadd($balance, $unspentTransaction['value']);
        }

        return $balance;
    }

    public function getPendingBalance(string $address): string
    {
        // get the current balance
        $balance = $this->getBalance($address);

        // get all the mempool transactions for the address
        $query = 'SELECT `transaction_id`,`tx_id`,`value` FROM mempool_outputs WHERE `address` = :address';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'address', $address, DatabaseHelpers::ALPHA_NUMERIC, 40);
        $stmt->execute();
        $transactions = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ($transactions as $transaction) {
            $key = $transaction['transaction_id'] . '-' . $transaction['tx_id'];
            $transactions[$key] = $transaction['value'];
        }

        // add the pending to the balance
        foreach ($transactions as $value) {
            $balance = bcadd($balance, $value);
        }

        return $balance;
    }
}
