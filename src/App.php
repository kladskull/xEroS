<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use function count;
use function in_array;

class App
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function checkMigrations(): void
    {
        // check if migration was run
        $tableCount = 0;
        $requiredTables = [
            'accounts',
            'blocks',
            'key_value_store',
            'logs',
            'mempool_inputs',
            'mempool_outputs',
            'mempool_transactions',
            'peers',
            'queue',
            'transaction_inputs',
            'transaction_outputs',
            'transactions'
        ];

        foreach ($requiredTables as $requiredTable) {
            $query = 'SELECT name FROM sqlite_master WHERE type=\'table\' AND name=:table_name';
            $stmt = $this->db->prepare($query);
            $stmt = DatabaseHelpers::filterBind($stmt, 'table_name', $requiredTable, DatabaseHelpers::TEXT);
            $stmt->execute();
            $tableExists = $stmt->fetchColumn();

            if (in_array($tableExists, $requiredTables, true)) {
                $tableCount++;
            }
        }

        if (count($requiredTables) !== $tableCount) {
            Console::log('Error: Before you run ' . Config::getProductName() . ' you must run ./phinx migrate');
            exit(0);
        }
    }
}
