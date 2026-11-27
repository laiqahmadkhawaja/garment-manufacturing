<?php
/**
 * Garment Manufacturing ERP - Factory Master Data & Workflows Seeder
 * Populates exact departments, operations, rates, detail/descriptions, workers, products,
 * elements, and demo lots with sleeves/sleeveless workflows from the Excel specification.
 */
require_once __DIR__ . '/../config/config.php';
$db = get_db();

echo "=================================================================\n";
echo "SEEDING FACTORY MASTER DATA FROM EXCEL SPECIFICATION\n";
echo "=================================================================\n";

$db->exec("SET FOREIGN_KEY_CHECKS = 0");

// 1. Core Lookups: Roles, Units, Colors, Sizes, Product Categories
$roles = [
    [1, 'admin', 'System Administrator', 'Full system access and control'],
    [2, 'production_manager', 'Production Manager', 'Oversees lots, jobs, and cutting'],
    [3, 'supervisor', 'Floor Supervisor', 'Floor level production logging'],
    [4, 'accountant', 'Accountant', 'Manages ledgers, payments and expenses'],
];
$rStmt = $db->prepare("INSERT INTO roles (id, name, display_name, description) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE display_name = VALUES(display_name)");
foreach ($roles as $r) {
    $rStmt->execute($r);
}
echo "[✓] Roles seeded\n";

