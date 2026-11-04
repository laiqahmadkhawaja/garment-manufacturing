<?php
require_once __DIR__ . '/../config/config.php';
$db = get_db();

echo "=== Migrating Database Schema for Latest Capabilities ===\n";

// 1. Colors
$cCols = $db->query("SHOW COLUMNS FROM colors")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('color_code', $cCols)) {
    $db->exec("ALTER TABLE colors ADD COLUMN color_code VARCHAR(50) NULL AFTER id");
    echo "[✓] Added color_code to colors\n";
}
if (!in_array('yarn_stock_kg', $cCols)) {
    $db->exec("ALTER TABLE colors ADD COLUMN yarn_stock_kg DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER hex_code");
    echo "[✓] Added yarn_stock_kg to colors\n";
}

// Seed initial color codes and yarn stock
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

// 2. Lots
$lCols = $db->query("SHOW COLUMNS FROM lots")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('shift_type', $lCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN shift_type ENUM('Single Shift', 'Double Shift') DEFAULT 'Single Shift' AFTER supervisor_name");
    echo "[✓] Added shift_type to lots\n";
}
if (!in_array('operator_id', $lCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN operator_id INT NULL AFTER shift_type");
    $db->exec("ALTER TABLE lots ADD CONSTRAINT fk_lot_operator FOREIGN KEY (operator_id) REFERENCES workers(id) ON DELETE SET NULL");
    echo "[✓] Added operator_id to lots\n";
}
if (!in_array('night_operator_id', $lCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN night_operator_id INT NULL AFTER operator_id");
    $db->exec("ALTER TABLE lots ADD CONSTRAINT fk_lot_night_operator FOREIGN KEY (night_operator_id) REFERENCES workers(id) ON DELETE SET NULL");
    echo "[✓] Added night_operator_id to lots\n";
}
if (!in_array('size_mode', $lCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN size_mode ENUM('Single Size', 'Multi Size') DEFAULT 'Single Size' AFTER total_sizes");
    echo "[✓] Added size_mode to lots\n";
}
if (!in_array('single_size_id', $lCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN single_size_id INT NULL AFTER size_mode");
    echo "[✓] Added single_size_id to lots\n";
}
if (!in_array('has_anatomy_variants', $lCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN has_anatomy_variants TINYINT(1) DEFAULT 0 AFTER single_size_id");
    echo "[✓] Added has_anatomy_variants to lots\n";
}
if (!in_array('anatomy_breakdown', $lCols)) {
    $db->exec("ALTER TABLE lots ADD COLUMN anatomy_breakdown LONGTEXT NULL AFTER has_anatomy_variants");
    echo "[✓] Added anatomy_breakdown to lots\n";
}

// 3. Lot Variants
$vCols = $db->query("SHOW COLUMNS FROM lot_variants")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('color_code', $vCols)) {
    $db->exec("ALTER TABLE lot_variants ADD COLUMN color_code VARCHAR(50) NULL AFTER color_id");
    echo "[✓] Added color_code to lot_variants\n";
}
$db->exec("UPDATE lot_variants lv JOIN colors c ON lv.color_id = c.id SET lv.color_code = c.color_code WHERE lv.color_code IS NULL OR lv.color_code = ''");

// 4. Production Entries
$peCols = $db->query("SHOW COLUMNS FROM production_entries")->fetchAll(PDO::FETCH_COLUMN);
$db->exec("ALTER TABLE production_entries MODIFY COLUMN color_id INT NULL");
$db->exec("ALTER TABLE production_entries MODIFY COLUMN size_id INT NULL");
if (!in_array('shift', $peCols)) {
    $db->exec("ALTER TABLE production_entries ADD COLUMN shift ENUM('Day Shift', 'Night Shift', 'General') DEFAULT 'General' AFTER worker_id");
    echo "[✓] Added shift to production_entries\n";
}

// 5. Dynamic Garment Types Table (Jersey, Coat, Full Zip, Gold Gala, etc.)
$db->exec("CREATE TABLE IF NOT EXISTS garment_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    code VARCHAR(30) NULL,
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
echo "[✓] Verified garment_types table\n";

$gCount = (int)$db->query("SELECT COUNT(*) FROM garment_types")->fetchColumn();
if ($gCount === 0) {
    $defaultGarmentTypes = [
        ['name' => 'جرسی', 'code' => 'JERSEY', 'order' => 1],
        ['name' => 'کوٹ', 'code' => 'COAT', 'order' => 2],
        ['name' => 'فل زپ', 'code' => 'FULL_ZIP', 'order' => 3],
        ['name' => 'ہاف زپ', 'code' => 'HALF_ZIP', 'order' => 4],
        ['name' => 'فل زپ گول گلا', 'code' => 'FULL_ZIP_GG', 'order' => 5],
        ['name' => 'ہاف زپ گول گلا', 'code' => 'HALF_ZIP_GG', 'order' => 6],
        ['name' => 'سینڈو فل زپ', 'code' => 'SANDO_FZ', 'order' => 7],
        ['name' => 'سینڈو ہاف زپ', 'code' => 'SANDO_HZ', 'order' => 8],
        ['name' => 'سینڈو', 'code' => 'SANDO', 'order' => 9],
        ['name' => 'جیکٹ', 'code' => 'JACKET', 'order' => 10],
    ];
    $insG = $db->prepare("INSERT INTO garment_types (name, code, sort_order) VALUES (?, ?, ?)");
    foreach ($defaultGarmentTypes as $gt) {
        $insG->execute([$gt['name'], $gt['code'], $gt['order']]);
    }
    echo "[✓] Seeded default garment types\n";
}

// 6. Designs Table Enhancements (Prototype Sketch, Machine Type, Measurements)
$dCols = $db->query("SHOW COLUMNS FROM designs")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('sketch_image', $dCols)) {
    $db->exec("ALTER TABLE designs ADD COLUMN sketch_image VARCHAR(255) NULL AFTER name");
    echo "[✓] Added sketch_image to designs\n";
}
if (!in_array('machine_type', $dCols)) {
    $db->exec("ALTER TABLE designs ADD COLUMN machine_type VARCHAR(60) DEFAULT 'CMS 430' AFTER sketch_image");
    echo "[✓] Added machine_type to designs\n";
}
if (!in_array('measurements_json', $dCols)) {
    $db->exec("ALTER TABLE designs ADD COLUMN measurements_json JSON NULL AFTER machine_type");
    echo "[✓] Added measurements_json to designs\n";
}

// 7. Product Elements Multipliers & Flags
$peCols = $db->query("SHOW COLUMNS FROM product_elements")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('multiplier', $peCols)) {
    $db->exec("ALTER TABLE product_elements ADD COLUMN multiplier INT NOT NULL DEFAULT 1 AFTER quantity");
    echo "[✓] Added multiplier to product_elements\n";
}
if (!in_array('tracks_at_operator', $peCols)) {
    $db->exec("ALTER TABLE product_elements ADD COLUMN tracks_at_operator TINYINT(1) DEFAULT 1 AFTER multiplier");
    echo "[✓] Added tracks_at_operator to product_elements\n";
}
if (!in_array('default_sam_minutes', $peCols)) {
    $db->exec("ALTER TABLE product_elements ADD COLUMN default_sam_minutes DECIMAL(6,2) DEFAULT 0.00 AFTER tracks_at_operator");
    echo "[✓] Added default_sam_minutes to product_elements\n";
}

// 8. Operator Element Entries Table (Stage 1 without colors)
$db->exec("CREATE TABLE IF NOT EXISTS lot_operator_element_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lot_id INT NOT NULL,
    element_id INT NOT NULL,
    entry_date DATE NOT NULL,
    operator1_id INT NULL,
    operator1_pieces INT DEFAULT 0,
    operator2_id INT NULL,
    operator2_pieces INT DEFAULT 0,
    total_pieces INT GENERATED ALWAYS AS (operator1_pieces + operator2_pieces) STORED,
    required_pieces INT NOT NULL,
    minutes_spent DECIMAL(8,2) DEFAULT 0.00,
    notes VARCHAR(255) NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lot_id) REFERENCES lots(id) ON DELETE CASCADE,
    FOREIGN KEY (element_id) REFERENCES product_elements(id)
)");
echo "[✓] Verified lot_operator_element_entries table\n";

$loeCols = $db->query("SHOW COLUMNS FROM lot_operator_element_entries")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('cut_pieces', $loeCols)) {
    $db->exec("ALTER TABLE lot_operator_element_entries ADD COLUMN cut_pieces INT NOT NULL DEFAULT 0 AFTER total_pieces");
    echo "[✓] Added cut_pieces to lot_operator_element_entries\n";
}

// 9. Guarantee Operator Entry operation exists at sequence 1 (first stage before cutting)
$opCheck = $db->query("SELECT id FROM operations WHERE code = 'OP-OPERATOR' OR name LIKE '%Operator Entry%' LIMIT 1")->fetchColumn();
if (!$opCheck) {
    $deptId = (int)$db->query("SELECT id FROM departments WHERE name LIKE '%Knitt%' OR name LIKE '%Machine%' LIMIT 1")->fetchColumn();
    if (!$deptId) {
        $db->exec("INSERT INTO departments (name, code, description) VALUES ('Machine Knitting', 'DEP-KNIT', 'Flat machine operators and panel knitting')");
        $deptId = (int)$db->lastInsertId();
    }
    $db->prepare("INSERT INTO operations (sequence, name, code, department_id, default_rate, is_active)
                  VALUES (1, 'Operator Entry (مشین آپریٹر / نٹنگ)', 'OP-OPERATOR', ?, 5.00, 1)")
       ->execute([$deptId]);
    echo "[✓] Created Operator Entry operation at sequence 1\n";
} else {
    $db->exec("UPDATE operations SET sequence = 1 WHERE id = $opCheck");
    $db->exec("UPDATE operations SET sequence = 2 WHERE code = 'OP-CUT'");
}

// 10. Departments code nullability & existing code backfill
$db->exec("UPDATE departments SET code = CONCAT('DEP-', id) WHERE code IS NULL OR code = ''");
$db->exec("ALTER TABLE departments MODIFY COLUMN code VARCHAR(50) NULL DEFAULT NULL");
echo "[✓] Departments code made nullable and backfilled\n";

echo "[SUCCESS] Schema migration complete.\n";
