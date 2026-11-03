<?php
require_once __DIR__ . '/../config/config.php';

$db = get_db();

echo "Running migration: create design_elements table...\n";

$sql = "CREATE TABLE IF NOT EXISTS `design_elements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `design_id` INT NOT NULL,
    `element_name` VARCHAR(100) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `notes` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`design_id`) REFERENCES `designs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$db->exec($sql);
echo "[✓] Table design_elements created successfully.\n";

// Seed default elements for existing demo designs if not already present
$cnt = (int)$db->query("SELECT COUNT(*) FROM design_elements")->fetchColumn();
if ($cnt === 0) {
    // Design 1: Ladies Cable V-Neck 901
    // Front (1), Back (1), Sleeves (2), Neck/Collar (1), Bottom Rib (1)
    $d1 = (int)$db->query("SELECT id FROM designs WHERE design_number = '901' LIMIT 1")->fetchColumn();
    if ($d1) {
        $ins = $db->prepare("INSERT INTO design_elements (design_id, element_name, quantity, notes, sort_order) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$d1, 'Front', 1, 'Cable knit pattern', 1]);
        $ins->execute([$d1, 'Back', 1, 'Plain knit', 2]);
        $ins->execute([$d1, 'Sleeves', 2, 'Ribbed cuffs', 3]);
        $ins->execute([$d1, 'V-Neck Collar', 1, 'V-neck ribbed trim', 4]);
        $ins->execute([$d1, 'Bottom Hem', 1, 'Elasticated rib', 5]);
    }

    // Design 2: Classic Executive Coat 902
    // Front (2), Back (1), Sleeves (2), Collar (1), Pockets (2), Inner Lining (1)
    $d2 = (int)$db->query("SELECT id FROM designs WHERE design_number = '902' LIMIT 1")->fetchColumn();
    if ($d2) {
        $ins = $db->prepare("INSERT INTO design_elements (design_id, element_name, quantity, notes, sort_order) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$d2, 'Front Panels', 2, 'Left & right overlap panels', 1]);
        $ins->execute([$d2, 'Back', 1, 'Center back seam with vent', 2]);
        $ins->execute([$d2, 'Sleeves', 2, 'Two-piece sleeve', 3]);
        $ins->execute([$d2, 'Collar & Lapel', 1, 'Notched lapel', 4]);
        $ins->execute([$d2, 'Pockets', 2, 'Flap side pockets', 5]);
        $ins->execute([$d2, 'Inner Lining', 1, 'Silk poly lining', 6]);
    }

    // Design 3: Urban Active Jacket 903
    // Front (1), Back (1), Sleeves (2), Pockets (2), Collar (1), Hood (1)
    $d3 = (int)$db->query("SELECT id FROM designs WHERE design_number = '903' LIMIT 1")->fetchColumn();
    if ($d3) {
        $ins = $db->prepare("INSERT INTO design_elements (design_id, element_name, quantity, notes, sort_order) VALUES (?, ?, ?, ?, ?)");
        $ins->execute([$d3, 'Front Panels', 2, 'Zipper opening', 1]);
        $ins->execute([$d3, 'Back', 1, 'Quilted panel', 2]);
        $ins->execute([$d3, 'Sleeves', 2, 'Elastic cuff bands', 3]);
        $ins->execute([$d3, 'Side Pockets', 2, 'Zipper welt pockets', 4]);
        $ins->execute([$d3, 'Stand Collar', 1, 'Padded collar', 5]);
        $ins->execute([$d3, 'Detachable Hood', 1, 'Drawstring hood', 6]);
    }
    echo "[✓] Seeded demo design elements for designs 901, 902, 903.\n";
}

$total = (int)$db->query("SELECT COUNT(*) FROM design_elements")->fetchColumn();
echo "Total design_elements records: $total\n";