$units = [
    [1, 'Pieces', 'PCS'],
    [2, 'Dozen', 'DZN'],
    [3, 'Kilogram', 'KG'],
    [4, 'Pound', 'LBS'],
    [5, 'Meters', 'MTR'],
    [6, 'Yards', 'YDS'],
    [7, 'Cones', 'CONE'],
    [8, 'Pala / Panels', 'PALA'],
    [9, 'Minutes', 'MIN'],
];
$uStmt = $db->prepare("INSERT INTO units (name, code) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
foreach ($units as $u) {
    $uStmt->execute([$u[1], $u[2]]);
}
echo "[✓] Units seeded\n";

$sizes = [
    [1, 'Small (S)', 'S', 1],
    [2, 'Medium (M)', 'M', 2],
    [3, 'Large (L)', 'L', 3],
    [4, 'Extra Large (XL)', 'XL', 4],
    [5, 'Free Size (FS)', 'FS', 5],
];
$sStmt = $db->prepare("INSERT INTO sizes (name, code, sort_order) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order)");
foreach ($sizes as $s) {
    $sStmt->execute([$s[1], $s[2], $s[3]]);
}
echo "[✓] Sizes seeded\n";

$colors = [
    [1, 'Black', '#1a1a1a'],
    [2, 'Navy Blue', '#001f3f'],
    [3, 'Charcoal Grey', '#333333'],
    [4, 'Maroon', '#800000'],
    [5, 'Off-White / Beige', '#f5f5dc'],
    [6, 'Olive Green', '#556b2f'],
];
$cStmt = $db->prepare("INSERT INTO colors (name, hex_code) VALUES (?, ?) ON DUPLICATE KEY UPDATE hex_code = VALUES(hex_code)");
foreach ($colors as $c) {
    $cStmt->execute([$c[1], $c[2]]);
}
echo "[✓] Colors seeded\n";

$categories = [
    [1, 'KNIT', 'Knitwear Sweaters', 'Woolen and acrylic knitwear sweaters and coats'],
    [2, 'SHAWL', 'Shawls & Wraps', 'Cut shawls, bazo shawls, and fur shawls'],
    [3, 'TROUSER', 'Trousers & Bottoms', 'Knitted and stitched trousers'],
];
$catStmt = $db->prepare("INSERT INTO product_categories (code, name, description, is_active) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)");
foreach ($categories as $cat) {
    $catStmt->execute([$cat[1], $cat[2], $cat[3]]);
}
echo "[✓] Categories seeded\n";


// 2. Departments from Excel Specification
$departments = [
    ['code' => 'DEP-OP', 'name' => 'Operator', 'desc' => 'Knitting Machine Operators (front, back, sleeves, trims)'],
    ['code' => 'DEP-PPRESS', 'name' => 'Palay Press', 'desc' => 'Knitted panel / palay steaming and iron pressing'],
    ['code' => 'DEP-QC', 'name' => 'Quality Check', 'desc' => 'Raw knitwear piece checking and defect auditing'],
    ['code' => 'DEP-CUT', 'name' => 'Cutting', 'desc' => 'Precision cutting of knitted panels, collars, sleeves'],
    ['code' => 'DEP-TAIL', 'name' => 'Tailor', 'desc' => 'Stitching, tailoring, pockets, gala, labels, and zippers'],
    ['code' => 'DEP-SAFTY', 'name' => 'Safty', 'desc' => 'Safety overlock stitching and panel reinforcement'],
    ['code' => 'DEP-TANKAY', 'name' => 'Tankay', 'desc' => 'Manual and machine tankay attachment'],
    ['code' => 'DEP-KAJ', 'name' => 'Kaj', 'desc' => 'Buttonholes / Kaj creation'],
    ['code' => 'DEP-BTN', 'name' => 'Button', 'desc' => 'Button attachment and fastening'],
    ['code' => 'DEP-ACC', 'name' => 'Accessories', 'desc' => 'Moti, Kadhari, Dori, Fur, and embellishments'],
    ['code' => 'DEP-MPRESS', 'name' => 'Mall Press', 'desc' => 'Finished garment pressing and ironing'],
    ['code' => 'DEP-CHK', 'name' => 'Checking', 'desc' => 'Final quality checking and inspection'],
    ['code' => 'DEP-PACK', 'name' => 'Packing', 'desc' => 'Folding, polybagging, tagging and carton packing'],
    ['code' => 'DEP-PRESS', 'name' => 'PRESS', 'desc' => 'General Press and Finish Department'],
];

$deptMap = [];
$dIns = $db->prepare("INSERT INTO departments (code, name, description, is_active) VALUES (?, ?, ?, 1) 
                      ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)");
foreach ($departments as $d) {
    $dIns->execute([$d['code'], $d['name'], $d['desc']]);
    $dId = (int)$db->query("SELECT id FROM departments WHERE code = '{$d['code']}'")->fetchColumn();
    $deptMap[$d['name']] = $dId;
}
echo "[✓] " . count($departments) . " Departments configured\n";


// 3. Operations from Excel Specification (Code, Name, Detail/Description, Dept, Rate, Rate Unit, Seq, Est Minutes)
$opsData = [
    // Accessories
    ['code' => 'OP-DORI', 'name' => 'Dori-Shawl', 'desc' => 'shawl dori', 'dept' => 'Accessories', 'rate' => 12.00, 'unit' => 'Per piece', 'seq' => 10, 'sam' => 2.0],
    ['code' => 'OP-KADHARI', 'name' => 'Kadhari', 'desc' => 'Kadhari', 'dept' => 'Accessories', 'rate' => 110.00, 'unit' => 'Per piece', 'seq' => 11, 'sam' => 15.0],
    ['code' => 'OP-MOTIFANCY', 'name' => 'MotiFancy', 'desc' => 'Moti Fancy', 'dept' => 'Accessories', 'rate' => 80.00, 'unit' => 'Per piece', 'seq' => 12, 'sam' => 12.0],
    ['code' => 'OP-MOTI', 'name' => 'Moti', 'desc' => 'Moti', 'dept' => 'Accessories', 'rate' => 25.00, 'unit' => 'Per piece', 'seq' => 13, 'sam' => 5.0],

    // Button
    ['code' => 'OP-BTN5', 'name' => 'Button5', 'desc' => 'Button 84 rate', 'dept' => 'Button', 'rate' => 7.00, 'unit' => 'Per piece', 'seq' => 14, 'sam' => 2.0],
    ['code' => 'OP-BTN6', 'name' => 'Button6', 'desc' => 'Button 84 rate', 'dept' => 'Button', 'rate' => 8.33, 'unit' => 'Per piece', 'seq' => 15, 'sam' => 2.2],
    ['code' => 'OP-BTNSHAWL', 'name' => 'ButtonShawl', 'desc' => 'Button Shawl 96', 'dept' => 'Button', 'rate' => 8.00, 'unit' => 'Per piece', 'seq' => 16, 'sam' => 2.0],

    // Cutting
    ['code' => 'OP-KATAI', 'name' => 'Katai', 'desc' => 'Katai', 'dept' => 'Cutting', 'rate' => 12.00, 'unit' => 'Per piece', 'seq' => 17, 'sam' => 3.5],
    ['code' => 'OP-KATAIB', 'name' => 'Katai bazo', 'desc' => 'Katai bazo only', 'dept' => 'Cutting', 'rate' => 3.00, 'unit' => 'Per piece', 'seq' => 18, 'sam' => 1.0],

    // Kaj
    ['code' => 'OP-KAJ', 'name' => 'KAJ', 'desc' => 'KAJ 80 Darjan', 'dept' => 'Kaj', 'rate' => 6.67, 'unit' => 'Per piece', 'seq' => 19, 'sam' => 1.8],

    // Packing
    ['code' => 'OP-P', 'name' => 'P', 'desc' => 'Packing Only', 'dept' => 'Packing', 'rate' => 5.00, 'unit' => 'Per piece', 'seq' => 20, 'sam' => 1.5],
    ['code' => 'OP-PC', 'name' => 'P+C', 'desc' => 'Packing and Checking', 'dept' => 'Packing', 'rate' => 8.00, 'unit' => 'Per piece', 'seq' => 21, 'sam' => 2.5],

    // Press
    ['code' => 'OP-PPRESS', 'name' => 'PALAY Press', 'desc' => 'PALAY Press', 'dept' => 'Palay Press', 'rate' => 10.00, 'unit' => 'Per pala', 'seq' => 22, 'sam' => 2.0],
    ['code' => 'OP-MPRESS', 'name' => 'MallPress', 'desc' => 'Mall Press', 'dept' => 'Mall Press', 'rate' => 15.00, 'unit' => 'Per piece', 'seq' => 23, 'sam' => 3.0],

    // Quality Check
    ['code' => 'OP-MURAMTIYA', 'name' => 'Muramtiya', 'desc' => 'Mall Muramt', 'dept' => 'Quality Check', 'rate' => 8.00, 'unit' => 'Per piece', 'seq' => 24, 'sam' => 2.0],
    ['code' => 'OP-QC', 'name' => 'QC', 'desc' => 'Quality Check piece audit', 'dept' => 'Quality Check', 'rate' => 8.00, 'unit' => 'Per piece', 'seq' => 25, 'sam' => 2.0],

    // Safty
    ['code' => 'OP-SAFTY', 'name' => 'Safty', 'desc' => 'Safty', 'dept' => 'Safty', 'rate' => 10.00, 'unit' => 'Per piece', 'seq' => 26, 'sam' => 2.5],

    // Tailor
    ['code' => 'OP-LBL', 'name' => 'Label', 'desc' => 'Cut Shawl Label , Label', 'dept' => 'Tailor', 'rate' => 3.00, 'unit' => 'Per piece', 'seq' => 27, 'sam' => 1.0],
    ['code' => 'OP-LM', 'name' => 'LM', 'desc' => 'Link Moda Thoka Label', 'dept' => 'Tailor', 'rate' => 9.00, 'unit' => 'Per piece', 'seq' => 28, 'sam' => 2.5],
    ['code' => 'OP-KL', 'name' => 'KL', 'desc' => 'Kiyari Label', 'dept' => 'Tailor', 'rate' => 12.00, 'unit' => 'Per piece', 'seq' => 29, 'sam' => 3.0],
    ['code' => 'OP-CSL', 'name' => 'CSL', 'desc' => 'Cut Shawl Label', 'dept' => 'Tailor', 'rate' => 14.00, 'unit' => 'Per piece', 'seq' => 30, 'sam' => 3.5],
    ['code' => 'OP-MODAY', 'name' => 'moday', 'desc' => 'Moday', 'dept' => 'Tailor', 'rate' => 15.00, 'unit' => 'Per piece', 'seq' => 31, 'sam' => 4.0],
    ['code' => 'OP-SSL', 'name' => 'SSL', 'desc' => 'Shawl Salai Label', 'dept' => 'Tailor', 'rate' => 16.00, 'unit' => 'Per piece', 'seq' => 32, 'sam' => 4.0],
    ['code' => 'OP-OP', 'name' => 'OP', 'desc' => 'Open Pocket', 'dept' => 'Tailor', 'rate' => 16.00, 'unit' => 'Per piece', 'seq' => 33, 'sam' => 4.0],
    ['code' => 'OP-KLS', 'name' => 'KLS', 'desc' => 'Kiyari Label - thokay wali', 'dept' => 'Tailor', 'rate' => 20.00, 'unit' => 'Per piece', 'seq' => 34, 'sam' => 5.0],
    ['code' => 'OP-OPD', 'name' => 'OPD', 'desc' => 'Open Pocket Design', 'dept' => 'Tailor', 'rate' => 20.00, 'unit' => 'Per piece', 'seq' => 35, 'sam' => 5.0],
    ['code' => 'OP-GLADIES', 'name' => 'GolgalaLadies', 'desc' => 'Gol Gala Ladies', 'dept' => 'Tailor', 'rate' => 21.00, 'unit' => 'Per piece', 'seq' => 36, 'sam' => 5.0],
    ['code' => 'OP-SULTANI', 'name' => 'Sultani', 'desc' => 'Open Pocket + Label + Thoka', 'dept' => 'Tailor', 'rate' => 23.00, 'unit' => 'Per piece', 'seq' => 37, 'sam' => 6.0],
    ['code' => 'OP-OPS', 'name' => 'OPS', 'desc' => 'Open Pocket Design Single', 'dept' => 'Tailor', 'rate' => 23.00, 'unit' => 'Per piece', 'seq' => 38, 'sam' => 6.0],
    ['code' => 'OP-GOLPCK', 'name' => 'GolPocket', 'desc' => 'Gol Pocket', 'dept' => 'Tailor', 'rate' => 23.00, 'unit' => 'Per piece', 'seq' => 39, 'sam' => 6.0],
    ['code' => 'OP-THELAPCK', 'name' => 'ThelaPocket', 'desc' => 'Thela Pocket', 'dept' => 'Tailor', 'rate' => 24.00, 'unit' => 'Per piece', 'seq' => 40, 'sam' => 6.0],
    ['code' => 'OP-GML', 'name' => 'GML', 'desc' => 'Gol Moda Label', 'dept' => 'Tailor', 'rate' => 25.00, 'unit' => 'Per piece', 'seq' => 41, 'sam' => 6.5],
    ['code' => 'OP-EGG', 'name' => 'EGG', 'desc' => 'Golgala Export', 'dept' => 'Tailor', 'rate' => 35.00, 'unit' => 'Per piece', 'seq' => 42, 'sam' => 9.0],
    ['code' => 'OP-COATIOP', 'name' => 'CoatiOP', 'desc' => 'Coati Open Pocket', 'dept' => 'Tailor', 'rate' => 38.00, 'unit' => 'Per piece', 'seq' => 43, 'sam' => 9.5],
    ['code' => 'OP-COATIOPD', 'name' => 'CoatiOPD', 'desc' => 'Coati Open Pocket Design', 'dept' => 'Tailor', 'rate' => 42.00, 'unit' => 'Per piece', 'seq' => 44, 'sam' => 10.5],
    ['code' => 'OP-COATIGOLP', 'name' => 'CoatiGolP', 'desc' => 'Coati Gol Pocket Design', 'dept' => 'Tailor', 'rate' => 42.00, 'unit' => 'Per piece', 'seq' => 45, 'sam' => 10.5],
    ['code' => 'OP-COATI', 'name' => 'coati', 'desc' => 'Pocket Jati Slik Gala V + Gol + Label', 'dept' => 'Tailor', 'rate' => 45.00, 'unit' => 'Per piece', 'seq' => 46, 'sam' => 11.0],
    ['code' => 'OP-ALINE', 'name' => 'Aline', 'desc' => 'Aline Kanda Pocket Label Salai', 'dept' => 'Tailor', 'rate' => 50.00, 'unit' => 'Per piece', 'seq' => 47, 'sam' => 12.5],
    ['code' => 'OP-FURSHAWL', 'name' => 'FurShawl', 'desc' => 'Shawl fur', 'dept' => 'Tailor', 'rate' => 60.00, 'unit' => 'Per piece', 'seq' => 48, 'sam' => 15.0],
    ['code' => 'OP-FULZIP', 'name' => 'Fulzip', 'desc' => 'Pocket Gents Zip Golgala Label Tanka', 'dept' => 'Tailor', 'rate' => 65.00, 'unit' => 'Per piece', 'seq' => 49, 'sam' => 16.0],
    ['code' => 'OP-SHAWLMURAM', 'name' => 'ShawlMuramay', 'desc' => 'Shawl Muramt', 'dept' => 'Tailor', 'rate' => 10.00, 'unit' => 'Per piece', 'seq' => 50, 'sam' => 2.5],
    ['code' => 'OP-GERSI', 'name' => 'gersi', 'desc' => 'Mithu Gersi', 'dept' => 'Tailor', 'rate' => 16.00, 'unit' => 'Per piece', 'seq' => 51, 'sam' => 4.0],
    ['code' => 'OP-SENDO', 'name' => 'sendo', 'desc' => 'Mithu Sendo', 'dept' => 'Tailor', 'rate' => 32.00, 'unit' => 'Per piece', 'seq' => 52, 'sam' => 8.0],
    ['code' => 'OP-FLATLOCK', 'name' => 'Flatlock', 'desc' => 'front tape flat lock', 'dept' => 'Tailor', 'rate' => 10.00, 'unit' => 'Per piece', 'seq' => 53, 'sam' => 2.5],
    ['code' => 'OP-VASTER', 'name' => 'VAster', 'desc' => 'V- Aster Back', 'dept' => 'Tailor', 'rate' => 25.00, 'unit' => 'Per piece', 'seq' => 54, 'sam' => 6.0],
    ['code' => 'OP-FULZIPGERSI', 'name' => 'FulzipGersi', 'desc' => 'without aster fulzip bazo', 'dept' => 'Tailor', 'rate' => 120.00, 'unit' => 'Per piece', 'seq' => 55, 'sam' => 30.0],
    ['code' => 'OP-FULZIPSENDO', 'name' => 'FulzipSendo', 'desc' => 'without aster fulzip sendo', 'dept' => 'Tailor', 'rate' => 150.00, 'unit' => 'Per piece', 'seq' => 56, 'sam' => 35.0],
    ['code' => 'OP-ASTERG', 'name' => 'AsterGersi', 'desc' => 'aster fulzip bazo', 'dept' => 'Tailor', 'rate' => 220.00, 'unit' => 'Per piece', 'seq' => 57, 'sam' => 50.0],
    ['code' => 'OP-ASTERS', 'name' => 'AsterSendo', 'desc' => 'aster fulzip sendo', 'dept' => 'Tailor', 'rate' => 250.00, 'unit' => 'Per piece', 'seq' => 58, 'sam' => 60.0],
    ['code' => 'OP-SHIRT', 'name' => 'Shirt', 'desc' => 'Round Neck + Side Salai', 'dept' => 'Tailor', 'rate' => 43.00, 'unit' => 'Per piece', 'seq' => 59, 'sam' => 10.0],
    ['code' => 'OP-KALAR', 'name' => 'Kalar', 'desc' => 'Fur wala Kalar', 'dept' => 'Tailor', 'rate' => 60.00, 'unit' => 'Per piece', 'seq' => 60, 'sam' => 15.0],
    ['code' => 'OP-FPOCKET', 'name' => 'Fpocket', 'desc' => 'Fur wali pocket', 'dept' => 'Tailor', 'rate' => 30.00, 'unit' => 'Per piece', 'seq' => 61, 'sam' => 7.5],
    ['code' => 'OP-KALARPCK', 'name' => 'KalarPocket', 'desc' => 'Fur Kalar & Pocket', 'dept' => 'Tailor', 'rate' => 90.00, 'unit' => 'Per piece', 'seq' => 62, 'sam' => 22.5],
    ['code' => 'OP-TROUSER2', 'name' => 'Trouser2', 'desc' => 'Trouser variant 38', 'dept' => 'Tailor', 'rate' => 38.00, 'unit' => 'Per piece', 'seq' => 63, 'sam' => 9.5],
    ['code' => 'OP-TROUSER1', 'name' => 'Trouser1', 'desc' => 'Lastic + Blet + Asan', 'dept' => 'Tailor', 'rate' => 33.00, 'unit' => 'Per piece', 'seq' => 64, 'sam' => 8.0],
    ['code' => 'OP-TROUSER', 'name' => 'Trouser', 'desc' => 'Lastic + Blet (28) + Side Salai (22) + Asan 5', 'dept' => 'Tailor', 'rate' => 55.00, 'unit' => 'Per piece', 'seq' => 65, 'sam' => 14.0],
    ['code' => 'OP-VGENTS', 'name' => 'VGents', 'desc' => 'V Gents Neck', 'dept' => 'Tailor', 'rate' => 1.00, 'unit' => 'Per piece', 'seq' => 66, 'sam' => 0.5],
    ['code' => 'OP-MODAYGENTS', 'name' => 'ModayGents', 'desc' => 'Moday Gents', 'dept' => 'Tailor', 'rate' => 2.00, 'unit' => 'Per piece', 'seq' => 67, 'sam' => 1.0],

    // Tankay
    ['code' => 'OP-TANKAY', 'name' => 'Tankay', 'desc' => 'Tankay 60 darjan', 'dept' => 'Tankay', 'rate' => 5.00, 'unit' => 'Per piece', 'seq' => 68, 'sam' => 1.5],

    // Operator Stage
    ['code' => 'OP-OPERATOR', 'name' => 'Operator Knitting', 'desc' => 'Knitting panels (Front, Back, Bazo, V-Tape)', 'dept' => 'Operator', 'rate' => 2.50, 'unit' => 'Per minute', 'seq' => 1, 'sam' => 10.0],
    ['code' => 'OP-CHECKING', 'name' => 'Checking', 'desc' => 'Final Checking', 'dept' => 'Checking', 'rate' => 5.00, 'unit' => 'Per piece', 'seq' => 70, 'sam' => 1.5],
];

$opMap = [];
$opIns = $db->prepare("INSERT INTO operations (code, name, description, department_id, sequence, estimated_minutes, is_mandatory, default_rate, rate_unit, is_active)
                       VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, 1)
                       ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), department_id = VALUES(department_id),
                                               sequence = VALUES(sequence), estimated_minutes = VALUES(estimated_minutes),
                                               default_rate = VALUES(default_rate), rate_unit = VALUES(rate_unit), is_active = 1");
