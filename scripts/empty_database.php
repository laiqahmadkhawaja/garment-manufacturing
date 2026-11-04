<?php
/**
 * Garment ERP - Database Reset / Clean Slate Script
 * Empties all lots, designs, products, recipes, workflows, operations, and resets users to admin.
 */
require_once __DIR__ . '/../config/config.php';

echo "=== Garment ERP Database Reset Tool ===\n";

$db = get_db();

try {
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tablesToTruncate = [
        // Production & Lots
        'production_rework',
        'production_rejections',
        'production_entries',
        'lot_workflow_steps',
        'lot_variants',
        'lot_status_history',
        'lots',

        // Packing & Finished Goods
        'packing_entries',
        'finished_goods_stock',
        'inventory_transactions',

        // Dispatches & Returns
        'dispatch_return_items',
        'dispatch_returns',
        'dispatch_items',
        'dispatches',

        // Materials & Stores
        'material_return_items',
        'material_returns',
        'material_issue_items',
        'material_issues',
        'material_purchase_items',
        'material_purchases',

        // Accounting & Ledgers
        'customer_ledger',
        'customer_payments',
        'worker_ledger',
        'worker_payments',
        'supplier_ledger',
        'supplier_payments',
        'audit_logs',
        'notifications',

        // Designs & Elements
        'design_elements',
        'designs',

        // Products, Anatomy & Recipes
        'bom_items',
        'bom_headers',
        'product_elements',
        'products',
        'product_categories',

        // Workflows & Operations
        'workflow_template_steps',
        'workflow_templates',
        'operation_rates',
        'worker_operations',
        'operations',

        // Users
        'users'
    ];

    $existingTables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tablesToTruncate as $tbl) {
        if (in_array($tbl, $existingTables)) {
            $db->exec("TRUNCATE TABLE `$tbl`");
            echo "[✓] Cleared table: $tbl\n";
        }
    }

    // Re-create the single master admin user so the system remains accessible
    $adminPasswordHash = password_hash('admin123', PASSWORD_DEFAULT);
    $insAdmin = $db->prepare("INSERT INTO users (id, username, password_hash, full_name, role_id, phone, email, is_active) 
                              VALUES (1, 'admin', ?, 'System Administrator', 1, '0300-1234567', 'admin@garmentfactory.com', 1)");
    $insAdmin->execute([$adminPasswordHash]);
    echo "[✓] Created single clean admin user (Username: 'admin', Password: 'admin123')\n";

    $db->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "\n======================================================\n";
    echo "SUCCESS: Database has been cleared and reset for fresh testing!\n";
    echo "======================================================\n";
    echo "Login Credentials:\n";
    echo " - Username: admin\n";
    echo " - Password: admin123\n";

} catch (Exception $e) {
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "[ERROR] Reset failed: " . $e->getMessage() . "\n";
    exit(1);
}
