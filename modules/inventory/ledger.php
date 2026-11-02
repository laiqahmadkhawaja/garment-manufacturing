<?php
/**
 * Garment Manufacturing ERP - Central Stock Movement Ledger
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Stock Movement Ledger';
$activeNav = 'finished_goods';
$db = get_db();

// Fetch inventory transactions
$sql = "SELECT it.*, wh.name AS warehouse_name, u.full_name AS recorder_name,
               m.name AS material_name, m.code AS material_code,
               p.name AS product_name, d.design_number, c.name AS color_name, s.name AS size_name,
               l.lot_number
        FROM inventory_transactions it
        JOIN warehouses wh ON it.warehouse_id = wh.id
        LEFT JOIN materials m ON it.material_id = m.id
        LEFT JOIN products p ON it.product_id = p.id
        LEFT JOIN designs d ON it.design_id = d.id
        LEFT JOIN colors c ON it.color_id = c.id
        LEFT JOIN sizes s ON it.size_id = s.id
        LEFT JOIN lots l ON it.lot_id = l.id
        LEFT JOIN users u ON it.created_by = u.id
        ORDER BY it.id DESC LIMIT 100";

$transactions = $db->query($sql)->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Central Stock Movement Ledger</h4>
                <p class="text-muted small mb-0">Permanent auditable record of all inward purchases, factory issues, packing and customer dispatches</p>
            </div>
            <a href="<?= BASE_URL ?>modules/inventory/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Stock
            </a>
        </div>

        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Stock Movement Logs (Last 100 Transactions)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0" style="font-size: 0.85rem;">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Type</th>
                                <th>Item Category</th>
                                <th>Description / Item</th>
                                <th>Lot #</th>
                                <th class="text-end">Qty In</th>
                                <th class="text-end">Qty Out</th>
                                <th>Warehouse</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="9" class="text-center py-4 text-muted">No stock transactions logged yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $t): 
                                    $isIn = (float)$t['quantity_in'] > 0;
                                ?>
                                    <tr>
                                        <td><?= date('d M Y, h:i A', strtotime($t['created_at'])) ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= clean($t['transaction_type']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= $t['item_type'] === 'Material' ? 'warning' : 'info' ?>-subtle text-<?= $t['item_type'] === 'Material' ? 'warning-emphasis' : 'info' ?> fw-semibold">
                                                <?= clean($t['item_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($t['item_type'] === 'Material'): ?>
                                                <div class="fw-bold text-dark"><?= clean($t['material_name']) ?></div>
                                                <div class="text-muted small"><?= clean($t['material_code']) ?></div>
                                            <?php else: ?>
                                                <div class="fw-bold text-dark"><?= clean($t['product_name']) ?></div>
                                                <div class="text-muted small"><?= clean($t['color_name']) ?> (<?= clean($t['size_name']) ?>)</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $t['lot_number'] ? '<span class="fw-semibold text-primary">LOT #' . clean($t['lot_number']) . '</span>' : '-' ?>
                                        </td>
                                        <td class="text-end fw-bold text-success">
                                            <?= (float)$t['quantity_in'] > 0 ? '+ ' . number_format($t['quantity_in'], 2) : '-' ?>
                                        </td>
                                        <td class="text-end fw-bold text-danger">
                                            <?= (float)$t['quantity_out'] > 0 ? '- ' . number_format($t['quantity_out'], 2) : '-' ?>
                                        </td>
                                        <td><?= clean($t['warehouse_name']) ?></td>
                                        <td class="small text-muted"><?= clean($t['remarks']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
