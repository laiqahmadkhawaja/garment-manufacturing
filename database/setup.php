<?php
/**
 * Garment ERP - Database Setup & Migrator
 */
require_once __DIR__ . '/../config/config.php';

$host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
$port = defined('DB_PORT') ? DB_PORT : '3306';
$user = defined('DB_USER') ? DB_USER : 'root';
$pass = defined('DB_PASS') ? DB_PASS : '';
$dbName = defined('DB_NAME') ? DB_NAME : 'garment_erp';

echo "=== Garment Manufacturing & Production ERP Database Setup ===\n";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbName`");
    echo "[✓] Database '$dbName' ready.\n";

    $sqlFile = __DIR__ . '/schema.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("schema.sql not found at $sqlFile");
    }

    $sql = file_get_contents($sqlFile);
    $queries = explode(";\n", $sql);
    foreach ($queries as $q) {
        $q = trim($q);
        if (!empty($q)) {
            $pdo->exec($q);
        }
    }
    echo "[✓] Database tables and seed data created successfully!\n";

    // Run schema migrations for enhanced capabilities
    if (file_exists(__DIR__ . '/migrate_schema.php')) {
        require_once __DIR__ . '/migrate_schema.php';
    }

    $tables = ['users', 'roles', 'departments', 'operations', 'workflow_templates', 'products', 'product_elements', 'designs', 'design_elements', 'colors', 'sizes', 'workers', 'materials', 'customers', 'warehouses'];
    foreach ($tables as $tbl) {
        $stmt = $pdo->query("SELECT COUNT(*) AS cnt FROM `$tbl`");
        $cnt = $stmt->fetchColumn();
        echo " - Table `$tbl`: $cnt records\n";
    }

    echo "\n[SUCCESS] Garment ERP setup completed successfully.\n";

} catch (Exception $e) {
    echo "[ERROR] Setup failed: " . $e->getMessage() . "\n";
    exit(1);
}
