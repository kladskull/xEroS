<?php declare(strict_types=1);

namespace Blockchain;

use Exception;
use PDO;
use RuntimeException;
use function count;
use function exec;
use function explode;
use function file_get_contents;
use function filter_var;
use function hash;
use function shuffle;
use function time;
use function trim;

/**
 * Class Peer
 * @package Blockchain
 */
class Peer
{
    private PDO $db;
    private DataStore $store;

    public function __construct()
    {
        $this->store = new DataStore();
        $this->db = Database::getInstance();
    }

    /**
     * @return string
     */
    public function getUniquePeerId(): string
    {
        $data = $this->store->getKey('peer_id', '');

        if (empty($data)) {
            $data = '';

            if (PHP_OS === 'Linux') {
                // append whatever we can get from the commands below to add salt to the IP
                $data = trim(file_get_contents('/etc/machine-id'));
                $data .= trim(file_get_contents('/var/lib/dbus/machine-id'));
            }

            if (PHP_OS === 'Darwin' || PHP_OS === 'Linux') {
                $data .= exec('whoami');
            }

            // tie it to an IP address
            $data = hash('ripemd160', hash('ripemd160', $data));
            $this->store->add('peer_id', $data);
        }

        return $data;
    }

    /**
     * @param int $id
     * @return array|null
     */
    public function get(int $id): ?array
    {
        $query = 'SELECT `id`,`address`,`reserve`,`last_ping`,`blacklisted`,`fails`,`date_created` FROM peers WHERE ' .
            '`id` = :id LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'id', $id, DatabaseHelpers::INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param int $limit
     * @return array
     */
    public function getAll(int $limit = 100): array
    {
        $query = 'SELECT `id`,`address`,`reserve`,`last_ping`,`blacklisted`,`fails`,`date_created` FROM peers WHERE ' .
            'blacklisted=0 and fails <5 LIMIT :limit;';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'limit', $limit, DatabaseHelpers::INT);
        $stmt->execute();
        $peers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $seeds = Config::getInitialPeers();
        $tempPeers = [];

        if ($peers === false) {
            $peers = [];
        }

        foreach ($seeds as $seed) {
            $peers[] = [
                'address' => $seed,
            ];
        }

        // remove duplicates
        foreach ($peers as $p) {
            $tempPeers[$p['address']] = $p;
        }

        $peers = $tempPeers;
        shuffle($peers);

        return $peers;
    }

    /**
     * @param string $address
     * @return array|null
     */
    public function getByHostAddress(string $address): ?array
    {
        $query = 'SELECT `id`,`address`,`reserve`,`last_ping`,`blacklisted`,`fails`,`date_created` FROM peers ' .
            'WHERE `address` = :address LIMIT 1';
        $stmt = $this->db->prepare($query);
        $stmt = DatabaseHelpers::filterBind($stmt, 'address', $address, DatabaseHelpers::TEXT, 256);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param string $address
     * @return int
     */
    public function addBlackList(string $address): int
    {
        return $this->add($address, true);
    }

    /**
     * @param string $address
     * @return bool
     */
    public function incrementFails(string $address): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            $query = 'UPDATE peers SET fails=fails+1 WHERE address=:address;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                $stmt,
                'address',
                $address,
                DatabaseHelpers::TEXT,
                256
            );
            $stmt->execute();
            $this->db->commit();
            $result = true;
        } catch (Exception) {
            $this->db->rollback();
        }

        return $result;
    }

    /**
     * @param string $address
     * @return bool
     */
    public function clearFails(string $address): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            $query = 'UPDATE peers SET fails=0 WHERE address=:address;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                $stmt, 'address',
                $address,
                DatabaseHelpers::TEXT,
                256
            );
            $stmt->execute();
            $this->db->commit();
            $result = true;
        } catch (Exception) {
            $this->db->rollback();
        }

        return $result;
    }

    /**
     * @param string $address
     * @return bool
     */
    public function updatePingTime(string $address): bool
    {
        $result = false;
        try {
            $this->db->beginTransaction();
            $query = 'UPDATE peers SET last_ping=:last_ping WHERE address=:address;';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                $stmt, 'address',
                $address,
                DatabaseHelpers::TEXT,
                256
            );
            $stmt = DatabaseHelpers::filterBind(
                $stmt,
                'last_ping',
                time(),
                DatabaseHelpers::INT
            );
            $stmt->execute();
            $this->db->commit();
            $result = true;
        } catch (Exception) {
            $this->db->rollback();
        }

        return $result;
    }

    /**
     * @param string $address
     * @return bool
     */
    private function isValidAddress(string $address): bool
    {
        // Strict validation of peer address format
        $pattern = '/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}:[1-9][0-9]{0,4}$/';
        return (bool)preg_match($pattern, $address);
    }


    /**
     * @param string $address
     * @param bool $blacklisted
     * @return int
     */
    public function add(string $address, bool $blacklisted = false): int
    {
        if (!$this->isValidAddress($address)) {
            // Invalid address format
            Console::log('Failed to add peer to the database - invalid address format: ' . $address);
            return 0;
        }

        try {
            $this->db->beginTransaction();

            // Prepare the statement with parameterized query
            $query = 'INSERT OR REPLACE INTO peers (`address`, `reserve`, `last_ping`, `blacklisted`, `fails`, `date_created`) 
                      VALUES (:address, :reserve, :last_ping, :blacklisted, :fails, :date_created)';
            $stmt = $this->db->prepare($query);

            // Bind parameters
            $stmt->bindParam(':address', $address, PDO::PARAM_STR);
            $reserve = 0; // Set default value for reserve
            $stmt->bindParam(':reserve', $reserve, PDO::PARAM_INT);
            $lastPing = time(); // Current timestamp
            $stmt->bindParam(':last_ping', $lastPing, PDO::PARAM_INT);
            $stmt->bindParam(':blacklisted', $blacklisted, PDO::PARAM_INT);
            $fails = 0; // Set default value for fails
            $stmt->bindParam(':fails', $fails, PDO::PARAM_INT);
            $dateCreated = time(); // Current timestamp
            $stmt->bindParam(':date_created', $dateCreated, PDO::PARAM_INT);

            // Execute the statement
            $stmt->execute();

            // Get the last inserted ID
            $id = (int)$this->db->lastInsertId();

            if ($id <= 0) {
                // Failed to add peer to the database
                throw new RuntimeException('Failed to add peer to the database: ' . $address);
            }

            $this->db->commit();
        } catch (Exception $e) {
            // Log and rollback transaction in case of error
            Console::log('Rolling back transaction: ' . $e->getMessage());
            $this->db->rollback();
            $id = 0; // Return 0 indicating failure
        }

        return $id;
    }

    /**
     * Deletes a peer by its ID from the database.
     *
     * @param int $id The ID of the peer to delete.
     * @return bool True if the deletion was successful, false otherwise.
     */
    public function delete(int $id): bool
    {
        try {
            $this->db->beginTransaction();

            // Prepare the delete statement
            $stmt = $this->db->prepare('DELETE FROM peers WHERE id = :id');
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            // Commit the transaction
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            // Log the error and roll back the transaction
            Console::log('Rolling back transaction: ' . $e->getMessage());
            $this->db->rollBack();
            return false;
        }
    }
}
