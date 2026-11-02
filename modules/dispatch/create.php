<?php
/**
 * Garment Manufacturing ERP - Create Customer Dispatch & Delivery Challan
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Create Customer Dispatch';
$activeNav = 'dispatch';
$db = get_db();

$preselectedLotId = (int)($_GET['lot_id'] ?? 0);
$error = '';

// Handle Dispatch Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_dispatch') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $dispatchDate = trim($_POST['dispatch_date'] ?? date('Y-m-d'));
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $challanNo = trim($_POST['challan_no'] ?? '');
    $vehicleDetails = trim($_POST['vehicle_details'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Items array: [ { stock_id, lot_id, product_id, design_id, color_id, size_id, qty, unit_rate } ]
    $stockId = (int)($_POST['stock_id'] ?? 0);
    $dispatchQty = (int)($_POST['dispatch_qty'] ?? 0);
    $unitRate = (float)($_POST['unit_rate'] ?? 0.00);

    if ($customerId <= 0 || $stockId <= 0 || $dispatchQty <= 0) {
        $error = 'Please select a customer, available stock item, and valid dispatch quantity.';
    } else {
        $db->beginTransaction();
        try {
            // Verify stock availability
            $sStmt = $db->prepare("SELECT fg.*, p.name AS product_name, l.lot_number, l.total_pieces 
                                   FROM finished_goods_stock fg 
                                   JOIN products p ON fg.product_id = p.id 
                                   JOIN lots l ON fg.lot_id = l.id 
                                   WHERE fg.id = ? FOR UPDATE");
            $sStmt->execute([$stockId]);
            $item = $sStmt->fetch();

            if (!$item) {
                throw new Exception("Finished stock item not found.");
            }

            if ((int)$item['current_stock'] < $dispatchQty) {
                throw new Exception("Insufficient stock! Available: {$item['current_stock']} pcs, requested: $dispatchQty pcs.");
            }

            $totalValue = round($dispatchQty * $unitRate, 2);
            $dispNum = generate_doc_number('DIS-', 'dispatches', 'dispatch_number');
            if (empty($challanNo)) {
                $challanNo = 'CHL-' . substr($dispNum, 4);
            }

            // 1. Insert Dispatch Header
            $dStmt = $db->prepare("INSERT INTO dispatches 
                (dispatch_number, dispatch_date, customer_id, warehouse_id, challan_no, vehicle_details, total_pieces, total_value, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $dStmt->execute([
                $dispNum,
                $dispatchDate,
                $customerId,
                $warehouseId,
                $challanNo,
                $vehicleDetails,
                $dispatchQty,
                $totalValue,
                $notes,
                $_SESSION['user_id'] ?? null
            ]);
            $dispatchId = (int)$db->lastInsertId();

            // 2. Insert Dispatch Item
            $itemStmt = $db->prepare("INSERT INTO dispatch_items 
                (dispatch_id, lot_id, product_id, design_id, color_id, size_id, quantity, unit_rate, total_amount) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $itemStmt->execute([
                $dispatchId,
                $item['lot_id'],
                $item['product_id'],
                $item['design_id'],
                $item['color_id'],
                $item['size_id'],
                $dispatchQty,
                $unitRate,
                $totalValue
            ]);

            // 3. Deduct from finished_goods_stock & increment dispatched_stock
            $upStock = $db->prepare("UPDATE finished_goods_stock 
                                    SET current_stock = current_stock - ?, dispatched_stock = dispatched_stock + ? 
                                    WHERE id = ?");
            $upStock->execute([$dispatchQty, $dispatchQty, $stockId]);

            // 4. Record in central inventory_transactions
            $tx = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, product_id, design_id, color_id, size_id, lot_id, warehouse_id, transaction_type, quantity_out, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Finished Goods', ?, ?, ?, ?, ?, ?, 'Dispatch', ?, ?, 'DISPATCH', ?, ?, ?)");
            $tx->execute([
                $dispatchDate,
                $item['product_id'],
                $item['design_id'],
                $item['color_id'],
                $item['size_id'],
                $item['lot_id'],
                $warehouseId,
                $dispatchQty,
                $unitRate,
                $dispatchId,
                "Dispatched to Customer via Challan #$challanNo",
                $_SESSION['user_id'] ?? null
            ]);

            // 5. Update Customer Ledger (Debit increases receivable from customer)
            if ($totalValue > 0) {
                // Get last customer balance
                $cbalStmt = $db->prepare("SELECT running_balance FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
                $cbalStmt->execute([$customerId]);
                $lastCbal = $cbalStmt->fetchColumn();
                if ($lastCbal === false) {
                    $cQuery = $db->prepare("SELECT opening_balance FROM customers WHERE id = ?");
                    $cQuery->execute([$customerId]);
                    $lastCbal = (float)$cQuery->fetchColumn();
                } else {
                    $lastCbal = (float)$lastCbal;
                }
                $newCbal = $lastCbal + $totalValue;

                $cLedg = $db->prepare("INSERT INTO customer_ledger 
                    (customer_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                    VALUES (?, ?, 'Dispatch', 'DISPATCH', ?, ?, 0.00, ?, ?, ?)");
                $cLedg->execute([
                    $customerId,
                    $dispatchDate,
                    $dispatchId,
                    $totalValue,
                    $newCbal,
                    "Dispatched {$dispatchQty} pcs under Challan #$challanNo (Lot #{$item['lot_number']})",
                    $_SESSION['user_id'] ?? null
                ]);
            }

            // 6. Update Lot Dispatch Status
            // Check total dispatched vs total packed for this lot
            $totDispStmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM dispatch_items WHERE lot_id = ?");
            $totDispStmt->execute([$item['lot_id']]);
            $lotTotalDispatched = (int)$totDispStmt->fetchColumn();

            $lotNewStatus = ($lotTotalDispatched >= (int)$item['total_pieces']) ? 'Dispatched' : 'Partially Dispatched';
            $db->prepare("UPDATE lots SET status = ? WHERE id = ?")->execute([$lotNewStatus, $item['lot_id']]);
            $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, NULL, ?, ?, ?)")
               ->execute([$item['lot_id'], $lotNewStatus, "Dispatched $dispatchQty pcs under Challan #$challanNo", $_SESSION['user_id'] ?? null]);

            log_audit('CREATE_DISPATCH', 'dispatches', $dispatchId, null, [
                'customer_id' => $customerId,
                'lot_id' => $item['lot_id'],
                'dispatched_qty' => $dispatchQty,
                'challan_no' => $challanNo
            ]);

            $db->commit();
            redirect("modules/dispatch/challan.php?id=$dispatchId", "Dispatch #$dispNum created! Stock deducted. Delivery Challan generated.");
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to create dispatch: ' . $e->getMessage();
        }
    }
}

// Fetch available finished goods stock items
$sqlStock = "SELECT fg.*, p.name AS product_name, d.design_number, c.name AS color_name, s.name AS size_name, s.code AS size_code, l.lot_number, wh.name AS warehouse_name 
             FROM finished_goods_stock fg 
             JOIN products p ON fg.product_id = p.id 
             LEFT JOIN designs d ON fg.design_id = d.id 
             JOIN colors c ON fg.color_id = c.id 
             JOIN sizes s ON fg.size_id = s.id 
             JOIN lots l ON fg.lot_id = l.id 
             JOIN warehouses wh ON fg.warehouse_id = wh.id 
             WHERE fg.current_stock > 0";
if ($preselectedLotId > 0) {
    $sqlStock .= " AND fg.lot_id = " . $preselectedLotId;
}
$sqlStock .= " ORDER BY l.id DESC, p.name ASC";
$availableStocks = $db->query($sqlStock)->fetchAll();

$customers = $db->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$warehouses = $db->query("SELECT * FROM warehouses WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Create Customer Dispatch</h4>
                <p class="text-muted small mb-0">Deduct finished goods from warehouse, update customer ledger and issue delivery challan</p>
            </div>
            <a href="<?= BASE_URL ?>modules/dispatch/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Dispatches
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="card-modern" style="max-width: 860px;">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-truck me-1 text-primary"></i> Dispatch Outward Challan Form</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="create_dispatch">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label">Dispatch Date *</label>
                            <input type="date" name="dispatch_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Customer / Party *</label>
                            <select name="customer_id" class="form-select fw-bold" required>
                                <option value="">-- Select Customer / Party --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>">
                                        <?= clean($c['name']) ?> (<?= clean($c['city'] ?: 'Store') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Challan / Ref #</label>
                            <input type="text" name="challan_no" class="form-control" placeholder="Auto-generated if empty">
                        </div>

                        <!-- Available Finished Goods Stock Item Selector -->
                        <div class="col-12">
                            <label class="form-label fw-bold text-dark">Available Finished Stock Item *</label>
                            <select name="stock_id" id="dispatchStockSelect" class="form-select form-select-lg" required>
                                <option value="">-- Select Available Product from Warehouse --</option>
                                <?php foreach ($availableStocks as $stk): ?>
                                    <option value="<?= $stk['id'] ?>" data-max="<?= $stk['current_stock'] ?>">
                                        LOT #<?= clean($stk['lot_number']) ?> — <?= clean($stk['product_name']) ?> (Design: <?= $stk['design_number'] ?: 'N/A' ?>) | <?= clean($stk['color_name']) ?> / <?= clean($stk['size_name']) ?> [Available: <?= $stk['current_stock'] ?> pcs in <?= clean($stk['warehouse_name']) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label text-success fw-bold">Dispatch Quantity (Pcs) *</label>
                            <input type="number" name="dispatch_qty" id="dispQty" class="form-control form-control-lg fw-bold text-success text-center" min="1" placeholder="0" required>
                            <div class="form-text" id="dispStockMaxNotice">Select item to view available stock</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Unit Sale Rate (Rs.) <span class="badge bg-light text-muted border fw-normal">Optional</span></label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" name="unit_rate" id="dispUnitRate" class="form-control form-control-lg text-end" step="1" value="0.00">
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Total Dispatch Value</label>
                            <div class="p-2 border rounded bg-light text-end fw-bold fs-5 text-primary" id="lblDispTotal">
                                Rs. 0.00
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Vehicle / Transport Details</label>
                            <input type="text" name="vehicle_details" class="form-control" placeholder="e.g. Suzuki Pickup / Driver Billa (0300-...)">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label">Warehouse Source</label>
                            <select name="warehouse_id" class="form-select">
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= clean($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Dispatch Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Carton count, transport freight terms, destination boutique">
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-3 border-top">
                            <a href="<?= BASE_URL ?>modules/dispatch/index.php" class="btn btn-outline-secondary px-4">Cancel</a>
                            <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm">
                                <i class="bi bi-truck me-1"></i> Disclose & Print Delivery Challan
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const stockSelect = document.getElementById('dispatchStockSelect');
    const qtyInput = document.getElementById('dispQty');
    const rateInput = document.getElementById('dispUnitRate');
    const totalDisplay = document.getElementById('lblDispTotal');
    const maxNotice = document.getElementById('dispStockMaxNotice');

    function calcTotal() {
        const q = parseInt(qtyInput.value) || 0;
        const r = parseFloat(rateInput.value) || 0;
        const tot = q * r;
        totalDisplay.textContent = 'Rs. ' + tot.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    stockSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt && opt.dataset.max) {
            maxNotice.innerHTML = `<span class="text-success fw-bold">Maximum available: ${opt.dataset.max} pieces</span>`;
            qtyInput.max = opt.dataset.max;
            qtyInput.placeholder = opt.dataset.max;
        } else {
            maxNotice.textContent = 'Select item to view available stock';
        }
    });

    qtyInput.addEventListener('input', calcTotal);
    rateInput.addEventListener('input', calcTotal);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
