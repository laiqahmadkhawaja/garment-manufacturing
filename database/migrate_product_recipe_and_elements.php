<?php
require_once __DIR__ . '/../config/config.php';

$db = get_db();

echo "Running migration: product_elements & design_number on lots...\n";

// 1. Add design_number column to lots if not exists
try {
    $db->exec("ALTER TABLE lots ADD COLUMN `design_number` VARCHAR(100) NULL AFTER `product_id`");
    echo "[✓] Added design_number column to lots table.\n";
} catch (Exception $e) {
    echo "[i] Column design_number already exists or: " . $e->getMessage() . "\n";
}

// Sync existing lots' design_number from designs table if present
$db->exec("UPDATE lots l JOIN designs d ON l.design_id = d.id SET l.design_number = d.design_number WHERE l.design_number IS NULL OR l.design_number = ''");

// 2. Create product_elements table
$db->exec("CREATE TABLE IF NOT EXISTS `product_elements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `element_name` VARCHAR(100) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `notes` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
echo "[✓] Table product_elements ready.\n";

// Seed product_elements for demo products 1, 2, 3 if empty
$peCount = (int)$db->query("SELECT COUNT(*) FROM product_elements")->fetchColumn();
if ($peCount === 0) {
    // Product 1: Ladies Sweater
    $ins = $db->prepare("INSERT INTO product_elements (product_id, element_name, quantity, notes, sort_order) VALUES (?, ?, ?, ?, ?)");
    $ins->execute([1, 'Front', 1, 'Main knit front', 1]);
    $ins->execute([1, 'Back', 1, 'Plain knit back', 2]);
    $ins->execute([1, 'Sleeves', 2, 'Ribbed cuff sleeves', 3]);
    $ins->execute([1, 'V-Neck Collar', 1, 'V-neck trim', 4]);
    $ins->execute([1, 'Bottom Hem', 1, 'Elasticated waist rib', 5]);

    // Product 2: Mens Wool Coat
    $ins->execute([2, 'Front Panels', 2, 'Left & right overlap panels', 1]);
    $ins->execute([2, 'Back', 1, 'Center back seam with vent', 2]);
    $ins->execute([2, 'Sleeves', 2, 'Tailored two-piece sleeves', 3]);
    $ins->execute([2, 'Collar & Lapel', 1, 'Notched lapel', 4]);
    $ins->execute([2, 'Pockets', 2, 'Flap side pockets', 5]);
    $ins->execute([2, 'Inner Lining', 1, 'Silk poly lining', 6]);

    // Product 3: Puffer Jacket
    $ins->execute([3, 'Front Panels', 2, 'Quilted zip panels', 1]);
    $ins->execute([3, 'Back', 1, 'Quilted back body', 2]);
    $ins->execute([3, 'Sleeves', 2, 'Elasticated cuff sleeves', 3]);
    $ins->execute([3, 'Side Pockets', 2, 'Zipper welt pockets', 4]);
    $ins->execute([3, 'Stand Collar', 1, 'Padded stand collar', 5]);
    $ins->execute([3, 'Detachable Hood', 1, 'Drawstring hood', 6]);
    echo "[✓] Seeded default product elements for demo products.\n";
}

// 3. Ensure bom_headers has wastage_percent column if not exists
try {
    $db->exec("ALTER TABLE bom_headers ADD COLUMN `wastage_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `description`");
    echo "[✓] Added wastage_percent to bom_headers.\n";
} catch (Exception $e) {
    // already exists
}

// Ensure demo products have default BOM Recipes in bom_headers / bom_items
$bomCount = (int)$db->query("SELECT COUNT(*) FROM bom_headers")->fetchColumn();
if ($bomCount === 0) {
    // Product 1: Ladies Sweater BOM Recipe (Yarn 0.450 KG, Thread 50 MTR, Label 1 PC, Polybag 1 PC)
    $db->prepare("INSERT INTO bom_headers (product_id, description, wastage_percent, is_active) VALUES (1, 'Standard Sweater Recipe', 3.00, 1)")->execute();
    $bom1 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bom_items (bom_id, material_id, quantity_per_piece) VALUES (?, 1, 0.4500)")->execute([$bom1]); // Yarn
    $db->prepare("INSERT INTO bom_items (bom_id, material_id, quantity_per_piece) VALUES (?, 4, 1.0000)")->execute([$bom1]); // Label

    // Product 2: Mens Wool Coat BOM Recipe (Wool Fabric 2.2 MTR, Buttons 6 PCS, Lining 1.8 MTR, Thread 100 MTR)
    $db->prepare("INSERT INTO bom_headers (product_id, description, wastage_percent, is_active) VALUES (2, 'Executive Coat Recipe', 5.00, 1)")->execute();
    $bom2 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bom_items (bom_id, material_id, quantity_per_piece) VALUES (?, 2, 6.0000)")->execute([$bom2]); // Buttons
    $db->prepare("INSERT INTO bom_items (bom_id, material_id, quantity_per_piece) VALUES (?, 4, 1.0000)")->execute([$bom2]); // Label

    // Product 3: Puffer Jacket BOM Recipe (Zipper 1 PC, Polyester Fabric 1.5 MTR, Thread 80 MTR)
    $db->prepare("INSERT INTO bom_headers (product_id, description, wastage_percent, is_active) VALUES (3, 'Active Jacket Recipe', 4.00, 1)")->execute();
    $bom3 = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO bom_items (bom_id, material_id, quantity_per_piece) VALUES (?, 3, 1.0000)")->execute([$bom3]); // Zipper
    $db->prepare("INSERT INTO bom_items (bom_id, material_id, quantity_per_piece) VALUES (?, 4, 1.0000)")->execute([$bom3]); // Label
    echo "[✓] Seeded default BOM recipes for demo products.\n";
}

echo "[SUCCESS] Migration completed successfully!\n";
