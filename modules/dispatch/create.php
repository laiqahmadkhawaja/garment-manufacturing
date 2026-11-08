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
    check_csrf();
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

    // Payment Settlement Details
    $paymentStatus = in_array($_POST['payment_status'] ?? '', ['Paid', 'Partial', 'Credit']) ? $_POST['payment_status'] : 'Credit';
    $paidAmount = (float)($_POST['paid_amount'] ?? 0.00);
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $paymentRef = trim($_POST['payment_reference'] ?? '');

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

            // Compute paid & balance amounts based on settlement mode
            if ($totalValue <= 0) {
                $paymentStatus = 'Paid';
                $paidAmount = 0.00;
                $balanceAmount = 0.00;
            } elseif ($paymentStatus === 'Paid') {
                $paidAmount = $totalValue;
                $balanceAmount = 0.00;
            } elseif ($paymentStatus === 'Credit') {
                $paidAmount = 0.00;
                $balanceAmount = $totalValue;
            } else { // Partial
                $paidAmount = min($totalValue, max(0.00, $paidAmount));
                $balanceAmount = round($totalValue - $paidAmount, 2);
                if ($paidAmount >= $totalValue) {
                    $paymentStatus = 'Paid';
                    $balanceAmount = 0.00;
                } elseif ($paidAmount <= 0) {
                    $paymentStatus = 'Credit';
                    $balanceAmount = $totalValue;
                }
            }

            // 1. Insert Dispatch Header
            $dStmt = $db->prepare("INSERT INTO dispatches 
                (dispatch_number, dispatch_date, customer_id, warehouse_id, challan_no, vehicle_details, total_pieces, total_value, payment_status, paid_amount, balance_amount, payment_method, account_id, payment_reference, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $dStmt->execute([
                $dispNum,
                $dispatchDate,
                $customerId,
                $warehouseId,
                $challanNo,
                $vehicleDetails,
                $dispatchQty,
                $totalValue,
                $paymentStatus,
                $paidAmount,
                $balanceAmount,
                $paymentMethod,
                $accountId,
                $paymentRef,
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

            // 5. Update Customer Ledger (Debit increases receivable from customer, Credit decreases)
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

                // 5a. Record Dispatch Bill in Customer Ledger (Receivable Debit)
                $balAfterBill = round($lastCbal + $totalValue, 2);
                $dispDesc = "Dispatch #$dispNum (Challan #$challanNo) - {$dispatchQty} pcs {$item['product_name']}";

                $cLedg = $db->prepare("INSERT INTO customer_ledger 
                    (customer_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                    VALUES (?, ?, 'Dispatch', 'DISPATCH', ?, ?, 0.00, ?, ?, ?)");
                $cLedg->execute([
                    $customerId,
                    $dispatchDate,
                    $dispatchId,
                    $totalValue,
                    $balAfterBill,
                    $dispDesc,
                    $_SESSION['user_id'] ?? null
                ]);

                // 5b. Record Immediate Payment / Advance Received (Receivable Credit)
                if ($paidAmount > 0) {
                    $balAfterPay = round($balAfterBill - $paidAmount, 2);
                    $payDesc = "Payment received on Dispatch #$dispNum via $paymentMethod" . ($paymentRef ? " [Ref: $paymentRef]" : "");

                    $pLedg = $db->prepare("INSERT INTO customer_ledger 
                        (customer_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                        VALUES (?, ?, 'Payment', 'DISPATCH_PAYMENT', ?, 0.00, ?, ?, ?, ?)");
                    $pLedg->execute([
                        $customerId,
                        $dispatchDate,
                        $dispatchId,
                        $paidAmount,
                        $balAfterPay,
                        $payDesc,
                        $_SESSION['user_id'] ?? null
                    ]);

                    // Add received money to Cash / Bank account if account selected
                    if ($accountId > 0) {
                        $custStmt = $db->prepare("SELECT name FROM customers WHERE id = ?");
                        $custStmt->execute([$customerId]);
                        $custName = $custStmt->fetchColumn() ?: 'Customer';

                        record_account_transaction(
                            $accountId,
                            $dispatchDate,
                            'Customer Payment',
                            $paidAmount,
                            'inflow',
                            "Dispatch payment for Challan #$challanNo ($custName)",
                            'DISPATCH_PAYMENT',
                            $dispatchId,
                            $challanNo,
                            null,
                            $_SESSION['user_id'] ?? null
                        );
                    }
                }
            }

            // 6. Update Lot Dispatch Status
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
                'total_value' => $totalValue,
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'balance_amount' => $balanceAmount,
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

// Fetch customers along with their current ledger running balance
$customersSql = "SELECT c.*, 
    COALESCE(
        (SELECT running_balance FROM customer_ledger WHERE customer_id = c.id ORDER BY id DESC LIMIT 1),
        c.opening_balance,
        0.00
    ) AS current_balance
    FROM customers c 
    WHERE c.is_active = 1 
    ORDER BY c.name ASC";
$customers = $db->query($customersSql)->fetchAll();

$warehouses = $db->query("SELECT * FROM warehouses WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$accounts = $db->query("SELECT * FROM cash_bank_accounts WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$paymentMethods = $db->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

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
                            <label class="form-label fw-bold text-dark">Customer / Party *</label>
                            <select name="customer_id" id="dispatchCustomerId" class="form-select fw-bold" required>
                                <option value="" data-balance="0">-- Select Customer / Party --</option>
                                <?php foreach ($customers as $c): ?>
                                    <?php $bal = (float)$c['current_balance']; ?>
                                    <option value="<?= $c['id'] ?>" data-balance="<?= $bal ?>">
                                        <?= clean($c['name']) ?> (<?= clean($c['city'] ?: 'Store') ?>)
                                        <?php if ($bal > 0): ?>
                                            — [Receivable: Rs. <?= number_format($bal, 2) ?>]
                                        <?php elseif ($bal < 0): ?>
                                            — [Advance: Rs. <?= number_format(abs($bal), 2) ?>]
                                        <?php else: ?>
                                            — [Bal: Nil]
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="custBalanceNotice" class="mt-1 small" style="display: none;"></div>
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

                        <!-- Customer Payment & Settlement Box -->
                        <div class="col-12">
                            <div class="card border border-primary-subtle bg-light-subtle rounded-3 p-3 shadow-xs">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                                    <div>
                                        <span class="fw-bold text-dark fs-6">
                                            <i class="bi bi-wallet2 text-success me-1"></i> Customer Payment & Ledger Settlement (ادائیگی / وصولی کی تفصیلات)
                                        </span>
                                        <span class="text-muted small ms-2 d-none d-md-inline">• بل کے ساتھ نقد، پیشگی رقم یا مکمل ادھار درج کریں تاکہ کھاتہ فوری ہم آہنگ ہو</span>
                                    </div>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="payment_status" id="payStatusCredit" value="Credit" autocomplete="off" checked>
                                        <label class="btn btn-outline-danger fw-bold px-3 py-1" for="payStatusCredit">
                                            <i class="bi bi-clock-history me-1"></i> Credit / Unpaid (مکمل ادھار)
                                        </label>

                                        <input type="radio" class="btn-check" name="payment_status" id="payStatusPartial" value="Partial" autocomplete="off">
                                        <label class="btn btn-outline-warning fw-bold px-3 py-1 text-dark" for="payStatusPartial">
                                            <i class="bi bi-pie-chart-fill me-1"></i> Partial (جزوی / ایڈوانس)
                                        </label>

                                        <input type="radio" class="btn-check" name="payment_status" id="payStatusPaid" value="Paid" autocomplete="off">
                                        <label class="btn btn-outline-success fw-bold px-3 py-1" for="payStatusPaid">
                                            <i class="bi bi-check-circle-fill me-1"></i> Full Paid (مکمل نقد وصول)
                                        </label>
                                    </div>
                                </div>

                                <div class="row g-3 align-items-center">
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small fw-semibold text-muted mb-1">Paid / Advance (وصول شدہ رقم)</label>
                                        <div class="input-group">
                                            <span class="input-group-text fw-bold text-success">Rs.</span>
                                            <input type="number" name="paid_amount" id="dispPaidAmount" class="form-control form-control-lg fw-bold text-end text-success fs-5" step="0.01" min="0" placeholder="0.00" value="0.00">
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small fw-semibold text-muted mb-1">Bill Balance Due (اس بل کا باقی)</label>
                                        <div class="p-2 border rounded bg-white text-end fw-bold fs-5 text-danger" id="lblDispBalance">
                                            Rs. 0.00
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-3" id="payAccountWrapper">
                                        <label class="form-label small fw-semibold text-muted mb-1">Deposit To (وصولی کھاتہ / کیش رجسٹر)</label>
                                        <select name="account_id" id="dispAccountId" class="form-select form-select-sm fw-semibold">
                                            <option value="">-- Direct Factory Cash Drawer --</option>
                                            <?php foreach ($accounts as $acc): ?>
                                                <option value="<?= $acc['id'] ?>">
                                                    <?= clean($acc['account_name']) ?> (Bal: Rs. <?= number_format((float)$acc['current_balance'], 2) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3" id="payMethodWrapper">
                                        <label class="form-label small fw-semibold text-muted mb-1">Payment Method</label>
                                        <select name="payment_method" id="dispPaymentMethod" class="form-select form-select-sm fw-semibold">
                                            <option value="Cash">Cash (نقد)</option>
                                            <option value="Bank Transfer">Bank Transfer (بینک ٹرانسفر)</option>
                                            <option value="Cheque">Cheque (بینک چیک)</option>
                                            <option value="Online / Mobile">Online (JazzCash / EasyPaisa / Raast)</option>
                                            <?php foreach ($paymentMethods as $pm): ?>
                                                <?php if (!in_array($pm['name'], ['Cash', 'Bank Transfer', 'Cheque', 'Online / Mobile'])): ?>
                                                    <option value="<?= clean($pm['name']) ?>"><?= clean($pm['name']) ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3" id="payRefWrapper">
                                        <label class="form-label small fw-semibold text-muted mb-1">Cheque / Trx # (اختیاری)</label>
                                        <input type="text" name="payment_reference" id="dispPaymentRef" class="form-control form-control-sm" placeholder="e.g. Chq# 4022 or Trx#">
                                    </div>

                                    <!-- Customer Financial Summary Pill Card -->
                                    <div class="col-12">
                                        <div class="p-3 border rounded-3 bg-white">
                                            <div class="row text-center g-2">
                                                <div class="col-6 col-md-3">
                                                    <span class="text-muted small d-block">Dispatch Total Bill (موجودہ بل)</span>
                                                    <span class="fw-bold text-dark fs-6" id="sumBillVal">Rs. 0.00</span>
                                                </div>
                                                <div class="col-6 col-md-3">
                                                    <span class="text-muted small d-block">Received Now (وصول شدہ)</span>
                                                    <span class="fw-bold text-success fs-6" id="sumPaidVal">Rs. 0.00</span>
                                                </div>
                                                <div class="col-6 col-md-3">
                                                    <span class="text-muted small d-block">Previous Balance (سابقہ کھاتہ)</span>
                                                    <span class="fw-bold fs-6" id="sumPrevBal">Rs. 0.00</span>
                                                </div>
                                                <div class="col-6 col-md-3">
                                                    <span class="text-muted small d-block">Customer Net Closing (نیا مجموعی بیلنس)</span>
                                                    <span class="fw-bold fs-6" id="sumNetBal">Rs. 0.00</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div id="payStatusBanner" class="alert alert-secondary py-2 px-3 mb-0 small fw-semibold border">
                                            <i class="bi bi-info-circle me-1"></i> کسٹمر اور مالیت درج کریں تاکہ ادائیگی کا تفصیلی حساب اور نیا بیلنس تیار ہو۔
                                        </div>
                                    </div>
                                </div>
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
    const custSelect = document.getElementById('dispatchCustomerId');
    const custBalanceNotice = document.getElementById('custBalanceNotice');
    const stockSelect = document.getElementById('dispatchStockSelect');
    const qtyInput = document.getElementById('dispQty');
    const rateInput = document.getElementById('dispUnitRate');
    const totalDisplay = document.getElementById('lblDispTotal');
    const maxNotice = document.getElementById('dispStockMaxNotice');

    const payStatusCredit = document.getElementById('payStatusCredit');
    const payStatusPartial = document.getElementById('payStatusPartial');
    const payStatusPaid = document.getElementById('payStatusPaid');
    const paidInput = document.getElementById('dispPaidAmount');
    const lblDispBalance = document.getElementById('lblDispBalance');

    const sumBillVal = document.getElementById('sumBillVal');
    const sumPaidVal = document.getElementById('sumPaidVal');
    const sumPrevBal = document.getElementById('sumPrevBal');
    const sumNetBal = document.getElementById('sumNetBal');
    const payStatusBanner = document.getElementById('payStatusBanner');

    function getSelectedStatus() {
        if (payStatusPaid && payStatusPaid.checked) return 'Paid';
        if (payStatusPartial && payStatusPartial.checked) return 'Partial';
        return 'Credit';
    }

    function formatRs(num) {
        return 'Rs. ' + Number(num).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function calcFinancials(fromUserTyping = false) {
        const q = parseInt(qtyInput.value) || 0;
        const r = parseFloat(rateInput.value) || 0;
        const totalBill = Math.round(q * r * 100) / 100;
        totalDisplay.textContent = formatRs(totalBill);

        // Get customer previous balance
        const selectedCustOpt = custSelect.options[custSelect.selectedIndex];
        const prevBal = (selectedCustOpt && selectedCustOpt.dataset.balance) ? parseFloat(selectedCustOpt.dataset.balance) : 0;

        // Update customer balance badge under dropdown
        if (selectedCustOpt && selectedCustOpt.value) {
            custBalanceNotice.style.display = 'block';
            if (prevBal > 0) {
                custBalanceNotice.innerHTML = `<span class="badge bg-danger-subtle text-danger border px-2 py-1"><i class="bi bi-clock-history me-1"></i> سابقہ واجب الادا / ادھار (Receivable): <strong>${formatRs(prevBal)}</strong></span>`;
            } else if (prevBal < 0) {
                custBalanceNotice.innerHTML = `<span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> سابقہ پیشگی رقم (Advance with Factory): <strong>${formatRs(Math.abs(prevBal))}</strong></span>`;
            } else {
                custBalanceNotice.innerHTML = `<span class="badge bg-light text-muted border px-2 py-1"><i class="bi bi-dash-circle me-1"></i> سابقہ بیلنس: صفر (Nil Balance)</span>`;
            }
        } else {
            custBalanceNotice.style.display = 'none';
        }

        const mode = getSelectedStatus();
        let paid = parseFloat(paidInput.value) || 0;

        if (mode === 'Paid') {
            paid = totalBill;
            paidInput.value = totalBill.toFixed(2);
            paidInput.readOnly = true;
        } else if (mode === 'Credit') {
            paid = 0;
            paidInput.value = '0.00';
            paidInput.readOnly = true;
        } else { // Partial
            paidInput.readOnly = false;
            if (!fromUserTyping) {
                if (paid === 0 || paid >= totalBill) {
                    paid = totalBill > 0 ? Math.round((totalBill / 2) * 100) / 100 : 0;
                    paidInput.value = paid.toFixed(2);
                }
            }
            if (paid > totalBill) {
                paid = totalBill;
                paidInput.value = totalBill.toFixed(2);
            }
        }

        const remainingBill = Math.max(0, Math.round((totalBill - paid) * 100) / 100);
        lblDispBalance.textContent = formatRs(remainingBill);
        if (remainingBill > 0) {
            lblDispBalance.className = 'p-2 border rounded bg-white text-end fw-bold fs-5 text-danger';
        } else {
            lblDispBalance.className = 'p-2 border rounded bg-white text-end fw-bold fs-5 text-success';
        }

        // Net Customer Balance = Previous Balance + Total Bill - Paid
        const netBal = Math.round((prevBal + totalBill - paid) * 100) / 100;

        sumBillVal.textContent = formatRs(totalBill);
        sumPaidVal.textContent = formatRs(paid);

        // Previous balance pill
        if (prevBal > 0) {
            sumPrevBal.className = 'fw-bold fs-6 text-danger';
            sumPrevBal.textContent = formatRs(prevBal) + ' (Receivable)';
        } else if (prevBal < 0) {
            sumPrevBal.className = 'fw-bold fs-6 text-success';
            sumPrevBal.textContent = formatRs(Math.abs(prevBal)) + ' (Advance)';
        } else {
            sumPrevBal.className = 'fw-bold fs-6 text-muted';
            sumPrevBal.textContent = 'Rs. 0.00 (Nil)';
        }

        // Net balance pill
        if (netBal > 0) {
            sumNetBal.className = 'fw-bold fs-6 text-danger';
            sumNetBal.textContent = formatRs(netBal) + ' (Receivable / کسٹمر ذمہ واجب الوصول)';
        } else if (netBal < 0) {
            sumNetBal.className = 'fw-bold fs-6 text-success';
            sumNetBal.textContent = formatRs(Math.abs(netBal)) + ' (Advance / کسٹمر کا ایڈوانس موجود)';
        } else {
            sumNetBal.className = 'fw-bold fs-6 text-success';
            sumNetBal.textContent = 'Rs. 0.00 (Clear / کھاتہ صاف)';
        }

        // Update status banner
        if (mode === 'Paid') {
            payStatusBanner.className = 'alert alert-success py-2 px-3 mb-0 small fw-semibold border';
            payStatusBanner.innerHTML = `<i class="bi bi-check2-circle me-1"></i> <strong>Full Paid (مکمل نقد وصول):</strong> ڈسپیچ کا پورا بل (${formatRs(totalBill)}) موقع پر وصول ریکارڈ ہو گا اور کسٹمر کے کھاتے میں اس بل کا کوئی ادھار باقی نہیں رہے گا۔`;
        } else if (mode === 'Credit') {
            payStatusBanner.className = 'alert alert-danger py-2 px-3 mb-0 small fw-semibold border';
            payStatusBanner.innerHTML = `<i class="bi bi-clock-history me-1"></i> <strong>Credit / Unpaid (مکمل ادھار):</strong> موجودہ ڈسپیچ کی پوری مالیت (${formatRs(totalBill)}) کسٹمر کے ذمے بقایا ادھار کھاتے (Receivable) میں شامل ہو جائے گی۔`;
        } else {
            payStatusBanner.className = 'alert alert-warning py-2 px-3 mb-0 small fw-semibold border';
            payStatusBanner.innerHTML = `<i class="bi bi-pie-chart-fill me-1"></i> <strong>Partial / Advance (جزوی وصولی):</strong> بل میں سے <strong>${formatRs(paid)}</strong> نقد/بینک وصول ہو رہے ہیں جبکہ باقی <strong>${formatRs(remainingBill)}</strong> کسٹمر کے ادھار کھاتے میں درج ہوں گے۔`;
        }
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

    custSelect.addEventListener('change', function() {
        calcFinancials(false);
    });

    [payStatusCredit, payStatusPartial, payStatusPaid].forEach(function(radio) {
        if (radio) {
            radio.addEventListener('change', function() {
                calcFinancials(false);
            });
        }
    });

    qtyInput.addEventListener('input', function() {
        calcFinancials(false);
    });

    rateInput.addEventListener('input', function() {
        calcFinancials(false);
    });

    paidInput.addEventListener('input', function() {
        calcFinancials(true);
    });

    // Run initial calculation
    calcFinancials(false);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
