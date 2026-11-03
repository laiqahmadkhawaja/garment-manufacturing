<?php
require_once __DIR__ . '/../config/config.php';
$db = get_db();

echo "Running Migration for Smart Lot Creation...\n";

// 1. Upgrade colors table
$cols = $db->query("SHOW COLUMNS FROM colors")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('color_code', $cols)) {
    $db->exec("ALTER TABLE colors ADD COLUMN color_code VARCHAR(40) NULL UNIQUE AFTER name");
    echo "Added color_code to colors table.\n";
}
if (!in_array('yarn_stock_kg', $cols)) {
    $db->exec("ALTER TABLE colors ADD COLUMN yarn_stock_kg DECIMAL(10,2) DEFAULT 0.00 AFTER hex_code");
    echo "Added yarn_stock_kg to colors table.\n";
}

// Seed initial color codes and realistic yarn stock if missing
$seedColors = [
    ['name' => 'Black', 'code' => '1136', 'hex' => '#1a1a1a', 'yarn' => 450.00],
    ['name' => 'Navy', 'code' => '1138', 'hex' => '#0f172a', 'yarn' => 380.00],
    ['name' => 'Grey', 'code' => '9024', 'hex' => '#6b7280', 'yarn' => 290.00],
    ['name' => 'White', 'code' => '1001', 'hex' => '#ffffff', 'yarn' => 210.00],
    ['name' => 'Maroon', 'code' => '2204', 'hex' => '#881337', 'yarn' => 160.00],
    ['name' => 'Blue', 'code' => '5512', 'hex' => '#2563eb', 'yarn' => 140.00],
    ['name' => 'Beige', 'code' => '7801', 'hex' => '#d4c5b9', 'yarn' => 95.00],
    ['name' => 'Olive Green', 'code' => '3340', 'hex' => '#3f6212', 'yarn' => 80.00],
];

foreach ($seedColors as $sc) {
    $stmt = $db->prepare("UPDATE colors SET color_code = ?, yarn_stock_kg = ? WHERE name = ? AND (color_code IS NULL OR color_code = '')");
    $stmt->execute([$sc['code'], $sc['yarn'], $sc['name']]);
}

// 2. Upgrade lots table
$lotCols = $db->query("SHOW COLUMNS FROM lots")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('shift_type', $lotCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN shift_type ENUM('Single Shift', 'Double Shift') DEFAULT 'Single Shift' AFTER supervisor_name");
    echo "Added shift_type to lots.\n";
}
if (!in_array('operator_id', $lotCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN operator_id INT NULL AFTER shift_type");
    $db->exec("ALTER TABLE lots ADD INDEX idx_operator_id (operator_id)");
    echo "Added operator_id to lots.\n";
}
if (!in_array('night_operator_id', $lotCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN night_operator_id INT NULL AFTER operator_id");
    $db->exec("ALTER TABLE lots ADD INDEX idx_night_operator_id (night_operator_id)");
    echo "Added night_operator_id to lots.\n";
}
if (!in_array('size_mode', $lotCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN size_mode ENUM('Single Size', 'Multi Size') DEFAULT 'Single Size' AFTER total_sizes");
    echo "Added size_mode to lots.\n";
}
if (!in_array('single_size_id', $lotCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN single_size_id INT NULL AFTER size_mode");
    echo "Added single_size_id to lots.\n";
}
if (!in_array('has_anatomy_variants', $lotCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN has_anatomy_variants TINYINT(1) DEFAULT 0 AFTER single_size_id");
    echo "Added has_anatomy_variants to lots.\n";
}
if (!in_array('anatomy_breakdown', $lotCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN anatomy_breakdown LONGTEXT NULL AFTER has_anatomy_variants");
    echo "Added anatomy_breakdown to lots.\n";
}

// 3. Upgrade lot_variants table
$varCols = $db->query("SHOW COLUMNS FROM lot_variants")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('color_code', $varCols)) {
    $db->exec("ALTER TABLE lot_variants ADD COLUMN color_code VARCHAR(50) NULL AFTER color_id");
    echo "Added color_code to lot_variants.\n";
}

// Update existing lot variants with color codes
$db->exec("UPDATE lot_variants lv 
           JOIN colors c ON lv.color_id = c.id 
           SET lv.color_code = c.color_code 
           WHERE lv.color_code IS NULL OR lv.color_code = ''");

echo "Migration Completed Successfully!\n";
