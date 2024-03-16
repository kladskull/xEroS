<?php
declare(strict_types=1);

namespace Blockchain;

use Exception;
use PDO;
use RuntimeException;

class Queue
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieves a queue item by its ID.
     *
     * @param int $id The ID of the queue item.
     * @return array|null The queue item data if found, null otherwise.
     */
    public function get(int $id): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT `id`, `date_created`, `command`, `data`, `trys` FROM queue WHERE `id` = :id LIMIT 1');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            Console::log('Error retrieving queue item: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieves queue items by command with a limit.
     *
     * @param string $command The command associated with the queue items.
     * @param int $limit The maximum number of items to retrieve.
     * @return array The array of queue items.
     */
    public function getItems(string $command, int $limit = 100): array
    {
        try {
            $stmt = $this->db->prepare('SELECT `id`, `date_created`, `command`, `data`, `trys` FROM queue WHERE command = :command AND trys < 5 LIMIT :limit');
            $stmt->bindValue(':command', $command, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            Console::log('Error retrieving queue items: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Increments the failure count for a queue item.
     *
     * @param int $id The ID of the queue item.
     * @return bool True if successful, false otherwise.
     */
    public function incrementFails(int $id): bool
    {
        try {
            $stmt = $this->db->prepare('UPDATE queue SET trys = trys + 1 WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            Console::log('Error incrementing failure count: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clears the failure count for a queue item.
     *
     * @param int $id The ID of the queue item.
     * @return bool True if successful, false otherwise.
     */
    public function clearFails(int $id): bool
    {
        try {
            $stmt = $this->db->prepare('UPDATE queue SET trys = 0 WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            Console::log('Error clearing failure count: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Adds a new item to the queue.
     *
     * @param string $command The command associated with the queue item.
     * @param string $data The data to be stored in the queue item.
     * @return int The ID of the newly added queue item, or 0 on failure.
     */
    public function add(string $command, string $data): int
    {
        try {
            $stmt = $this->db->prepare('INSERT INTO queue (`command`, `date_created`, `data`, `trys`) VALUES (:command, :date_created, :data, 0)');
            $stmt->bindValue(':command', $command, PDO::PARAM_STR);
            $stmt->bindValue(':date_created', time(), PDO::PARAM_INT);
            $stmt->bindValue(':data', $data, PDO::PARAM_STR);
            $stmt->execute();
            $id = (int)$this->db->lastInsertId();
            return $id > 0 ? $id : 0;
        } catch (Exception $e) {
            Console::log('Error adding item to queue: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Deletes a queue item by its ID.
     *
     * @param int $id The ID of the queue item to delete.
     * @return bool True if successful, false otherwise.
     */
    public function delete(int $id): bool
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM queue WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            Console::log('Error deleting item from queue: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prunes (deletes) queue items with failure count greater than 4.
     *
     * @return bool True if successful, false otherwise.
     */
    public function prune(): bool
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM queue WHERE trys > 4');
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            Console::log('Error pruning queue: ' . $e->getMessage());
            return false;
        }
    }
}
