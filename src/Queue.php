<?php declare(strict_types=1);

namespace Xeros;

use Exception;
use PDO;
use RuntimeException;

class Queue
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`date_created`,`command`,`data`,`trys` FROM queue WHERE `id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getItems(string $command, int $limit = 100): array
    {
        $query = 'SELECT `id`,`date_created`,`command`,`data`,`trys` FROM queue WHERE command=:command and trys <5 LIMIT :limit;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'command', $command, DatabaseHelpers::TEXT, 32);
        $stmt = DatabaseHelpers::filterBind($stmt, 'limit', $limit, DatabaseHelpers::INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function incrementFails(int $id): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            $query = 'UPDATE queue SET trys=trys+1 WHERE id=:id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
            $stmt->execute();
            $this->db->commit();
            $result = true;
        } catch (Exception) {
            $this->db->rollback();
        }
        return $result;
    }

    public function clearFails(int $id): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            $query = 'UPDATE queue SET trys=0 WHERE id=:id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
            $stmt->execute();
            $this->db->commit();
            $result = true;
        } catch (Exception) {
            $this->db->rollback();
        }
        return $result;
    }

    public function add(string $command, string $data): int
    {
        try {
            $this->db->beginTransaction();

            // prepare the statement and execute
            $query = 'INSERT INTO queue (`command`,`date_created`,`data`,`trys`) VALUES (:command,:date_created,:data,:trys)';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'address', value: $command, pdoType: DatabaseHelpers::TEXT, maxLength: 32);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'data', value: $data, pdoType: DatabaseHelpers::TEXT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'date_created', value: time(), pdoType: DatabaseHelpers::INT);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'trys', value: 0, pdoType: DatabaseHelpers::INT);
            $stmt->execute();

            // ensure the block was stored
            $id = $this->db->lastInsertId();
            if ($id <= 0) {
                throw new RuntimeException('failed to add queue to the database: ' . $command);
            }
            $this->db->commit();
        } catch (Exception $ex) {
            $id = 0;
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }

        return $id;
    }

    public function prune(): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();

            // delete the block
            $query = 'DELETE FROM queue WHERE trys > 4;';
            $this->db->query($query);

            $this->db->commit();
            $result = true;
        } catch (Exception|RuntimeException $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }
        return $result;
    }

    public function delete(int $id): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();

            // delete the block
            $query = 'DELETE FROM queue WHERE `id` = :id;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(stmt: $stmt, fieldName: 'id', value: $id, pdoType: DatabaseHelpers::INT);
            $stmt->execute();

            $this->db->commit();
            $result = true;
        } catch (Exception|RuntimeException $ex) {
            Console::log('Rolling back transaction: ' . $ex->getMessage());
            $this->db->rollback();
        }
        return $result;
    }
}