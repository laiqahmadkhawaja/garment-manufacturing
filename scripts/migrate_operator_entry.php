<?php
require_once __DIR__ . '/../config/config.php';
$db = get_db();

echo "Running Migration for Operator Initial Production Entry...\n";

// 1. Make color_id and size_id nullable in production_entries
$db->exec("ALTER TABLE production_entries MODIFY COLUMN color_id INT NULL");
$db->exec("ALTER TABLE production_entries MODIFY COLUMN size_id INT NULL");
echo "Modified color_id and size_id to be NULLABLE in production_entries.\n";

// 2. Add shift column to production_entries if missing
$cols = $db->query("SHOW COLUMNS FROM production_entries")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('shift', $cols)) {
    $db->exec("ALTER TABLE production_entries ADD COLUMN shift ENUM('Day Shift', 'Night Shift', 'General') DEFAULT 'General' AFTER worker_id");
    echo "Added shift column to production_entries.\n";
}

echo "Migration completed successfully!\n";
