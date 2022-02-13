<?php declare(strict_types=1);

namespace Xeros;

class Queue extends Database
{
    public function get(int $id): ?array
    {
        return $this->db->queryFirstRow("SELECT id,date_created,command,data,trys FROM queue WHERE id=%i", $id);
    }

    public function getItems(string $command, int $limit = 100): array
    {
        $limit = filterLimit($limit, 1, 100);

        return toArray(
            $this->db->query("SELECT id,date_created,command,data,trys FROM queue WHERE command=%s AND trys<5 ORDER BY id LIMIT %i;", $command, $limit)
        );
    }

    public function incrementFails(int $id): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();
            $this->db->query("UPDATE queue SET trys=trys+1 WHERE id=%i", $id);
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception) {
            $this->db->rollback();
        }
        return $result;
    }

    public function clearFails(int $id): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();
            $this->db->query("UPDATE queue SET trys=0 WHERE id=%i", $id);
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception) {
            $this->db->rollback();
        }
        return $result;
    }

    public function add(string $command, string $data): int
    {
        if (empty($command) || strlen($command) > 32) {
            return 0;
        }

        try {
            $this->db->startTransaction();

            $this->db->insert('queue', [
                'date_created' => time(),
                'command' => $command,
                'data' => $data,
                'trys' => 0,
            ]);
            $id = $this->db->insertId();
            $this->db->commit();
        } catch (MeekroDBException|Exception $ex) {
            $id = 0;
            $this->db->rollback();
        }
        return $id;
    }

    // prune in batches so it doesn't lock the system up
    public function prune()
    {
        $result = false;
        try {
            $this->db->startTransaction();
            $this->db->query("DELETE FROM queue WHERE trys > 4 LIMIT 100;");
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception) {
            $this->db->rollback();
        }
        return $result;
    }

    public function delete(int $id): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();
            $this->db->delete('queue', 'id=%i', $id);
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception $ex) {
            print_r($ex);
            $this->db->rollback();
        }
        return $result;
    }
}