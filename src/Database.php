<?php declare(strict_types=1);

namespace Xeros;

use PDO;
use PDOException;

class Database
{
    protected static PDO $instance;

    protected function __construct()
    {
    }

    public static function getInstance(): PDO
    {
        if (empty(self::$instance)) {
            try {
                self::$instance = new PDO(
                    dsn: 'sqlite:' . APP_DIR . strtolower(Config::getProductName()) . '.db',
                    options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $error) {
                Console::log($error->getMessage());
            }

        }

        return self::$instance;
    }
}