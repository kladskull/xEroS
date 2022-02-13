<?php declare(strict_types=1);

namespace Xeros;

use Exception;
use Medoo\Medoo;
use RuntimeException;

class Database
{

    private ?Medoo $dbConn = null;
    private static ?Database $instance = null;

    private function __construct()
    {
    }

    private static function getInstance()
    {
        if (self::$instance === null) {
            $className = __CLASS__;
            self::$instance = new $className;
        }

        return self::$instance;
    }

    private static function initConnection()
    {
        $db = self::getInstance();

        // Connect the database.
        $db->dbConn = new Medoo([
            'type' => 'sqlite',
            // todo: add development, testing and production based on config
            'database' => APP_DIR . strtolower(Config::getProductName()) . '.db'
        ]);

        return $db;
    }

    public static function getDbConn(): ?Medoo
    {
        try {
            return self::initConnection()->dbConn;
        } catch (Exception $ex) {
            Log::console("Unable to connect to the database " . $ex->getMessage());
            return null;
        }
    }

    public function __clone()
    {
        throw new RuntimeException("Can't clone a singleton");
    }
}