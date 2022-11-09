<?php declare(strict_types=1);

namespace Blockchain;

use PDO;
use PDOException;

class Database
{
    protected static PDO $instance;

    public static function getInstance(): PDO
    {
        if (empty(self::$instance)) {
            try {
                self::$instance = new PDO('sqlite:' . APP_DIR . strtolower(Config::getProductName()) .
                    '-' . Config::getDbEnvironment() . '.db');
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                Console::log($e->getMessage());
            }
        }

        return self::$instance;
    }

}
