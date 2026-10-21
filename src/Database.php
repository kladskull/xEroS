<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PDOException;

/**
 * Class Database
 * @package Blockchain
 */
class Database
{
    protected static PDO $instance;

    /**
     * @return PDO
     */
    public static function getInstance(): PDO
    {
        if (empty(self::$instance)) {
            try {
                $configEnv = Config::getDbEnvironment();
                if (trim($configEnv) !== '') {
                    $configEnv = '-' . $configEnv;
                }

                $path = APP_DIR . strtolower(Config::getProductName()) . $configEnv . '.db';

                // the accounts table holds private keys, keep the file readable by this user only
                if (!file_exists($path)) {
                    touch($path);
                }

                chmod($path, 0600);
                self::$instance = new PDO('sqlite:' . $path);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // the node and the miner share the file, wait for a lock instead of failing
                self::$instance->setAttribute(PDO::ATTR_TIMEOUT, 10);
            } catch (PDOException $e) {
                Console::log($e->getMessage());
            }
        }

        return self::$instance;
    }
}
