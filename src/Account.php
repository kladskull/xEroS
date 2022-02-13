<?php declare(strict_types=1);

namespace Xeros;

use Exception;
use Medoo\Medoo;
use PDOException;

class Account
{
    private OpenSsl $openSsl;
    private Address $address;
    private Medoo $db;

    public function __construct()
    {
        $this->db = Database::getDbConn();
        $this->openSsl = new OpenSsl();
        $this->address = new Address();
    }

    public function get(int $id): array
    {
        return $this->db->get(
            table: 'accounts',
            columns: ['address', 'public_hash', 'public_key', 'private_key', 'date_created'],
            where: ['id' => $id]
        );
    }

    public function getBy(string $fieldName, string $value): ?array
    {
        if (in_array($fieldName, ['address', 'public_hash'])) {
            return null;
        }

        $record = $this->db->get(
            table: 'accounts',
            columns: ['id'],
            where: [$fieldName => $value]
        );
        return $this->get((int)$record['id']);
    }

    public function create(): int
    {
        $id = 0;
        try {
            $this->db->action(function () {
                $keys = $this->openSsl->createRsaKeyPair();

                $this->db->insert('accounts',
                    [
                        'public_key' => $keys['public_key'],
                        'public_key_raw' => $keys['public_key_raw'],
                        'private_key' => $keys['private_key'],
                        'address' => $this->address->create($keys['public_key']),
                    ]);
            });
            $id = $this->db->id();

        } catch (PDOException|Exception $ex) {
            Console::console($ex->getMessage());
        }

        return $id;
    }

    public function delete(int $id): bool
    {
        $result = false;

        try {
            $this->db->action(function (int $id) {
                $this->db->delete('accounts', ['AND' => ['age' => $id]]);
            });
            $result = true;
        } catch (PDOException|Exception $ex) {
            Console::console($ex->getMessage());
        }

        return $result;
    }

    public function getBalance(string $address): string
    {
        $balance = "0";
        $unspentTransactions = $this->db->select(
            table: 'transaction_outputs',
            join: null,
            columns: 'value',
            where: ['address' => $address]
        );

        foreach ($unspentTransactions as $unspentTransaction) {
            $balance = bcadd($balance, $unspentTransaction['value'], 0);
        }

        return $balance;
    }

    public function getPendingBalance(string $address): string
    {
        // get the current balance
        $balance = $this->getBalance($address);

        // get all the mempool transactions for the address
        $transactions = [];
        $mempoolTransactions = $this->db->select(
            table: 'mempool_outputs',
            join: null,
            columns: ['transaction_id', 'tx_id', 'value'],
            where: ['address' => $address]
        );

        foreach ($mempoolTransactions as $mempoolTransaction) {
            $key = $mempoolTransaction['transaction_id'] . '-' . $mempoolTransaction['tx_id'];
            $transactions[$key] = $mempoolTransaction['value'];
        }

        // remove any spent transactions from the array
        $mempoolSpends = $this->db->select(
            table: 'mempool_inputs',
            join: null,
            columns: ['previous_transaction_id', 'previous_tx_out_id'],
            where: ['address' => $address]
        );
        foreach ($mempoolSpends as $mempoolSpend) {
            $key = $mempoolSpend['previous_transaction_id'] . '-' . $mempoolSpend['previous_tx_out_id'];
            unset($transactions[$key]);
        }

        // add the pending to the balance
        foreach ($transactions as $key => $value) {
            $balance = bcadd($balance, $value, 0);
        }

        return $balance;
    }
}