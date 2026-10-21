<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use PDO;
use RuntimeException;
use function bcadd;
use function time;

/**
 * Class Account
 * @package Blockchain
 */
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

    /**
     * @param int $id
     * @return array|null
     */
    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts ' .
            'WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return array|null
     */
    public function getNewestAccount(): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts ' .
            'ORDER BY id DESC LIMIT 1';
        $stmt = $this->db->query($query);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param string $address
     * @return array|null
     */
    public function getByAddress(string $address): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts ' .
            'WHERE `address` = :address LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'address', $address, DatabaseHelpers::TEXT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param string $publicKeyRaw
     * @return array|null
     */
    public function getByPublicKeyRaw(string $publicKeyRaw): ?array
    {
        $query = 'SELECT `id`,`address`,`public_key`,`public_key_raw`,`private_key`,`date_created` FROM accounts ' .
            ' WHERE `public_key_raw` = :public_key_raw LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'public_key_raw', $publicKeyRaw, DatabaseHelpers::TEXT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @return int
     */
    public function create(): int
    {
        try {
            $ownsTransaction = !$this->db->inTransaction();

            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

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

            if ($ownsTransaction) {

                $this->db->commit();

            }
        } catch (Exception $e) {
            $id = 0;
            Console::log('Rolling back transaction: ' . $e->getMessage());
            if ($ownsTransaction ?? false) {
                $this->db->rollBack();
            }
        }

        return $id;
    }

    /**
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $result = false;
        try {
            $ownsTransaction = !$this->db->inTransaction();

            if ($ownsTransaction) {
                $this->db->beginTransaction();
            }

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

            if ($ownsTransaction) {

                $this->db->commit();

            }
            $result = true;
        } catch (Exception $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());
            if ($ownsTransaction ?? false) {
                $this->db->rollBack();
            }
        }

        return $result;
    }

    /**
     * Unspent outputs paid to $address on the active chain, locked ones included.
     *
     * @param string $address
     * @return string
     */
    public function getBalance(string $address): string
    {
        $stmt = $this->db->prepare(
            'SELECT o.`value` FROM transaction_outputs o JOIN blocks b ON b.block_id=o.block_id ' .
            'WHERE o.`address`=:address AND o.spent=0 AND b.orphan=0'
        );
        $stmt->execute([':address' => $address]);
        $balance = '0';

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $balance = bcadd($balance, (string)$value);
        }

        return $balance;
    }

    /**
     * The balance once the mempool is mined: plus what is on the way in, minus what is being spent.
     *
     * @param string $address
     * @return string
     */
    public function getPendingBalance(string $address): string
    {
        $balance = $this->getBalance($address);

        $stmt = $this->db->prepare('SELECT `value` FROM mempool_outputs WHERE `address`=:address');
        $stmt->execute([':address' => $address]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $balance = bcadd($balance, (string)$value);
        }

        $stmt = $this->db->prepare(
            'SELECT o.`value` FROM mempool_inputs i ' .
            'JOIN transaction_outputs o ON o.transaction_id=i.previous_transaction_id AND o.tx_id=i.previous_tx_out_id ' .
            'JOIN blocks b ON b.block_id=o.block_id ' .
            'WHERE o.`address`=:address AND o.spent=0 AND b.orphan=0'
        );
        $stmt->execute([':address' => $address]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $balance = bcsub($balance, (string)$value);
        }

        return $balance;
    }
}
