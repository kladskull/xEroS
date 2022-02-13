<?php declare(strict_types=1);

namespace Xeros;

class DataStore extends Database
{
    public function get(int $id): ?array
    {
        return $this->db->queryFirstRow("SELECT `key`,`data` FROM key_value_store WHERE id=%i", $id);
    }

    public function getKey(string $key, $default = ''): string|int
    {
        $retVal = $default;
        $values = $this->db->queryFirstRow("SELECT `data`,expires FROM key_value_store WHERE `key`=%s", $key);
        if ($values !== null) {
            $expiry = (int)$values['expires'];
            if ($expiry === 0) {
                $retVal = $values['data'];
            } elseif (time() < $expiry) {
                $retVal = $values['data'];
            }
        }
        return $retVal;
    }

    public
    function add(string $key, string $value, int $expires = 0): int
    {
        if (strlen($key) > 128) {
            return 0;
        }

        try {
            $this->db->startTransaction();

            $this->db->insertUpdate('key_value_store', [
                'key' => $key,
                'data' => $value,
                'expires' => $expires
            ], [
                'data' => $value,
                'expires' => $expires
            ]);
            $id = $this->db->insertId();
            $this->db->commit();
        } catch (MeekroDBException|Exception $ex) {
            $id = 0;
            $this->db->rollback();
        }
        return $id;
    }

    public
    function deleteKey(string $key): bool
    {
        return $this->delete((int)$this->db->queryFirstField("SELECT id FROM key_value_store WHERE `key`=%s", $key));
    }

    public
    function delete(int $id): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();
            $this->db->delete('peers', 'id=%i', $id);
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception) {
            $this->db->rollback();
        }
        return $result;
    }
}