<?php
/**
 * Garment Manufacturing ERP - Database Connection
 * Uses PDO with prepared statements, error mode exception, and UTF-8 encoding.
 */

class Database {
    private static ?PDO $instance = null;

    public static function connect(): PDO {
        if (self::$instance === null) {
            $host = '127.0.0.1';
            $dbName = 'garment_erp';
            $user = 'root';
            $pass = '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host=$host;dbname=$dbName;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                error_log("Database connection error: " . $e->getMessage());
                die("A database error occurred. Please ensure MySQL is running in WampServer.");
            }
        }
        return self::$instance;
    }
}

function get_db(): PDO {
    return Database::connect();
}
