<?php
/**
 * Garment Manufacturing ERP - Database Connection
 * Direct PDO singleton connection helper.
 */

function get_db(): PDO {
    static $instance = null;
    if ($instance === null) {
        $dsn = "mysql:host=127.0.0.1;dbname=garment_erp;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $instance = new PDO($dsn, 'root', '', $options);
        } catch (PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            die("A database error occurred. Please ensure MySQL is running in WampServer.");
        }
    }
    return $instance;
}
