<?php declare(strict_types=1);

namespace Xeros;

class Peer
{
    private Http $http;
    private DataStore $store;

    public function __construct()
    {
        $this->http = new Http();
        $this->store = new DataStore();
    }

    public function getUniquePeerId(): string
    {
        $data = $this->store->getKey('peer_id', '');
        if (empty($data)) {
            // append whatever we can get from the commands below to add salt to the IP
            $data = trim(file_get_contents('/etc/machine-id'));
            $data .= trim(file_get_contents('/var/lib/dbus/machine-id'));
            $data .= exec('whoami');
            $data .= $this->http->get(Config::getHostIdService());

            // tie it to an IP address
            $data = hash('ripemd160', hash('ripemd160', $data));
            $this->store->add('peer_id', $data);
        }
        return $data;
    }

    public function get(int $id): ?array
    {
        return $this->db->queryFirstRow("SELECT * FROM peers WHERE id=%i", $id);
    }

    public function ping(string $peer): bool|string
    {
        return $this->http->get($peer . 'ping.php');
    }

    public function refresh(array $peers): bool
    {
        // get a list of peers
        $success = false;

        // make some connections
        foreach ($peers as $id => $p) {
            $success = false;
            Console::console('Refreshing ' . $p);
            $data = $this->http->get($p . '/ping.php');
            $response = json_decode($data, true) ?: null;
            if ($response !== null) {
                $this->updatePingTime($p);
                $success = true;
            } else {
                $this->incrementFails($p);
            }
        }
        return $success;
    }

    public function getAll(int $limit = 100, bool $oldest = false): array
    {
        $timeSql = 'AND (UNIX_TIMESTAMP(NOW())-last_ping < 86400 OR UNIX_TIMESTAMP(NOW())-date_created < 86400) ORDER BY RAND()';
        if ($oldest) {
            // do not disturb recently pinged servers...
            $timeSql = 'AND UNIX_TIMESTAMP(NOW())-last_ping > 86400 ORDER BY last_ping DESC';
        }

        // yea, this is not so good using RAND, but it's not a bottleneck... yet
        $peers = toArray($this->db->query("SELECT address FROM peers WHERE blacklisted=0 AND fails<5 $timeSql LIMIT %i;", $limit));

        $seeds = Config::getInitialPeers();
        foreach ($seeds as $seed) {
            $peers[] = [
                'address' => $seed,
            ];
        }

        // remove duplicates
        $tempPeers = [];
        foreach ($peers as $p) {
            $tempPeers[$p['address']] = $p;
        }
        $peers = $tempPeers;
        shuffle($peers);

        return $peers;
    }

    public function getByHostAddress(string $address): ?array
    {
        return $this->get(
            (int)$this->db->queryFirstField("SELECT id FROM peers WHERE address=%s", $address)
        );
    }

    public function addBlackList(string $address): int
    {
        return $this->add($address, true);
    }

    public function incrementFails(string $address): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();
            $this->db->query("UPDATE peers SET fails=fails+1 WHERE address=%s", $address);
            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception $e) {
            $this->db->rollback();
        }
        return $result;
    }

    public function clearFails(string $address): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();

            if ($this->getByHostAddress($address)) {
                $this->db->query("UPDATE peers SET fails=0 WHERE address=%s", $address);
            }

            $this->db->commit();
            $result = true;
        } catch (MeekroDBException|Exception) {
            $this->db->rollback();
        }
        return $result;
    }

    public function updatePingTime(string $address): bool
    {
        $result = false;
        try {
            $this->db->startTransaction();
            $this->db->update('peers', ['last_ping' => time()], "address=%s", $address);
            $this->db->commit();
            $result = true;

            $this->clearFails($address);
        } catch (MeekroDBException|Exception) {
            $this->db->rollback();
        }
        return $result;
    }

    public function add(string $url, bool $blacklisted = false): int
    {
        $url = trim($url);
        if (strlen($url) > 256) {
            return 0;
        }

        $url = filter_var($url, FILTER_VALIDATE_URL);
        if ($url === false) {
            return 0;
        }

        if (!str_ends_with($url, '/')) {
            $url .= '/';
        }

        try {
            $this->db->startTransaction();

            $activePeers = (int)$this->db->queryFirstField(
                'SELECT count(1) FROM peers WHERE blacklisted=0 AND reserve=0 AND last_ping > UNIX_TIMESTAMP()-86400 AND reserve=0;'
            );

            $reserve = 0;
            if ($activePeers > Config::getMaxPeers()) {
                $reserve = 1;
            }

            if ($blacklisted) {
                $reserve = 0;
            }

            $this->db->insert('peers', [
                'address' => $url,
                'reserve' => $reserve,
                'last_ping' => 0,
                'blacklisted' => $blacklisted,
                'fails' => 0,
            ]);
            $id = $this->db->insertId();
            $this->db->commit();
        } catch (MeekroDBException|Exception $ex) {
            $id = 0;
            $this->db->rollback();
        }
        return $id;
    }

    public function delete(int $id): bool
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