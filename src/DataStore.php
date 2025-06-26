<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use PDO;

/**
 * Class DataStore
 * @package Blockchain
 */
class DataStore
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function get(int $id): ?array
    {
        $query = 'SELECT `key`,`data` FROM key_value_store WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param string $key
     * @param string $default
     * @return string|int
     */
    public function getKey(string $key, $default = ''): string|int
    {
        $query = 'SELECT `data`,`expires` FROM key_value_store WHERE `key` = :key LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'key', $key, DatabaseHelpers::TEXT);
        $stmt->execute();

        $retVal = $default;
        $record = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($record !== null) {
            $expires = $record['expires'];

            if ($expires === 0 || time() < $expires) {
                $retVal = $record['data'];
            }

        }

        return $retVal;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $expires
     * @return int
     */
    public function add(string $key, mixed $value, int $expires = 0): int
    {
        if (strlen($key) > 128) {
            return 0;
        }

        try {
            $this->db->beginTransaction();

            $query = 'INSERT OR REPLACE INTO key_value_store (`key`,`data`,`expires`) VALUES (:key,:data,:expires);';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind($stmt, 'key', $key, DatabaseHelpers::TEXT, 128);
            $stmt = DatabaseHelpers::filterBind($stmt, 'data', $value, DatabaseHelpers::TEXT);
            $stmt = DatabaseHelpers::filterBind($stmt, 'expires', $expires, DatabaseHelpers::INT);
            $stmt->execute();
            $id = (int)$this->db->lastInsertId();

            $this->db->commit();
        } catch (Exception $e) {
            Console::log('Error storing key: ' . $e->getMessage());
            $id = 0;
            $this->db->rollback();
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
            $this->db->beginTransaction();

            // delete the block
            $query = 'DELETE FROM key_value_store WHERE `id` = :id;';
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

    /**
     * @param string $key
     * @return bool
     */
    public function deleteKey(string $key): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();

            // delete the block
            $query = 'DELETE FROM key_value_store WHERE `key` = :key;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'key',
                value: $key,
                pdoType: DatabaseHelpers::TEXT,
                maxLength: 128
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
}
