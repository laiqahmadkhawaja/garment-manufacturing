<?php
/**
 * Garment Manufacturing ERP - Customer Dispatch Returns
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Dispatch Returns';
$activeNav = 'dispatch';
$db = get_db();

$preselectedDispId = (int)($_GET['dispatch_id'] ?? 0);

// Handle Return Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_return') {
    $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
    $returnDate = trim($_POST['return_date'] ?? date('Y-m-d'));
    $returnType = trim($_POST['return_type'] ?? 'Saleable'); // Saleable, Damaged, Rework
    $returnQty = (int)($_POST['return_qty'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($dispatchId <= 0 || $returnQty <= 0) {
        set_flash('danger', 'Please choose a valid dispatch and return quantity.');
    } else {
        $db->beginTransaction();
        try {
            // Get original dispatch & first item details
            $dStmt = $db->prepare("SELECT d.*, di.lot_id, di.product_id, di.design_id, di.color_id, di.size_id, di.unit_rate, di.quantity AS dispatched_item_qty 
                                   FROM dispatches d 
                                   JOIN dispatch_items di ON d.id = di.dispatch_id 
                                   WHERE d.id = ? LIMIT 1");
            $dStmt->execute([$dispatchId]);
            $disp = $dStmt->fetch();

            if (!$disp) {
                throw new Exception("Original dispatch not found.");
            }

            if ($returnQty > (int)$disp['dispatched_item_qty']) {
                throw new Exception("Return quantity ($returnQty) exceeds dispatched quantity ({$disp['dispatched_item_qty']}).");
            }

            $unitRate = (float)$disp['unit_rate'];
            $creditAmount = round($returnQty * $unitRate, 2);
            $retNum = generate_doc_number('DRET-', 'dispatch_returns', 'return_number');

            // 1. Insert Return Header
            $rStmt = $db->prepare("INSERT INTO dispatch_returns (return_number, return_date, dispatch_id, customer_id, return_type, remarks, created_by) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $rStmt->execute([$retNum, $returnDate, $dispatchId, $disp['customer_id'], $returnType, $remarks, $_SESSION['user_id'] ?? null]);
            $returnId = (int)$db->lastInsertId();

            // 2. Insert Return Item
            $itemStmt = $db->prepare("INSERT INTO dispatch_return_items (return_id, lot_id, product_id, design_id, color_id, size_id, quantity, unit_rate, total_amount) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $itemStmt->execute([$returnId, $disp['lot_id'], $disp['product_id'], $disp['design_id'], $disp['color_id'], $disp['size_id'], $returnQty, $unitRate, $creditAmount]);

            // 3. If Saleable, add back to finished goods stock
            if ($returnType === 'Saleable') {
                $upStock = $db->prepare("UPDATE finished_goods_stock 
                                         SET current_stock = current_stock + ?, dispatched_stock = GREATEST(0, dispatched_stock - ?) 
                                         WHERE product_id = ? AND color_id = ? AND size_id = ? AND lot_id = ?");
                $upStock->execute([$returnQty, $returnQty, $disp['product_id'], $disp['color_id'], $disp['size_id'], $disp['lot_id']]);

                // Inventory transaction
                $tx = $db->prepare("INSERT INTO inventory_transactions 
                    (transaction_date, item_type, product_id, design_id, color_id, size_id, lot_id, warehouse_id, transaction_type, quantity_in, unit_cost, reference_type, reference_id, remarks, created_by) 
                    VALUES (?, 'Finished Goods', ?, ?, ?, ?, ?, ?, 'Dispatch Return', ?, ?, 'DISPATCH_RETURN', ?, ?, ?)");
                $tx->execute([$returnDate, $disp['product_id'], $disp['design_id'], $disp['color_id'], $disp['size_id'], $disp['lot_id'], $disp['warehouse_id'], $returnQty, $unitRate, $returnId, "Customer Return ($returnType)", $_SESSION['user_id'] ?? null]);
            }

            // 4. Update Customer Ledger (Credit decreases customer receivable balance)
            if ($creditAmount > 0) {
                $cbalStmt = $db->prepare("SELECT running_balance FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
                $cbalStmt->execute([$disp['customer_id']]);
                $lastCbal = (float)$cbalStmt->fetchColumn();
                $newCbal = $lastCbal - $creditAmount;

                $cLedg = $db->prepare("INSERT INTO customer_ledger 
                    (customer_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                    VALUES (?, ?, 'Return', 'DISPATCH_RETURN', ?, 0.00, ?, ?, ?, ?)");
                $cLedg->execute([
                    $disp['customer_id'],
                    $returnDate,
                    $returnId,
                    $creditAmount,
                    $newCbal,
                    "Goods Return under $retNum ($returnQty pcs @ Rs. $unitRate) - $returnType",
                    $_SESSION['user_id'] ?? null
                ]);
            }

            log_audit('DISPATCH_RETURN', 'dispatch_returns', $returnId, null, [
                'dispatch_id' => $dispatchId,
                'quantity' => $returnQty,
                'type' => $returnType
            ]);

            $db->commit();
            redirect('modules/dispatch/returns.php', "Return #$retNum processed! Customer ledger credited with " . format_currency($creditAmount) . ".");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error processing return: ' . $e->getMessage());
        }
    }
}

$dispatches = $db->query("SELECT d.id, d.dispatch_number, d.challan_no, d.total_pieces, c.name AS customer_name 
                         FROM dispatches d JOIN customers c ON d.customer_id = c.id ORDER BY d.id DESC")->fetchAll();

$returnsList = $db->query("SELECT dr.*, d.dispatch_number, d.challan_no, c.name AS customer_name, dri.quantity, dri.total_amount 
                          FROM dispatch_returns dr 
                          JOIN dispatches d ON dr.dispatch_id = d.id 
                          JOIN customers c ON dr.customer_id = c.id 
                          JOIN dispatch_return_items dri ON dr.id = dri.return_id 
                          ORDER BY dr.id DESC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Customer Returns Management</h4>
                <p class="text-muted small mb-0">Record returned pieces, classify saleable vs damaged stock, and issue credit notes</p>
            </div>
            <a href="<?= BASE_URL ?>modules/dispatch/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Dispatches
            </a>
        </div>

        <!-- Return Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-arrow-return-left me-1 text-warning"></i> Record Customer Return Voucher</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="process_return">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Return Date *</label>
                            <input type="date" name="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Original Dispatch Challan *</label>
                            <select name="dispatch_id" class="form-select fw-bold" required>
                                <option value="">-- Select Original Dispatch --</option>
                                <?php foreach ($dispatches as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= ($preselectedDispId === $d['id']) ? 'selected' : '' ?>>
                                        <?= clean($d['dispatch_number']) ?> (Challan: <?= clean($d['challan_no']) ?>) — <?= clean($d['customer_name']) ?> [<?= $d['total_pieces'] ?> pcs]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Stock Classification *</label>
                            <select name="return_type" class="form-select">
                                <option value="Saleable">Saleable (Adds back to finished stock)</option>
                                <option value="Damaged">Damaged / Rejection (Written off)</option>
                                <option value="Rework">Rework Required (Sent to rework)</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Return Quantity (Pcs) *</label>
                            <input type="number" name="return_qty" class="form-control form-control-lg fw-bold text-warning text-center" min="1" placeholder="0" required>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label">Customer Reason / Remarks</label>
                            <input type="text" name="remarks" class="form-control" placeholder="Size alteration requested / excess boutique stock">
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                            <button type="submit" class="btn btn-warning px-5 fw-bold text-dark">
                                <i class="bi bi-arrow-return-left me-1"></i> Accept Return & Update Stock
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Returns List -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Customer Returns History (<?= count($returnsList) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Return #</th>
                                <th>Date</th>
                                <th>Original Challan</th>
                                <th>Customer / Party</th>
                                <th>Condition</th>
                                <th class="text-center">Returned Qty</th>
                                <th class="text-end">Credit Note Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($returnsList)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted">No customer returns logged yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($returnsList as $ret): ?>
                                    <tr>
                                        <td class="fw-bold text-primary"><?= clean($ret['return_number']) ?></td>
                                        <td><?= format_date($ret['return_date']) ?></td>
                                        <td>
                                            <span class="fw-semibold text-dark"><?= clean($ret['challan_no']) ?></span>
                                            <div class="text-muted small"><?= clean($ret['dispatch_number']) ?></div>
                                        </td>
                                        <td><?= clean($ret['customer_name']) ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= clean($ret['return_type']) ?></span>
                                        </td>
                                        <td class="text-center fw-bold text-warning fs-6"><?= number_format($ret['quantity']) ?> pcs</td>
                                        <td class="text-end fw-bold text-success fs-6"><?= format_currency($ret['total_amount']) ?></td>
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
