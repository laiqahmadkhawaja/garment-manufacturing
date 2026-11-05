<?php
/**
 * Garment Manufacturing ERP - Issue Raw Material to Production Lot
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Issue Material to Lot';
$activeNav = 'material_issues';
$db = get_db();

$preselectedLotId = (int)($_GET['lot_id'] ?? 0);
$preselectedMatId = (int)($_GET['material_id'] ?? 0);

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_material') {
    $lotId = (int)($_POST['lot_id'] ?? 0);
    $issueDate = trim($_POST['issue_date'] ?? date('Y-m-d'));
    $materialId = (int)($_POST['material_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0.00);
    $notes = trim($_POST['notes'] ?? '');

    if ($lotId <= 0 || $materialId <= 0 || $quantity <= 0) {
        set_flash('danger', 'Please choose a production lot, raw material, and valid quantity.');
    } else {
        $db->beginTransaction();
        try {
            // Check available stock
            $mStmt = $db->prepare("SELECT current_stock, cost_price, name, unit_id FROM materials WHERE id = ? FOR UPDATE");
            $mStmt->execute([$materialId]);
            $mat = $mStmt->fetch();

            if (!$mat) {
                throw new Exception("Material not found.");
            }

            if ((float)$mat['current_stock'] < $quantity && get_setting('allow_negative_stock', '0') !== '1') {
                throw new Exception("Insufficient stock! Available: " . $mat['current_stock'] . ", requested: $quantity");
            }

            $unitCost = (float)$mat['cost_price'];
            $totalCost = round($quantity * $unitCost, 2);
            $issueNum = generate_doc_number('ISS-', 'material_issues', 'issue_number');

            // 1. Insert Material Issue Master
            $iStmt = $db->prepare("INSERT INTO material_issues (issue_number, issue_date, lot_id, total_cost, notes, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $iStmt->execute([$issueNum, $issueDate, $lotId, $totalCost, $notes, $_SESSION['user_id'] ?? null]);
            $issueId = (int)$db->lastInsertId();

            // 2. Insert Item
            $itemStmt = $db->prepare("INSERT INTO material_issue_items (issue_id, material_id, quantity, unit_price, total_cost) 
                                      VALUES (?, ?, ?, ?, ?)");
            $itemStmt->execute([$issueId, $materialId, $quantity, $unitCost, $totalCost]);

            // 3. Deduct Raw Material Stock
            $up = $db->prepare("UPDATE materials SET current_stock = current_stock - ? WHERE id = ?");
            $up->execute([$quantity, $materialId]);

            // 4. Record Inventory Transaction
            $whId = get_default_warehouse_id('raw');
            $tx = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, material_id, lot_id, warehouse_id, transaction_type, quantity_out, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Material', ?, ?, ?, 'Material Issue', ?, ?, 'MATERIAL_ISSUE', ?, ?, ?)");
            $tx->execute([$issueDate, $materialId, $lotId, $whId, $quantity, $unitCost, $issueId, "Issued to Lot #$lotId", $_SESSION['user_id'] ?? null]);

            log_audit('ISSUE_MATERIAL', 'material_issues', $issueId, null, [
                'lot_id' => $lotId,
                'material' => $mat['name'],
                'quantity' => $quantity,
                'cost' => $totalCost
            ]);

            $db->commit();
            redirect("modules/raw_materials/print_issue.php?id=$issueId", "Material issued successfully! $quantity {$mat['name']} deducted from stock.");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error issuing material: ' . $e->getMessage());
        }
    }
}

// Fetch active lots
$lots = $db->query("SELECT l.id, l.lot_number, l.total_pieces, p.name AS product_name 
                    FROM lots l JOIN products p ON l.product_id = p.id 
                    WHERE l.status != 'Closed' ORDER BY l.id DESC")->fetchAll();

// Fetch materials with current stock
$materials = $db->query("SELECT m.id, m.name, m.code, m.current_stock, m.cost_price, u.code AS unit_code 
                         FROM materials m 
                         JOIN units u ON m.unit_id = u.id 
                         WHERE m.is_active = 1 ORDER BY m.name ASC")->fetchAll();

// Recent Issues list
$issuesList = $db->query("SELECT mi.*, l.lot_number, p.name AS product_name, m.name AS material_name, mii.quantity, u.code AS unit_code 
                          FROM material_issues mi 
                          JOIN lots l ON mi.lot_id = l.id 
                          JOIN products p ON l.product_id = p.id 
                          JOIN material_issue_items mii ON mi.id = mii.issue_id 
                          JOIN materials m ON mii.material_id = m.id 
                          JOIN units u ON m.unit_id = u.id 
                          ORDER BY mi.id DESC LIMIT 10")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Issue Raw Material to Lot</h4>
                <p class="text-muted small mb-0">Deducts yarn, fabric, or accessories from store and allocates cost to production lot</p>
            </div>
            <a href="<?= BASE_URL ?>modules/raw_materials/returns.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                <i class="bi bi-box-arrow-in-left"></i>
                <span>Material Returns</span>
            </a>
        </div>

        <!-- Issue Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-box-arrow-right me-1 text-primary"></i> Production Material Requisition Form</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="issue_material">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Issue Date *</label>
                            <input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Production Lot *</label>
                            <select name="lot_id" class="form-select fw-bold text-primary" required>
                                <option value="">-- Choose Factory Lot --</option>
                                <?php foreach ($lots as $l): ?>
                                    <option value="<?= $l['id'] ?>" <?= ($preselectedLotId === $l['id']) ? 'selected' : '' ?>>
                                        LOT #<?= clean($l['lot_number']) ?> — <?= clean($l['product_name']) ?> (<?= $l['total_pieces'] ?> pcs)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Material to Issue *</label>
                            <select name="material_id" id="issueMatSelect" class="form-select" required>
                                <option value="">-- Choose Raw Material --</option>
                                <?php foreach ($materials as $m): ?>
                                    <option value="<?= $m['id'] ?>" 
                                            data-stock="<?= $m['current_stock'] ?>" 
                                            data-unit="<?= clean($m['unit_code']) ?>"
                                            <?= ($preselectedMatId === $m['id']) ? 'selected' : '' ?>>
                                        <?= clean($m['name']) ?> (Avail: <?= number_format($m['current_stock'], 2) ?> <?= clean($m['unit_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Issue Quantity *</label>
                            <input type="number" name="quantity" id="issueQty" class="form-control form-control-lg fw-bold text-end" step="0.01" min="0.01" placeholder="0.00" required>
                            <div class="form-text" id="lblStockNotice">Select material to view stock</div>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Notes / Department Requisition Details</label>
                            <input type="text" name="notes" class="form-control" placeholder="Requisition for cutting / collar knitting / packaging">
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                            <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm">
                                <i class="bi bi-box-arrow-right me-1"></i> Issue to Production & Print Slip
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Recent Issues Table -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Recent Material Issues (<?= count($issuesList) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Issue #</th>
                                <th>Date</th>
                                <th>Lot Number</th>
                                <th>Product</th>
                                <th>Material Issued</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Cost</th>
                                <th class="text-end">Slip</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($issuesList)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No material issues recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($issuesList as $iss): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= clean($iss['issue_number']) ?></td>
                                        <td><?= format_date($iss['issue_date']) ?></td>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $iss['lot_id'] ?>" class="fw-bold text-decoration-none">
                                                LOT #<?= clean($iss['lot_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= clean($iss['product_name']) ?></td>
                                        <td class="fw-semibold text-dark"><?= clean($iss['material_name']) ?></td>
                                        <td class="text-end fw-bold text-danger">- <?= number_format($iss['quantity'], 2) ?> <?= clean($iss['unit_code']) ?></td>
                                        <td class="text-end fw-bold text-dark"><?= format_currency($iss['total_cost']) ?></td>
                                        <td class="text-end">
                                            <a href="<?= BASE_URL ?>modules/raw_materials/print_issue.php?id=<?= $iss['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const matSelect = document.getElementById('issueMatSelect');
    const notice = document.getElementById('lblStockNotice');

    function updateStockNotice() {
        const opt = matSelect.options[matSelect.selectedIndex];
        if (opt && opt.dataset.stock) {
            notice.innerHTML = `<span class="text-success fw-bold">Available in store: ${parseFloat(opt.dataset.stock).toFixed(2)} ${opt.dataset.unit}</span>`;
        } else {
            notice.textContent = 'Select material to view stock';
        }
    }

    matSelect.addEventListener('change', updateStockNotice);
    if (matSelect.value) updateStockNotice();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
