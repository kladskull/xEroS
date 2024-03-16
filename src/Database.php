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

                self::$instance = new PDO('sqlite:' . APP_DIR . strtolower(Config::getProductName()) .
                    $configEnv . '.db');
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                Console::log($e->getMessage());
            }
        }

        return self::$instance;
    }
}