foreach ($opsData as $o) {
    $deptId = $deptMap[$o['dept']] ?? $deptMap['Operator'];
    $opIns->execute([$o['code'], $o['name'], $o['desc'], $deptId, $o['seq'], $o['sam'], $o['rate'], $o['unit']]);
    $oId = (int)$db->query("SELECT id FROM operations WHERE code = '{$o['code']}'")->fetchColumn();
    $opMap[$o['name']] = $oId;
    $opMap[$o['code']] = $oId;
}
echo "[✓] " . count($opsData) . " Operations configured with detail/description and rates\n";


// 4. Workers from Excel
$workersData = [
    ['code' => 'WRK-ADNAN', 'name' => 'Adnan', 'phone' => '0301-1111001', 'dept' => 'Operator', 'type' => 'Minute Rate', 'rate_basis' => 'element_wise', 'min_rate' => 2.50, 'rate' => 0.00],
    ['code' => 'WRK-MOLVI', 'name' => 'Molvi', 'phone' => '0301-1111002', 'dept' => 'Operator', 'type' => 'Minute Rate', 'rate_basis' => 'element_wise', 'min_rate' => 2.50, 'rate' => 0.00],
    ['code' => 'WRK-HASHIM', 'name' => 'Hashim', 'phone' => '0301-1111003', 'dept' => 'Operator', 'type' => 'Minute Rate', 'rate_basis' => 'element_wise', 'min_rate' => 2.50, 'rate' => 0.00],
    ['code' => 'WRK-LALA', 'name' => 'LALA', 'phone' => '0301-1111004', 'dept' => 'Palay Press', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 10.00],
    ['code' => 'WRK-UMAIR', 'name' => 'Umair', 'phone' => '0301-1111005', 'dept' => 'Quality Check', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 8.00],
    ['code' => 'WRK-ALIRAZA', 'name' => 'Ali Raza', 'phone' => '0301-1111006', 'dept' => 'Cutting', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 12.00],
    ['code' => 'WRK-ASAD', 'name' => 'Asad', 'phone' => '0301-1111007', 'dept' => 'Tailor', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 16.00],
    ['code' => 'WRK-IRFAN', 'name' => 'Irfan', 'phone' => '0301-1111008', 'dept' => 'Tailor', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 15.00],
    ['code' => 'WRK-MITHU', 'name' => 'Mithu', 'phone' => '0301-1111009', 'dept' => 'Tailor', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 16.00],
    ['code' => 'WRK-BILAL', 'name' => 'Bilal', 'phone' => '0301-1111010', 'dept' => 'Safty', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 10.00],
    ['code' => 'WRK-LATIF', 'name' => 'Latif', 'phone' => '0301-1111011', 'dept' => 'Tankay', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 5.00],
    ['code' => 'WRK-HUMZA', 'name' => 'Humza', 'phone' => '0301-1111012', 'dept' => 'Button', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 7.00],
    ['code' => 'WRK-LALAMP', 'name' => 'Lala (Mall Press)', 'phone' => '0301-1111013', 'dept' => 'Mall Press', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 15.00],
    ['code' => 'WRK-OSAMA', 'name' => 'Osama', 'phone' => '0301-1111014', 'dept' => 'Packing', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 5.00],
    ['code' => 'WRK-ALI', 'name' => 'Ali', 'phone' => '0301-1111015', 'dept' => 'Accessories', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 25.00],
    ['code' => 'WRK-BUTT', 'name' => 'Butt', 'phone' => '0301-1111016', 'dept' => 'Accessories', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 110.00],
    ['code' => 'WRK-SALEEM', 'name' => 'Saleem', 'phone' => '0301-1111017', 'dept' => 'Accessories', 'type' => 'Piece Rate', 'rate_basis' => 'whole_sweater', 'min_rate' => 2.50, 'rate' => 12.00],
];

