<?php
/**
 * Garment Manufacturing ERP - Return Unused Material from Production Lot
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Return Material from Lot';
$activeNav = 'material_returns';
$db = get_db();

$preselectedLotId = (int)($_GET['lot_id'] ?? 0);

// Handle Return Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'return_material') {
    $lotId = (int)($_POST['lot_id'] ?? 0);
    $returnDate = trim($_POST['return_date'] ?? date('Y-m-d'));
    $materialId = (int)($_POST['material_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0.00);
    $notes = trim($_POST['notes'] ?? '');

    if ($lotId <= 0 || $materialId <= 0 || $quantity <= 0) {
        set_flash('danger', 'Please choose a production lot, material, and valid quantity to return.');
    } else {
        $db->beginTransaction();
        try {
            $mStmt = $db->prepare("SELECT cost_price, name, unit_id FROM materials WHERE id = ?");
            $mStmt->execute([$materialId]);
            $mat = $mStmt->fetch();

            $unitCost = (float)$mat['cost_price'];
            $totalCost = round($quantity * $unitCost, 2);
            $retNum = generate_doc_number('RET-', 'material_returns', 'return_number');

            // 1. Insert Material Return Master
            $rStmt = $db->prepare("INSERT INTO material_returns (return_number, return_date, lot_id, total_cost, notes, created_by) 
                                  VALUES (?, ?, ?, ?, ?, ?)");
            $rStmt->execute([$retNum, $returnDate, $lotId, $totalCost, $notes, $_SESSION['user_id'] ?? null]);
            $returnId = (int)$db->lastInsertId();

            // 2. Insert Item
            $itemStmt = $db->prepare("INSERT INTO material_return_items (return_id, material_id, quantity, unit_price, total_cost) 
                                      VALUES (?, ?, ?, ?, ?)");
            $itemStmt->execute([$returnId, $materialId, $quantity, $unitCost, $totalCost]);

            // 3. Add Raw Material Stock Back
            $up = $db->prepare("UPDATE materials SET current_stock = current_stock + ? WHERE id = ?");
            $up->execute([$quantity, $materialId]);

            // 4. Record Inventory Transaction
            $tx = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, material_id, lot_id, warehouse_id, transaction_type, quantity_in, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Material', ?, ?, 2, 'Material Return', ?, ?, 'MATERIAL_RETURN', ?, ?, ?)");
            $tx->execute([$returnDate, $materialId, $lotId, $quantity, $unitCost, $returnId, "Returned from Lot #$lotId", $_SESSION['user_id'] ?? null]);

            log_audit('RETURN_MATERIAL', 'material_returns', $returnId, null, [
                'lot_id' => $lotId,
                'material' => $mat['name'],
                'quantity' => $quantity,
                'cost' => $totalCost
            ]);

            $db->commit();
            redirect('modules/raw_materials/returns.php', "Return #$retNum processed! $quantity {$mat['name']} added back to raw inventory.");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error processing return: ' . $e->getMessage());
        }
    }
}

$lots = $db->query("SELECT l.id, l.lot_number, p.name AS product_name FROM lots l JOIN products p ON l.product_id = p.id WHERE l.status != 'Closed' ORDER BY l.id DESC")->fetchAll();
$materials = $db->query("SELECT m.id, m.name, m.code, u.code AS unit_code FROM materials m JOIN units u ON m.unit_id = u.id WHERE m.is_active = 1 ORDER BY m.name ASC")->fetchAll();

$returnsList = $db->query("SELECT mr.*, l.lot_number, p.name AS product_name, m.name AS material_name, mri.quantity, u.code AS unit_code 
                           FROM material_returns mr 
                           JOIN lots l ON mr.lot_id = l.id 
                           JOIN products p ON l.product_id = p.id 
                           JOIN material_return_items mri ON mr.id = mri.return_id 
                           JOIN materials m ON mri.material_id = m.id 
                           JOIN units u ON m.unit_id = u.id 
                           ORDER BY mr.id DESC LIMIT 10")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Material Returns from Production</h4>
                <p class="text-muted small mb-0">Return unused yarn, fabric, or trims back to raw store and credit lot cost</p>
            </div>
            <a href="<?= BASE_URL ?>modules/raw_materials/issues.php" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
                <i class="bi bi-box-arrow-right"></i>
                <span>Issue to Lot</span>
            </a>
        </div>

        <!-- Return Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-box-arrow-in-left me-1 text-success"></i> Return Unused Raw Material Form</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="return_material">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Return Date *</label>
                            <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Production Lot *</label>
                            <select name="lot_id" class="form-select fw-bold text-primary" required>
                                <option value="">-- Choose Factory Lot --</option>
                                <?php foreach ($lots as $l): ?>
                                    <option value="<?= $l['id'] ?>" <?= ($preselectedLotId === $l['id']) ? 'selected' : '' ?>>
                                        LOT #<?= clean($l['lot_number']) ?> — <?= clean($l['product_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Material to Return *</label>
                            <select name="material_id" class="form-select" required>
                                <option value="">-- Choose Material --</option>
                                <?php foreach ($materials as $m): ?>
                                    <option value="<?= $m['id'] ?>">
                                        <?= clean($m['name']) ?> (<?= clean($m['code']) ?>) [<?= clean($m['unit_code']) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Return Quantity *</label>
                            <input type="number" name="quantity" class="form-control form-control-lg fw-bold text-success text-end" step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Reason / Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Excess material from cutting / batch completed">
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                            <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm">
                                <i class="bi bi-box-arrow-in-left me-1"></i> Confirm Material Return
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Returns List -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Material Returns Log (<?= count($returnsList) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Return #</th>
                                <th>Date</th>
                                <th>Lot Number</th>
                                <th>Product</th>
                                <th>Material Returned</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Cost Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returnsList)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No material returns recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($returnsList as $ret): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= clean($ret['return_number']) ?></td>
                                        <td><?= format_date($ret['return_date']) ?></td>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $ret['lot_id'] ?>" class="fw-bold text-decoration-none">
                                                LOT #<?= clean($ret['lot_number']) ?>
                                            </a>
                                        </td>
                                        <td><?= clean($ret['product_name']) ?></td>
                                        <td class="fw-semibold text-dark"><?= clean($ret['material_name']) ?></td>
                                        <td class="text-end fw-bold text-success">+ <?= number_format($ret['quantity'], 2) ?> <?= clean($ret['unit_code']) ?></td>
                                        <td class="text-end fw-bold text-success fs-6"><?= format_currency($ret['total_cost']) ?></td>
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
