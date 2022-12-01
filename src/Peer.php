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
        $valid = false;
        $host = explode(':', $address);

        if ((count($host) === 2) && filter_var($host[0], FILTER_VALIDATE_IP) 
            && (int)$host[1] > 0 && (int)$host[1] <= 65535) {
            $valid = true;
        }

        return $valid;
    }

    /**
     * @param string $address
     * @param bool $blacklisted
     * @return int
     */
    public function add(string $address, bool $blacklisted = false): int
    {
        if (!$this->isValidAddress($address)) {
            Console::log('failed to add peer to the database - junk address: ' . $address);

            return 0;
        }

        if ($this->getByHostAddress($address) !== null) {
            Console::log('duplicate peer address, not adding: ' . $address);

            return 0;
        }

        try {
            $blist = $blacklisted ? 1 : 0;
            $this->db->beginTransaction();

            // prepare the statement and execute
            $query = 'INSERT OR REPLACE INTO peers (`address`,`reserve`,`last_ping`,`blacklisted`,`fails`,' .
                '`date_created`) VALUES (:address,:reserve,:last_ping,:blacklisted,:fails,:date_created)';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'address',
                value: $address,
                pdoType: DatabaseHelpers::TEXT,
                maxLength: 256
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'last_ping',
                value: 0,
                pdoType: DatabaseHelpers::INT
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'blacklisted',
                value: $blist,
                pdoType: DatabaseHelpers::INT
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'reserve',
                value: 0,
                pdoType: DatabaseHelpers::INT
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'fails',
                value: 0,
                pdoType: DatabaseHelpers::INT
            );
            $stmt = DatabaseHelpers::filterBind(
                stmt: $stmt,
                fieldName: 'date_created',
                value: time(),
                pdoType: DatabaseHelpers::INT
            );
            $stmt->execute();

            // ensure the block was stored
            $id = (int)$this->db->lastInsertId();

            if ($id <= 0) {
                throw new RuntimeException('failed to add peer to the database: ' . $address);
            }

            $this->db->commit();
        } catch (Exception $e) {
            $id = 0;
            Console::log('Rolling back transaction: ' . $e->getMessage());
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
            $query = 'DELETE FROM peers WHERE `id` = :id;';
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
        } catch (Exception|RuntimeException $e) {
            Console::log('Rolling back transaction: ' . $e->getMessage());
            $this->db->rollback();
        }

        return $result;
    }
}