$workerMap = [];
$wIns = $db->prepare("INSERT INTO workers (code, name, phone, department_id, worker_type, rate_basis, rate_per_minute, default_rate, is_active, joining_date, opening_balance)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, CURDATE(), 0.00)
                      ON DUPLICATE KEY UPDATE name = VALUES(name), department_id = VALUES(department_id), worker_type = VALUES(worker_type),
                                              rate_basis = VALUES(rate_basis), rate_per_minute = VALUES(rate_per_minute), default_rate = VALUES(default_rate)");
foreach ($workersData as $w) {
    $dId = $deptMap[$w['dept']] ?? $deptMap['Operator'];
    $wIns->execute([$w['code'], $w['name'], $w['phone'], $dId, $w['type'], $w['rate_basis'], $w['min_rate'], $w['rate']]);
    $wId = (int)$db->query("SELECT id FROM workers WHERE code = '{$w['code']}'")->fetchColumn();
    $workerMap[$w['name']] = $wId;
}
echo "[✓] " . count($workersData) . " Artisans / Workers configured\n";

// Map workers to operations
$wOpIns = $db->prepare("INSERT IGNORE INTO worker_operations (worker_id, operation_id) VALUES (?, ?)");
// Operators
foreach (['Adnan', 'Molvi', 'Hashim'] as $wName) {
    if (isset($workerMap[$wName], $opMap['Operator Knitting'])) {
        $wOpIns->execute([$workerMap[$wName], $opMap['Operator Knitting']]);
    }
}
// Palay Press
if (isset($workerMap['LALA'], $opMap['PALAY Press'])) {
    $wOpIns->execute([$workerMap['LALA'], $opMap['PALAY Press']]);
}
// QC & Checking
if (isset($workerMap['Umair'], $opMap['QC'])) {
    $wOpIns->execute([$workerMap['Umair'], $opMap['QC']]);
}
if (isset($workerMap['Osama'], $opMap['Checking'])) {
    $wOpIns->execute([$workerMap['Osama'], $opMap['Checking']]);
}
// Cutting
if (isset($workerMap['Ali Raza'], $opMap['Katai'])) {
    $wOpIns->execute([$workerMap['Ali Raza'], $opMap['Katai']]);
    $wOpIns->execute([$workerMap['Ali Raza'], $opMap['Katai bazo']]);
}
// Tailors
foreach (['Asad', 'Irfan', 'Mithu'] as $wName) {
    if (isset($workerMap[$wName])) {
        foreach (['Label', 'LM', 'KL', 'CSL', 'moday', 'SSL', 'OP', 'KLS', 'OPD', 'coati', 'Fulzip', 'gersi', 'sendo', 'VGents', 'ModayGents'] as $opName) {
            if (isset($opMap[$opName])) {
                $wOpIns->execute([$workerMap[$wName], $opMap[$opName]]);
            }
        }
    }
}
// Safty
if (isset($workerMap['Bilal'], $opMap['Safty'])) {
    $wOpIns->execute([$workerMap['Bilal'], $opMap['Safty']]);
}
// Tankay & Kaj
if (isset($workerMap['Latif'])) {
    if (isset($opMap['Tankay'])) $wOpIns->execute([$workerMap['Latif'], $opMap['Tankay']]);
    if (isset($opMap['KAJ'])) $wOpIns->execute([$workerMap['Latif'], $opMap['KAJ']]);
}
// Button
if (isset($workerMap['Humza'])) {
    foreach (['Button5', 'Button6', 'ButtonShawl'] as $bOp) {
        if (isset($opMap[$bOp])) $wOpIns->execute([$workerMap['Humza'], $opMap[$bOp]]);
    }
}
// Mall Press
if (isset($workerMap['Lala (Mall Press)'], $opMap['MallPress'])) {
    $wOpIns->execute([$workerMap['Lala (Mall Press)'], $opMap['MallPress']]);
}
// Packing
if (isset($workerMap['Osama'], $opMap['P'])) {
    $wOpIns->execute([$workerMap['Osama'], $opMap['P']]);
    $wOpIns->execute([$workerMap['Osama'], $opMap['P+C']]);
}
// Accessories
if (isset($workerMap['Ali'], $opMap['Moti'])) $wOpIns->execute([$workerMap['Ali'], $opMap['Moti']]);
if (isset($workerMap['Butt'], $opMap['Kadhari'])) $wOpIns->execute([$workerMap['Butt'], $opMap['Kadhari']]);
if (isset($workerMap['Saleem'], $opMap['Dori-Shawl'])) $wOpIns->execute([$workerMap['Saleem'], $opMap['Dori-Shawl']]);
echo "[✓] Worker-to-Operation assignments established\n";


// 5. Products from Excel Specification
$productsData = [
    [
        'code' => 'PROD-GERSI',
        'name' => 'Gersi Gents',
        'cat' => 1,
        'type' => 'Sweater',
        'desc' => 'Gents knitted gersi sweater, available with sleeves and sleeveless (sendo) variants',
        'elements' => [
            ['name' => 'Front Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.0],
            ['name' => 'Back Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.0],
            ['name' => 'Bazo / Sleeves', 'qty' => 2, 'mult' => 2, 'sam' => 4.0],
            ['name' => 'VTape / V-Gala', 'qty' => 1, 'mult' => 1, 'sam' => 1.0],
            ['name' => 'Moday Tape (Sleeveless)', 'qty' => 1, 'mult' => 1, 'sam' => 1.0],
        ]
    ],
    [
        'code' => 'PROD-COATI',
        'name' => 'Coati Ladies',
        'cat' => 1,
        'type' => 'Cardigan / Coat',
        'desc' => 'Ladies coati open pocket with sleeves and sleeveless variants',
        'elements' => [
            ['name' => 'Front Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.5],
            ['name' => 'Back Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.5],
            ['name' => 'Bazo / Sleeves', 'qty' => 2, 'mult' => 2, 'sam' => 4.0],
            ['name' => 'Pocket', 'qty' => 2, 'mult' => 2, 'sam' => 1.5],
            ['name' => 'Round Neck / Gol Gala', 'qty' => 1, 'mult' => 1, 'sam' => 1.5],
            ['name' => 'Moday Tape', 'qty' => 1, 'mult' => 1, 'sam' => 1.0],
        ]
    ],
    [
        'code' => 'PROD-FULZIP',
        'name' => 'Gent Full Zip',
        'cat' => 1,
        'type' => 'Zipper Jacket',
        'desc' => 'Gents Full Zip Sweater with pocket and golgala',
        'elements' => [
            ['name' => 'Front Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.5],
            ['name' => 'Back Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.5],
            ['name' => 'Bazo / Sleeves', 'qty' => 2, 'mult' => 2, 'sam' => 4.0],
            ['name' => 'Bon / Pocket', 'qty' => 2, 'mult' => 2, 'sam' => 2.0],
            ['name' => 'Round Neck / Gol Gala', 'qty' => 1, 'mult' => 1, 'sam' => 1.5],
        ]
    ],
    [
        'code' => 'PROD-SHAWLB',
        'name' => 'Shawl Sleeves',
        'cat' => 2,
        'type' => 'Shawl',
        'desc' => 'Knitted Shawl with sleeves (Shawl Bazo) or sleeveless (Shawl Sendo)',
        'elements' => [
            ['name' => 'Shawl Panel', 'qty' => 1, 'mult' => 1, 'sam' => 4.0],
            ['name' => 'Bazo / Sleeves', 'qty' => 2, 'mult' => 2, 'sam' => 4.0],
            ['name' => 'Moday Tape', 'qty' => 1, 'mult' => 1, 'sam' => 1.0],
        ]
    ],
    [
        'code' => 'PROD-CUTSHAWL',
        'name' => 'Cut Shawl LK',
        'cat' => 2,
        'type' => 'Shawl',
        'desc' => 'Cut Shawl with ShawlA, ShawlB and Bazo panels',
        'elements' => [
            ['name' => 'Shawl A Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.0],
            ['name' => 'Shawl B Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.0],
            ['name' => 'Bazo / Sleeves', 'qty' => 2, 'mult' => 2, 'sam' => 4.0],
        ]
    ],
    [
        'code' => 'PROD-8GMAMA',
        'name' => '8g Mama Shape',
        'cat' => 1,
        'type' => 'Sweater',
        'desc' => '8g Mama Shape Sweater with Front A/B, Back and Bazo',
        'elements' => [
            ['name' => 'Front A Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.0],
            ['name' => 'Front B Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.0],
            ['name' => 'Back Panel', 'qty' => 1, 'mult' => 1, 'sam' => 3.0],
            ['name' => 'Bazo / Sleeves', 'qty' => 2, 'mult' => 2, 'sam' => 4.0],
        ]
    ],
];

$productMap = [];
$pIns = $db->prepare("INSERT INTO products (code, name, category_id, product_type, description, is_active)
                      VALUES (?, ?, ?, ?, ?, 1)
                      ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), category_id = VALUES(category_id)");
$peDel = $db->prepare("DELETE FROM product_elements WHERE product_id = ?");
$peIns = $db->prepare("INSERT INTO product_elements (product_id, element_name, quantity, multiplier, default_sam_minutes, tracks_at_operator, sort_order)
                       VALUES (?, ?, ?, ?, ?, 1, ?)");

foreach ($productsData as $p) {
    $pIns->execute([$p['code'], $p['name'], $p['cat'], $p['type'], $p['desc']]);
    $prodId = (int)$db->query("SELECT id FROM products WHERE code = '{$p['code']}'")->fetchColumn();
    $productMap[$p['code']] = $prodId;

    // Elements
    $peDel->execute([$prodId]);
    $order = 10;
    foreach ($p['elements'] as $elem) {
        $peIns->execute([$prodId, $elem['name'], $elem['qty'], $elem['mult'], $elem['sam'], $order]);
        $order += 10;
    }
}
echo "[✓] " . count($productsData) . " Products and anatomical elements configured\n";


// 6. Workflow Templates and Steps for Products
$tplIns = $db->prepare("INSERT INTO workflow_templates (name, product_type, description, is_active) 
                        VALUES (?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE description = VALUES(description)");
$tplStepIns = $db->prepare("INSERT INTO workflow_template_steps (template_id, operation_id, sequence, is_mandatory) VALUES (?, ?, ?, 1)");

$templates = [
    'Gersi Gents Standard Workflow' => [
        'type' => 'Sweater',
        'ops' => ['OP-OPERATOR', 'OP-PPRESS', 'OP-QC', 'OP-KATAI', 'OP-VGENTS', 'OP-MODAY', 'OP-SAFTY', 'OP-TANKAY', 'OP-MPRESS', 'OP-CHECKING', 'OP-P']
    ],
    'Coati Ladies Workflow' => [
        'type' => 'Cardigan / Coat',
        'ops' => ['OP-OPERATOR', 'OP-PPRESS', 'OP-QC', 'OP-KATAI', 'OP-COATIOP', 'OP-MODAY', 'OP-SAFTY', 'OP-TANKAY', 'OP-KAJ', 'OP-BTN5', 'OP-MPRESS', 'OP-CHECKING', 'OP-P']
    ],
    'Gent Fulzip Workflow' => [
        'type' => 'Zipper Jacket',
        'ops' => ['OP-OPERATOR', 'OP-PPRESS', 'OP-QC', 'OP-KATAI', 'OP-OP', 'OP-FULZIP', 'OP-GLADIES', 'OP-LBL', 'OP-SAFTY', 'OP-TANKAY', 'OP-BTN5', 'OP-MPRESS', 'OP-CHECKING', 'OP-P']
    ],
    'Shawl Bazo Workflow' => [
        'type' => 'Shawl',
        'ops' => ['OP-OPERATOR', 'OP-PPRESS', 'OP-QC', 'OP-KATAI', 'OP-SSL', 'OP-MODAY', 'OP-LBL', 'OP-SAFTY', 'OP-TANKAY', 'OP-DORI', 'OP-MPRESS', 'OP-CHECKING', 'OP-P']
    ],
];

foreach ($templates as $tplName => $tData) {
    $db->prepare("DELETE FROM workflow_templates WHERE name = ?")->execute([$tplName]);
    $tplIns->execute([$tplName, $tData['type'], "Standard workflow for $tplName"]);
    $tplId = (int)$db->lastInsertId();

    $seq = 1;
    foreach ($tData['ops'] as $opCode) {
        if (isset($opMap[$opCode])) {
            $tplStepIns->execute([$tplId, $opMap[$opCode], $seq++]);
        }
    }
}
echo "[✓] " . count($templates) . " Workflow templates constructed\n";


// 7. Seed Demo Lots with the 12/12 Reference (With Sleeves vs Sleeveless)
echo "\n--- CREATING DEMO LOTS WITH SLEEVE & SLEEVELESS BREAKDOWNS ---\n";

// Demo Lot 1: Gersi Gents (24 pieces = 12 with sleeves + 12 without sleeves / sendo)
$gersiProdId = $productMap['PROD-GERSI'];
$db->prepare("DELETE FROM lots WHERE lot_number = 'LOT-2026-001'")->execute();
$lotIns = $db->prepare("INSERT INTO lots (lot_number, lot_date, product_id, total_pieces, total_colors, total_sizes, size_mode, single_size_id, shift_type, supervisor_name, machine_name, operator_id, night_operator_id, has_anatomy_variants, anatomy_breakdown, status, created_by)
                        VALUES (?, CURDATE(), ?, 24, 2, 1, 'Single Size', 2, 'Double Shift', 'System Administrator', 'Shima Seiki 1', ?, ?, 1, ?, 'In Production', 1)");

$gersiAnatomy = json_encode([
    'with_sleeves' => 12,
    'sleeveless' => 12,
    'notes' => '12pc with sleeves (4 palay each = 48) + 12pc without sleeves (2 palay each = 24). Total 72 palay.'
]);

$lotIns->execute(['LOT-2026-001', $gersiProdId, $workerMap['Adnan'], $workerMap['Molvi'], $gersiAnatomy]);
$lotId1 = (int)$db->lastInsertId();

// Lot variants (12 Black, 12 Navy Blue)
$lvIns = $db->prepare("INSERT INTO lot_variants (lot_id, color_id, size_id, quantity) VALUES (?, ?, ?, ?)");
$lvIns->execute([$lotId1, 1, 2, 12]); // Black Medium: 12
$lvIns->execute([$lotId1, 2, 2, 12]); // Navy Blue Medium: 12

// Attach workflow steps to lot
$lwsIns = $db->prepare("INSERT INTO lot_workflow_steps (lot_id, operation_id, sequence, is_mandatory, status) VALUES (?, ?, ?, 1, 'Pending')");
$lSeq = 1;
foreach (['OP-OPERATOR', 'OP-PPRESS', 'OP-QC', 'OP-KATAI', 'OP-VGENTS', 'OP-MODAY', 'OP-SAFTY', 'OP-TANKAY', 'OP-MPRESS', 'OP-CHECKING', 'OP-P'] as $opCode) {
    if (isset($opMap[$opCode])) {
        $lwsIns->execute([$lotId1, $opMap[$opCode], $lSeq++]);
    }
}

// Generate Realistic Production Entries for Lot 1:
// 1. Operator Entry (Adnan & Molvi)
$peIns = $db->prepare("INSERT INTO production_entries 
    (entry_number, entry_date, lot_id, operation_id, worker_id, shift, color_id, size_id, qty_received, good_qty, rate, minutes_per_piece, worker_minute_rate, total_minutes, labour_amount, notes, created_by)
    VALUES (?, CURDATE(), ?, ?, ?, ?, ?, 2, ?, ?, ?, ?, 2.50, ?, ?, ?, 1)");

// Day Operator Adnan: 12 pcs front, back, bazo, vtape
$peIns->execute(['PE-2026-001', $lotId1, $opMap['OP-OPERATOR'], $workerMap['Adnan'], 'Day Shift', 1, 12, 12, 12.00, 4.8, 57.6, 144.00, 'Adnan Day: 12 with sleeves (front 12, back 12, bazo 24, vtape 12)']);
// Night Operator Molvi: 12 pcs front, back, vtape, moday tape
$peIns->execute(['PE-2026-002', $lotId1, $opMap['OP-OPERATOR'], $workerMap['Molvi'], 'Night Shift', 2, 12, 12, 10.00, 4.0, 48.0, 120.00, 'Molvi Night: 12 without sleeves (front 12, back 12, moday tape 12)']);

// 2. Palay Press Entry: LALA (Rate unit: Per pala @ Rs. 10.00)
// Total Palay = 12 with sleeves * 4 palay (48) + 12 sleeveless * 2 palay (24) = 72 Palay!
// Rate: Rs. 10.00 / pala -> Labour Amount: 72 * 10 = Rs. 720.00!
$peIns->execute(['PE-2026-003', $lotId1, $opMap['OP-PPRESS'], $workerMap['LALA'], 'General', 1, 24, 24, 10.00, 2.0, 48.0, 720.00, 'LALA: 12+12+24 (48 palay sleeves) + 12+12 (24 palay sendo) = 72 palay press @ Rs. 10/pala']);

// 3. Quality Check: Umair (24 pcs @ Rs. 8.00 = Rs. 192.00)
$peIns->execute(['PE-2026-004', $lotId1, $opMap['OP-QC'], $workerMap['Umair'], 'General', 1, 24, 24, 8.00, 2.0, 48.0, 192.00, 'Umair: Initial QC 24 pcs passed']);

// 4. Cutting: Ali Raza (24 pcs @ Rs. 12.00 = Rs. 288.00)
$peIns->execute(['PE-2026-005', $lotId1, $opMap['OP-KATAI'], $workerMap['Ali Raza'], 'General', 1, 24, 24, 12.00, 3.5, 84.0, 288.00, 'Ali Raza: Katai 24 pcs panels']);

// 5. Tailoring: Asad (V-Neck: 24 pcs @ Rs. 1.00 = Rs. 24.00)
$peIns->execute(['PE-2026-006', $lotId1, $opMap['OP-VGENTS'], $workerMap['Asad'], 'General', 1, 24, 24, 1.00, 0.5, 12.0, 24.00, 'Asad: V-Gala tailoring']);

// 6. Tailoring: Irfan (Moday for 12 sleeveless pcs @ Rs. 15.00 = Rs. 180.00)
$peIns->execute(['PE-2026-007', $lotId1, $opMap['OP-MODAY'], $workerMap['Irfan'], 'General', 2, 12, 12, 15.00, 4.0, 48.0, 180.00, 'Irfan: Moday tailoring 12 sleeveless pcs']);

// 7. Safety: Bilal (24 pcs @ Rs. 10.00 = Rs. 240.00)
$peIns->execute(['PE-2026-008', $lotId1, $opMap['OP-SAFTY'], $workerMap['Bilal'], 'General', 1, 24, 24, 10.00, 2.5, 60.0, 240.00, 'Bilal: Safety overlock on 24 pcs']);

// 8. Tankay: Latif (24 pcs @ Rs. 5.00 = Rs. 120.00)
$peIns->execute(['PE-2026-009', $lotId1, $opMap['OP-TANKAY'], $workerMap['Latif'], 'General', 1, 24, 24, 5.00, 1.5, 36.0, 120.00, 'Latif: Tankay 24 pcs']);

// 9. Mall Press: Lala (24 pcs @ Rs. 15.00 = Rs. 360.00)
$peIns->execute(['PE-2026-010', $lotId1, $opMap['OP-MPRESS'], $workerMap['Lala (Mall Press)'], 'General', 1, 24, 24, 15.00, 3.0, 72.0, 360.00, 'Lala: Finished garment Mall Press 24 pcs']);

// 10. Checking: Osama (24 pcs @ Rs. 5.00 = Rs. 120.00)
$peIns->execute(['PE-2026-011', $lotId1, $opMap['OP-CHECKING'], $workerMap['Osama'], 'General', 1, 24, 24, 5.00, 1.5, 36.0, 120.00, 'Osama: Final Checking 24 pcs']);

// 11. Packing: Osama (24 pcs @ Rs. 5.00 = Rs. 120.00)
$peIns->execute(['PE-2026-012', $lotId1, $opMap['OP-P'], $workerMap['Osama'], 'General', 1, 24, 24, 5.00, 1.5, 36.0, 120.00, 'Osama: Packing 24 pcs polybags']);

echo "[✓] Demo Lot 1 (LOT-2026-001) fully seeded with complete 11-step workflow and 72-pala calculations\n";


// Demo Lot 2: Coati Ladies (24 pieces = 12 with sleeves + 12 sleeveless)
$coatiProdId = $productMap['PROD-COATI'];
$db->prepare("DELETE FROM lots WHERE lot_number = 'LOT-2026-002'")->execute();
$coatiAnatomy = json_encode([
    'with_sleeves' => 12,
    'sleeveless' => 12,
    'notes' => 'Coati Ladies: 12 with sleeves + 12 sleeveless'
]);
$lotIns->execute(['LOT-2026-002', $coatiProdId, $workerMap['Adnan'], $workerMap['Hashim'], $coatiAnatomy]);
$lotId2 = (int)$db->lastInsertId();

$lvIns->execute([$lotId2, 3, 2, 12]); // Charcoal Grey: 12
$lvIns->execute([$lotId2, 4, 2, 12]); // Maroon: 12

$lSeq = 1;
foreach (['OP-OPERATOR', 'OP-PPRESS', 'OP-QC', 'OP-KATAI', 'OP-COATIOP', 'OP-MODAY', 'OP-MOTI', 'OP-SAFTY', 'OP-TANKAY', 'OP-KAJ', 'OP-BTN5', 'OP-MPRESS', 'OP-CHECKING', 'OP-P'] as $opCode) {
    if (isset($opMap[$opCode])) {
        $lwsIns->execute([$lotId2, $opMap[$opCode], $lSeq++]);
    }
}

// Production entries for Lot 2
$peIns->execute(['PE-2026-020', $lotId2, $opMap['OP-OPERATOR'], $workerMap['Adnan'], 'Day Shift', 3, 12, 12, 12.00, 4.8, 57.6, 144.00, 'Adnan: Coati 12 pcs with sleeves']);
$peIns->execute(['PE-2026-021', $lotId2, $opMap['OP-OPERATOR'], $workerMap['Hashim'], 'Night Shift', 4, 12, 12, 10.00, 4.0, 48.0, 120.00, 'Hashim: Coati 12 pcs sleeveless']);
$peIns->execute(['PE-2026-022', $lotId2, $opMap['OP-PPRESS'], $workerMap['LALA'], 'General', 3, 24, 24, 10.00, 2.0, 48.0, 720.00, 'LALA: 72 palay press Coati @ Rs. 10/pala']);
$peIns->execute(['PE-2026-023', $lotId2, $opMap['OP-COATIOP'], $workerMap['Asad'], 'General', 3, 24, 24, 38.00, 9.5, 228.0, 912.00, 'Asad: Coati Open Pocket 24 pcs @ Rs. 38']);
$peIns->execute(['PE-2026-024', $lotId2, $opMap['OP-BTN5'], $workerMap['Humza'], 'General', 3, 24, 24, 7.00, 2.0, 48.0, 168.00, 'Humza: Button5 attachment 24 pcs']);

echo "[✓] Demo Lot 2 (LOT-2026-002) Coati Ladies seeded with production operations\n";


// Demo Lot 3: Shawl Bazo (24 pieces = 12 Shawl Bazo + 12 Shawl Sendo)
$shawlProdId = $productMap['PROD-SHAWLB'];
$db->prepare("DELETE FROM lots WHERE lot_number = 'LOT-2026-003'")->execute();
$shawlAnatomy = json_encode([
    'with_sleeves' => 12,
    'sleeveless' => 12,
    'notes' => '12 Shawl Bazo (12+24 = 36 palay) + 12 Shawl Sendo (12 palay) = 48 palay'
]);
$lotIns->execute(['LOT-2026-003', $shawlProdId, $workerMap['Adnan'], $workerMap['Hashim'], $shawlAnatomy]);
$lotId3 = (int)$db->lastInsertId();

$lvIns->execute([$lotId3, 5, 5, 12]); // Off-White Free Size: 12
$lvIns->execute([$lotId3, 1, 5, 12]); // Black Free Size: 12

$lSeq = 1;
foreach (['OP-OPERATOR', 'OP-PPRESS', 'OP-QC', 'OP-KATAI', 'OP-SSL', 'OP-MODAY', 'OP-LBL', 'OP-SAFTY', 'OP-TANKAY', 'OP-DORI', 'OP-MPRESS', 'OP-CHECKING', 'OP-P'] as $opCode) {
    if (isset($opMap[$opCode])) {
        $lwsIns->execute([$lotId3, $opMap[$opCode], $lSeq++]);
    }
}

// Production entries for Lot 3
$peIns->execute(['PE-2026-030', $lotId3, $opMap['OP-OPERATOR'], $workerMap['Adnan'], 'Day Shift', 5, 12, 12, 10.00, 4.0, 48.0, 120.00, 'Adnan: Shawl 12 + Bazo 24 panels']);
$peIns->execute(['PE-2026-031', $lotId3, $opMap['OP-PPRESS'], $workerMap['LALA'], 'General', 5, 24, 24, 10.00, 2.0, 48.0, 480.00, 'LALA: 12+24 (36 bazo) + 12 (12 sendo) = 48 palay press @ Rs. 10/pala']);
$peIns->execute(['PE-2026-032', $lotId3, $opMap['OP-SSL'], $workerMap['Asad'], 'General', 5, 12, 12, 16.00, 4.0, 48.0, 192.00, 'Asad: Shawl Salai 12 pcs']);
$peIns->execute(['PE-2026-033', $lotId3, $opMap['OP-DORI'], $workerMap['Saleem'], 'General', 5, 24, 24, 12.00, 2.0, 48.0, 288.00, 'Saleem: Dori Lagwai 24 shawls @ Rs. 12']);

echo "[✓] Demo Lot 3 (LOT-2026-003) Shawl Bazo & Sendo seeded\n";

$db->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "\n=================================================================\n";
echo "SUCCESS: ALL FACTORY MASTER DATA & DEMO LOTS CONFIGURED!\n";
echo "=================================================================\n";
