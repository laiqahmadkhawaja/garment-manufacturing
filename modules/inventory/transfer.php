<?php
/**
 * Garment Manufacturing ERP - Finished Goods Warehouse Transfer
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Finished Goods Warehouse Transfer';
$activeNav = 'stock_transfer';
$db = get_db();

$preselectedStockId = (int)($_GET['stock_id'] ?? 0);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'execute_transfer') {
    check_csrf();
    $transferDate = trim($_POST['transfer_date'] ?? date('Y-m-d'));
    $fromWhId = (int)($_POST['from_warehouse_id'] ?? 0);
    $toWhId = (int)($_POST['to_warehouse_id'] ?? 0);
    $stockId = (int)($_POST['stock_id'] ?? 0);
    $qty = (int)($_POST['quantity'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');

    if ($fromWhId <= 0 || $toWhId <= 0) {
        $error = 'Please select both source and target warehouses.';
    } elseif ($fromWhId === $toWhId) {
        $error = 'Source warehouse and target warehouse cannot be the same.';
    } elseif ($stockId <= 0 || $qty <= 0) {
        $error = 'Please select a finished stock item and valid quantity to transfer.';
    } else {
        $db->beginTransaction();
        try {
            // Lock source stock item
            $sStmt = $db->prepare("SELECT fg.*, p.name AS product_name, l.lot_number 
                                   FROM finished_goods_stock fg 
                                   JOIN products p ON fg.product_id = p.id 
                                   JOIN lots l ON fg.lot_id = l.id 
                                   WHERE fg.id = ? AND fg.warehouse_id = ? FOR UPDATE");
            $sStmt->execute([$stockId, $fromWhId]);
            $sourceItem = $sStmt->fetch();

            if (!$sourceItem) {
                throw new Exception("Finished stock item not found in the selected source warehouse.");
            }

            if ((int)$sourceItem['current_stock'] < $qty) {
                throw new Exception("Insufficient stock! Available in source warehouse: {$sourceItem['current_stock']} pcs, requested: $qty pcs.");
            }

            // Fetch warehouse names for logging & notes
            $whFrom = $db->query("SELECT name FROM warehouses WHERE id = $fromWhId")->fetchColumn() ?: "WH#$fromWhId";
            $whTo = $db->query("SELECT name FROM warehouses WHERE id = $toWhId")->fetchColumn() ?: "WH#$toWhId";

            $trfNum = generate_doc_number('TRF-', 'warehouse_transfers', 'transfer_number');

            // 1. Deduct from source warehouse
            $upSource = $db->prepare("UPDATE finished_goods_stock SET current_stock = current_stock - ?, updated_at = NOW() WHERE id = ?");
            $upSource->execute([$qty, $stockId]);

            // 2. Add / Update in target warehouse
            $checkTarget = $db->prepare("SELECT id FROM finished_goods_stock 
                                         WHERE lot_id = ? AND product_id = ? AND (design_id = ? OR (design_id IS NULL AND ? IS NULL)) 
                                           AND color_id = ? AND size_id = ? AND warehouse_id = ? LIMIT 1");
            $checkTarget->execute([
                $sourceItem['lot_id'],
                $sourceItem['product_id'],
                $sourceItem['design_id'],
                $sourceItem['design_id'],
                $sourceItem['color_id'],
                $sourceItem['size_id'],
                $toWhId
            ]);
            $targetStkId = $checkTarget->fetchColumn();

            if ($targetStkId) {
                $upTarget = $db->prepare("UPDATE finished_goods_stock SET current_stock = current_stock + ?, updated_at = NOW() WHERE id = ?");
                $upTarget->execute([$qty, $targetStkId]);
            } else {
                $insTarget = $db->prepare("INSERT INTO finished_goods_stock 
                    (product_id, design_id, color_id, size_id, lot_id, warehouse_id, current_stock, dispatched_stock, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())");
                $insTarget->execute([
                    $sourceItem['product_id'],
                    $sourceItem['design_id'],
                    $sourceItem['color_id'],
                    $sourceItem['size_id'],
                    $sourceItem['lot_id'],
                    $toWhId,
                    $qty
                ]);
            }

            // 3. Record in warehouse_transfers table
            $trfStmt = $db->prepare("INSERT INTO warehouse_transfers 
                (transfer_number, transfer_date, from_warehouse_id, to_warehouse_id, lot_id, product_id, design_id, color_id, size_id, quantity, remarks, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $trfStmt->execute([
                $trfNum,
                $transferDate,
                $fromWhId,
                $toWhId,
                $sourceItem['lot_id'],
                $sourceItem['product_id'],
                $sourceItem['design_id'],
                $sourceItem['color_id'],
                $sourceItem['size_id'],
                $qty,
                $remarks,
                $_SESSION['user_id'] ?? null
            ]);
            $transferId = (int)$db->lastInsertId();

            // 4. Record 2 inventory transactions (Transfer Out from Source, Transfer In to Target)
            $txStmt = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, product_id, design_id, color_id, size_id, lot_id, warehouse_id, transaction_type, quantity_in, quantity_out, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Finished Goods', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 'WAREHOUSE_TRANSFER', ?, ?, ?)");

            // Outflow from source
            $txStmt->execute([
                $transferDate,
                $sourceItem['product_id'],
                $sourceItem['design_id'],
                $sourceItem['color_id'],
                $sourceItem['size_id'],
                $sourceItem['lot_id'],
                $fromWhId,
                'Transfer Out',
                0.00,
                $qty,
                $transferId,
                "Transfer #$trfNum: Transferred $qty pcs to $whTo" . ($remarks ? " ($remarks)" : ""),
                $_SESSION['user_id'] ?? null
            ]);

            // Inflow into target
            $txStmt->execute([
                $transferDate,
                $sourceItem['product_id'],
                $sourceItem['design_id'],
                $sourceItem['color_id'],
                $sourceItem['size_id'],
                $sourceItem['lot_id'],
                $toWhId,
                'Transfer In',
                $qty,
                0.00,
                $transferId,
                "Transfer #$trfNum: Received $qty pcs from $whFrom" . ($remarks ? " ($remarks)" : ""),
                $_SESSION['user_id'] ?? null
            ]);

            log_audit('TRANSFER_FINISHED_GOODS', 'warehouse_transfers', $transferId, null, [
                'from_warehouse' => $whFrom,
                'to_warehouse' => $whTo,
                'lot_number' => $sourceItem['lot_number'],
                'quantity' => $qty
            ]);

            $db->commit();
            redirect('modules/inventory/transfer.php', "Transfer #$trfNum completed! $qty pcs of {$sourceItem['product_name']} (LOT #{$sourceItem['lot_number']}) successfully transferred from '$whFrom' to '$whTo'.");
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to execute warehouse transfer: ' . $e->getMessage();
        }
    }
}

// Fetch active warehouses
$warehouses = $db->query("SELECT * FROM warehouses WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Fetch available finished goods stocks for picker
$stocks = $db->query("SELECT fg.*, p.name AS product_name, d.design_number, c.name AS color_name, s.name AS size_name, s.code AS size_code, l.lot_number, wh.name AS warehouse_name 
                      FROM finished_goods_stock fg 
                      JOIN products p ON fg.product_id = p.id 
                      LEFT JOIN designs d ON fg.design_id = d.id 
                      JOIN colors c ON fg.color_id = c.id 
                      JOIN sizes s ON fg.size_id = s.id 
                      JOIN lots l ON fg.lot_id = l.id 
                      JOIN warehouses wh ON fg.warehouse_id = wh.id 
                      WHERE fg.current_stock > 0 
                      ORDER BY fg.warehouse_id ASC, l.id DESC")->fetchAll();

// Preselected item info if any
$preselectedItem = null;
if ($preselectedStockId > 0) {
    foreach ($stocks as $stk) {
        if ((int)$stk['id'] === $preselectedStockId) {
            $preselectedItem = $stk;
            break;
        }
    }
}

// Fetch recent transfers
$recentTransfers = $db->query("SELECT wt.*, l.lot_number, p.name AS product_name, d.design_number, c.name AS color_name, s.name AS size_name, s.code AS size_code,
                                      wh_from.name AS from_warehouse_name, wh_to.name AS to_warehouse_name, u.full_name AS created_by_name
                               FROM warehouse_transfers wt
                               JOIN lots l ON wt.lot_id = l.id
                               JOIN products p ON wt.product_id = p.id
                               LEFT JOIN designs d ON wt.design_id = d.id
                               JOIN colors c ON wt.color_id = c.id
                               JOIN sizes s ON wt.size_id = s.id
                               JOIN warehouses wh_from ON wt.from_warehouse_id = wh_from.id
                               JOIN warehouses wh_to ON wt.to_warehouse_id = wh_to.id
                               LEFT JOIN users u ON wt.created_by = u.id
                               ORDER BY wt.id DESC LIMIT 15")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Finished Goods Warehouse Transfer</h4>
                <p class="text-muted small mb-0">Move finished goods between factory warehouses, depots and showroom branches (تیار مال کا گودام سے گودام ٹرانسفر)</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/settings/warehouses.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-building"></i>
                    <span>Manage Warehouses</span>
                </a>
                <a href="<?= BASE_URL ?>modules/inventory/index.php" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
                    <i class="bi bi-tags-fill"></i>
                    <span>Finished Stock</span>
                </a>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Transfer Form Card -->
        <div class="card-modern shadow-sm border-0 mb-4" style="max-width: 950px;">
            <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-arrow-left-right text-primary me-2"></i> Execute Finished Goods Stock Transfer
                    </h6>
                    <span class="text-muted small">فیکٹری گودام سے مال نکال کر شو روم یا دوسرے ڈسپیچ ڈپو میں منتقل کریں</span>
                </div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1">
                    <i class="bi bi-shield-check me-1"></i> Automatic Inward/Outward Ledger Balance
                </span>
            </div>
            <div class="card-modern-body p-4">
                <form method="POST" action="" id="transferForm">
                    <input type="hidden" name="action" value="execute_transfer">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold text-muted">Transfer Date *</label>
                            <input type="date" name="transfer_date" class="form-control form-control-sm fw-bold" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold text-danger">Source Warehouse (منتقل کنندہ گودام / From) *</label>
                            <select name="from_warehouse_id" id="from_warehouse_id" class="form-select form-select-sm fw-bold border-danger" required>
                                <option value="">-- Choose Origin Warehouse --</option>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>" <?= ($preselectedItem && $preselectedItem['warehouse_id'] == $wh['id']) ? 'selected' : '' ?>>
                                        <?= clean($wh['name']) ?> (<?= clean($wh['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold text-success">Target Warehouse (منزل گودام / To) *</label>
                            <select name="to_warehouse_id" id="to_warehouse_id" class="form-select form-select-sm fw-bold border-success" required>
                                <option value="">-- Choose Destination Warehouse --</option>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>">
                                        <?= clean($wh['name']) ?> (<?= clean($wh['code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Available Stock Item Selection (Dynamically filtered by Source Warehouse) -->
                    <div class="p-3 bg-light rounded-3 border mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label small fw-bold text-dark mb-0">
                                <i class="bi bi-box-seam text-primary me-1"></i> Available Stock Item in Source Warehouse *
                            </label>
                            <span id="stockCountBadge" class="badge bg-light text-muted border small">0 items in origin</span>
                        </div>
                        <select name="stock_id" id="stock_id" class="form-select form-select-sm fw-semibold" required>
                            <option value="">-- First Select Source Warehouse Above --</option>
                            <?php foreach ($stocks as $stk): ?>
                                <option value="<?= $stk['id'] ?>"
                                        data-wh="<?= $stk['warehouse_id'] ?>"
                                        data-max="<?= $stk['current_stock'] ?>"
                                        data-lot="<?= clean($stk['lot_number']) ?>"
                                        data-prod="<?= clean($stk['product_name']) ?>"
                                        <?= ($preselectedStockId === (int)$stk['id']) ? 'selected' : '' ?>>
                                    LOT #<?= clean($stk['lot_number']) ?> — <?= clean($stk['product_name']) ?> (Des: <?= $stk['design_number'] ?: 'Std' ?>) | <?= clean($stk['color_name']) ?> / <?= clean($stk['size_name']) ?> [Available: <?= $stk['current_stock'] ?> pcs in <?= clean($stk['warehouse_name']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="stockAvailabilityHint" class="mt-2 small"></div>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold text-dark">Quantity to Transfer (پیسز) *</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="quantity" id="transfer_qty" class="form-control form-control-sm text-center fw-bold text-primary fs-5" min="1" placeholder="0" required>
                                <span class="input-group-text">pcs</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-8">
                            <label class="form-label small fw-semibold text-muted">Transport / Reason / Remarks</label>
                            <input type="text" name="remarks" class="form-control form-control-sm" placeholder="e.g. Moved 30 pcs to City Showroom for wholesale dispatch (Driver Tariq)">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <a href="<?= BASE_URL ?>modules/inventory/index.php" class="btn btn-outline-secondary px-4">Cancel</a>
                        <button type="submit" id="btnSubmitTransfer" class="btn btn-primary px-5 fw-bold shadow-sm" disabled>
                            <i class="bi bi-arrow-left-right me-1"></i> Confirm & Transfer Finished Goods
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Recent Transfers Log Table -->
        <div class="card-modern shadow-sm border-0">
            <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-clock-history text-primary me-2"></i> Recent Warehouse Stock Transfers (<?= count($recentTransfers) ?>)
                </h6>
                <span class="text-muted small">تمام گوداموں کے درمیان پچھلے اسٹاک ٹرانسفرز کا مکمل ریکارڈ</span>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Transfer #</th>
                                <th>Date</th>
                                <th>Batch / Lot #</th>
                                <th>Product & Variant</th>
                                <th>From Warehouse</th>
                                <th>To Warehouse</th>
                                <th class="text-center">Transferred Qty</th>
                                <th>Remarks & Dispatcher</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTransfers)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">
                                        ابھی تک کوئی گودام ٹرانسفر ریکارڈ نہیں ہوا۔
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentTransfers as $trf): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-light text-primary border fw-bold">
                                                <?= clean($trf['transfer_number']) ?>
                                            </span>
                                        </td>
                                        <td><?= format_date($trf['transfer_date']) ?></td>
                                        <td>
                                            <span class="fw-bold text-dark">LOT #<?= clean($trf['lot_number']) ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= clean($trf['product_name']) ?></div>
                                            <div class="small text-muted"><?= clean($trf['color_name']) ?> / <?= clean($trf['size_name']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger-subtle text-danger border">
                                                <i class="bi bi-box-arrow-right me-1"></i><?= clean($trf['from_warehouse_name']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success-subtle text-success border">
                                                <i class="bi bi-box-arrow-in-left me-1"></i><?= clean($trf['to_warehouse_name']) ?>
                                            </span>
                                        </td>
                                        <td class="text-center fw-bold text-primary fs-6">
                                            <?= number_format($trf['quantity']) ?> pcs
                                        </td>
                                        <td>
                                            <div class="small text-dark"><?= clean($trf['remarks'] ?: 'Direct stock transfer') ?></div>
                                            <div class="small text-muted" style="font-size: 10px;">By: <?= clean($trf['created_by_name'] ?: 'Staff') ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div> <!-- end app-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    const fromWhSel = document.getElementById('from_warehouse_id');
    const toWhSel = document.getElementById('to_warehouse_id');
    const stockSel = document.getElementById('stock_id');
    const qtyInp = document.getElementById('transfer_qty');
    const hintEl = document.getElementById('stockAvailabilityHint');
    const countBadge = document.getElementById('stockCountBadge');
    const btnSubmit = document.getElementById('btnSubmitTransfer');

    const allStockOptions = Array.from(stockSel.options).slice(1);

    function filterStocksBySourceWarehouse() {
        const fromWh = fromWhSel.value;
        stockSel.innerHTML = '<option value="">-- Choose Stock Item to Transfer --</option>';

        if (!fromWh) {
            stockSel.innerHTML = '<option value="">-- First Select Source Warehouse Above --</option>';
            countBadge.textContent = '0 items in origin';
            hintEl.textContent = '';
            validateForm();
            return;
        }

        let matchingCount = 0;
        allStockOptions.forEach(opt => {
            if (opt.dataset.wh === fromWh) {
                stockSel.appendChild(opt.cloneNode(true));
                matchingCount++;
            }
        });

        countBadge.textContent = `${matchingCount} items available`;

        if (matchingCount === 0) {
            hintEl.innerHTML = `<span class="text-danger small"><i class="bi bi-exclamation-circle me-1"></i>اس گودام میں فی الوقت کوئی تیار شدہ مال موجود نہیں ہے۔</span>`;
        } else {
            hintEl.textContent = '';
        }

        validateForm();
    }

    function updateStockMax() {
        const opt = stockSel.selectedOptions[0];
        if (opt && opt.dataset.max) {
            const max = parseInt(opt.dataset.max) || 0;
            qtyInp.max = max;
            hintEl.innerHTML = `<span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-box-seam me-1"></i> Available in Source: <strong>${max} pieces</strong></span>`;
            if (!qtyInp.value || parseInt(qtyInp.value) <= 0) {
                qtyInp.value = max;
            } else if (parseInt(qtyInp.value) > max) {
                qtyInp.value = max;
            }
        } else {
            hintEl.textContent = '';
        }
        validateForm();
    }

    function validateForm() {
        const fromWh = fromWhSel.value;
        const toWh = toWhSel.value;
        const stockId = stockSel.value;
        const q = parseInt(qtyInp.value) || 0;
        const opt = stockSel.selectedOptions[0];
        const max = opt ? (parseInt(opt.dataset.max) || 0) : 0;

        let valid = true;
        if (!fromWh || !toWh || !stockId) valid = false;
        if (fromWh && toWh && fromWh === toWh) {
            valid = false;
            alert('منزل گودام (Target) اور نکلنے والا گودام (Source) ایک جیسے نہیں ہو سکتے۔');
            toWhSel.value = '';
        }
        if (q <= 0 || q > max) valid = false;

        btnSubmit.disabled = !valid;
    }

    fromWhSel.addEventListener('change', function() {
        filterStocksBySourceWarehouse();
    });

    toWhSel.addEventListener('change', validateForm);

    stockSel.addEventListener('change', function() {
        updateStockMax();
    });

    qtyInp.addEventListener('input', validateForm);

    // Initial trigger if source warehouse is pre-selected
    if (fromWhSel.value) {
        filterStocksBySourceWarehouse();
        <?php if ($preselectedStockId > 0): ?>
            stockSel.value = '<?= $preselectedStockId ?>';
            updateStockMax();
        <?php endif; ?>
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
