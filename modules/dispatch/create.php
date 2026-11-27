<?php
/**
 * Garment Manufacturing ERP - Create Customer Multi-Lot Dispatch & Delivery Challan
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Create Customer Dispatch';
$activeNav = 'dispatch';
$db = get_db();

$preselectedLotId = (int)($_GET['lot_id'] ?? 0);
$error = '';

// Handle Multi-Lot Dispatch Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_dispatch') {
    check_csrf();
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $dispatchDate = trim($_POST['dispatch_date'] ?? date('Y-m-d'));
    $warehouseId = (int)($_POST['warehouse_id'] ?? 1);
    $challanNo = trim($_POST['challan_no'] ?? '');
    $vehicleDetails = trim($_POST['vehicle_details'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Multi-Lot Items Array from form: items[index] = [ 'stock_id' => ..., 'qty' => ..., 'unit_rate' => ... ]
    $rawItems = $_POST['items'] ?? [];

    // Payment Settlement Details
    $paymentStatus = in_array($_POST['payment_status'] ?? '', ['Paid', 'Partial', 'Credit']) ? $_POST['payment_status'] : 'Credit';
    $paidAmount = (float)($_POST['paid_amount'] ?? 0.00);
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $paymentRef = trim($_POST['payment_reference'] ?? '');

    if ($customerId <= 0) {
        $error = 'Please select a customer / party for this dispatch.';
    } elseif (empty($rawItems) || !is_array($rawItems)) {
        $error = 'Please add at least one finished stock item to the dispatch list.';
    } else {
        $db->beginTransaction();
        try {
            $validatedItems = [];
            $grandTotalPieces = 0;
            $grandTotalValue = 0.00;
            $affectedLots = [];

            // 1. Verify stock availability with lock for each item
            foreach ($rawItems as $row) {
                $stockId = (int)($row['stock_id'] ?? 0);
                $qty = (int)($row['qty'] ?? $row['dispatch_qty'] ?? 0);
                $rate = (float)($row['unit_rate'] ?? 0.00);

                if ($stockId <= 0 || $qty <= 0) {
                    continue;
                }

                $sStmt = $db->prepare("SELECT fg.*, p.name AS product_name, l.lot_number, l.total_pieces 
                                       FROM finished_goods_stock fg 
                                       JOIN products p ON fg.product_id = p.id 
                                       JOIN lots l ON fg.lot_id = l.id 
                                       WHERE fg.id = ? FOR UPDATE");
                $sStmt->execute([$stockId]);
                $item = $sStmt->fetch();

                if (!$item) {
                    throw new Exception("Finished stock item #$stockId not found.");
                }

                if ((int)$item['current_stock'] < $qty) {
                    throw new Exception("Insufficient stock for {$item['product_name']} (LOT #{$item['lot_number']})! Available: {$item['current_stock']} pcs, requested: $qty pcs.");
                }

                $lineTotal = round($qty * $rate, 2);
                $grandTotalPieces += $qty;
                $grandTotalValue += $lineTotal;

                $validatedItems[] = [
                    'stock_id' => $stockId,
                    'item' => $item,
                    'qty' => $qty,
                    'unit_rate' => $rate,
                    'total_amount' => $lineTotal
                ];

                $lotId = (int)$item['lot_id'];
                if (!isset($affectedLots[$lotId])) {
                    $affectedLots[$lotId] = [
                        'lot_id' => $lotId,
                        'lot_number' => $item['lot_number'],
                        'total_pieces' => (int)$item['total_pieces'],
                        'dispatched_now' => 0
                    ];
                }
                $affectedLots[$lotId]['dispatched_now'] += $qty;
            }

            if (empty($validatedItems)) {
                throw new Exception("No valid items with quantity greater than zero were submitted.");
            }

            $dispNum = generate_doc_number('DIS-', 'dispatches', 'dispatch_number');
            if (empty($challanNo)) {
                $challanNo = 'CHL-' . substr($dispNum, 4);
            }

            // Compute paid & balance amounts based on settlement mode
            if ($grandTotalValue <= 0) {
                $paymentStatus = 'Paid';
                $paidAmount = 0.00;
                $balanceAmount = 0.00;
            } elseif ($paymentStatus === 'Paid') {
                $paidAmount = $grandTotalValue;
                $balanceAmount = 0.00;
            } elseif ($paymentStatus === 'Credit') {
                $paidAmount = 0.00;
                $balanceAmount = $grandTotalValue;
            } else { // Partial
                $paidAmount = min($grandTotalValue, max(0.00, $paidAmount));
                $balanceAmount = round($grandTotalValue - $paidAmount, 2);
                if ($paidAmount >= $grandTotalValue) {
                    $paymentStatus = 'Paid';
                    $balanceAmount = 0.00;
                } elseif ($paidAmount <= 0) {
                    $paymentStatus = 'Credit';
                    $balanceAmount = $grandTotalValue;
                }
            }

            // 2. Insert Dispatch Header
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
                $grandTotalPieces,
                $grandTotalValue,
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

            // 3. Insert Dispatch Items, deduct stock and record inventory transaction
            $itemStmt = $db->prepare("INSERT INTO dispatch_items 
                (dispatch_id, lot_id, product_id, design_id, color_id, size_id, quantity, unit_rate, total_amount) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $upStock = $db->prepare("UPDATE finished_goods_stock 
                                    SET current_stock = current_stock - ?, dispatched_stock = dispatched_stock + ? 
                                    WHERE id = ?");
            $tx = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, product_id, design_id, color_id, size_id, lot_id, warehouse_id, transaction_type, quantity_out, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Finished Goods', ?, ?, ?, ?, ?, ?, 'Dispatch', ?, ?, 'DISPATCH', ?, ?, ?)");

            foreach ($validatedItems as $v) {
                $it = $v['item'];
                $itemStmt->execute([
                    $dispatchId,
                    $it['lot_id'],
                    $it['product_id'],
                    $it['design_id'],
                    $it['color_id'],
                    $it['size_id'],
                    $v['qty'],
                    $v['unit_rate'],
                    $v['total_amount']
                ]);

                $upStock->execute([$v['qty'], $v['qty'], $v['stock_id']]);

                $tx->execute([
                    $dispatchDate,
                    $it['product_id'],
                    $it['design_id'],
                    $it['color_id'],
                    $it['size_id'],
                    $it['lot_id'],
                    $warehouseId,
                    $v['qty'],
                    $v['unit_rate'],
                    $dispatchId,
                    "Dispatched {$v['qty']} pcs to Customer via Challan #$challanNo (LOT #{$it['lot_number']})",
                    $_SESSION['user_id'] ?? null
                ]);
            }

            // 4. Update status for all affected lots
            foreach ($affectedLots as $lId => $lInfo) {
                $totDispStmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM dispatch_items WHERE lot_id = ?");
                $totDispStmt->execute([$lId]);
                $lotTotalDispatched = (int)$totDispStmt->fetchColumn();

                $lotNewStatus = ($lotTotalDispatched >= $lInfo['total_pieces']) ? 'Dispatched' : 'Partially Dispatched';
                $db->prepare("UPDATE lots SET status = ? WHERE id = ?")->execute([$lotNewStatus, $lId]);
                $db->prepare("INSERT INTO lot_status_history (lot_id, old_status, new_status, remarks, changed_by) VALUES (?, NULL, ?, ?, ?)")
                   ->execute([$lId, $lotNewStatus, "Dispatched {$lInfo['dispatched_now']} pcs under Challan #$challanNo (Dispatch #$dispNum)", $_SESSION['user_id'] ?? null]);
            }

            // 5. Update Customer Ledger
            if ($grandTotalValue > 0) {
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

                $lotNums = array_map(function($l) { return 'LOT #' . $l['lot_number']; }, $affectedLots);
                $dispDesc = "Dispatch #$dispNum (Challan #$challanNo) - {$grandTotalPieces} pcs across " . implode(', ', $lotNums);

                // 5a. Record Dispatch Bill in Customer Ledger (Receivable Debit)
                $balAfterBill = round($lastCbal + $grandTotalValue, 2);
                $cLedg = $db->prepare("INSERT INTO customer_ledger 
                    (customer_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                    VALUES (?, ?, 'Dispatch', 'DISPATCH', ?, ?, 0.00, ?, ?, ?)");
                $cLedg->execute([
                    $customerId,
                    $dispatchDate,
                    $dispatchId,
                    $grandTotalValue,
                    $balAfterBill,
                    $dispDesc,
                    $_SESSION['user_id'] ?? null
                ]);

                // 5b. Record Immediate Payment / Advance Received (Receivable Credit)
                if ($paidAmount > 0) {
                    $balAfterPay = round($balAfterBill - $paidAmount, 2);
                    $payDesc = "Payment received on Dispatch #$dispNum (Challan #$challanNo) via $paymentMethod" . ($paymentRef ? " [Ref: $paymentRef]" : "");

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

            log_audit('CREATE_DISPATCH', 'dispatches', $dispatchId, null, [
                'customer_id' => $customerId,
                'lots_count' => count($affectedLots),
                'total_pieces' => $grandTotalPieces,
                'total_value' => $grandTotalValue,
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'balance_amount' => $balanceAmount,
                'challan_no' => $challanNo
            ]);

            $db->commit();
            redirect("modules/dispatch/challan.php?id=$dispatchId", "Dispatch #$dispNum created! Multi-lot stock deducted and Delivery Challan / Gate Pass generated.");
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to create dispatch: ' . $e->getMessage();
        }
    }
}

// Fetch available finished goods stock items across ALL active lots
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
    $sqlStock .= " ORDER BY (fg.lot_id = $preselectedLotId) DESC, l.id DESC, p.name ASC";
} else {
    $sqlStock .= " ORDER BY l.id DESC, p.name ASC";
}
$availableStocks = $db->query($sqlStock)->fetchAll();

// Fetch customers along with current ledger running balance
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
                <h4 class="fw-bold mb-1 text-dark">Create Customer Dispatch & Gate Pass</h4>
                <p class="text-muted small mb-0">Bundle and dispatch multiple items from different lots under a single Delivery Challan</p>
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

        <div class="card-modern" style="max-width: 1050px;">
            <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-truck me-1 text-primary"></i> Multi-Lot Dispatch & Delivery Challan Form
                    </h6>
                    <span class="text-muted small">Combine items across multiple lots into a single delivery challan</span>
                </div>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1">
                    <i class="bi bi-layers me-1"></i> Multi-Lot Dispatching Enabled
                </span>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="" id="formDispatch">
                    <input type="hidden" name="action" value="create_dispatch">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <!-- Header Details: Date, Customer, Challan, Warehouse -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-3">
                            <label class="form-label small fw-semibold text-muted">Dispatch Date *</label>
                            <input type="date" name="dispatch_date" class="form-control form-control-sm fw-bold" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label small fw-bold text-dark">Customer / Party *</label>
                            <select name="customer_id" id="dispatchCustomerId" class="form-select form-select-sm fw-bold" required>
                                <option value="" data-balance="0">-- Select Customer / Party --</option>
                                <?php foreach ($customers as $c): ?>
                                    <?php $bal = (float)$c['current_balance']; ?>
                                    <option value="<?= $c['id'] ?>" data-balance="<?= $bal ?>" <?= (isset($_POST['customer_id']) && (int)$_POST['customer_id'] === (int)$c['id']) ? 'selected' : '' ?>>
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
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-semibold text-muted">Delivery Challan / Ref #</label>
                            <input type="text" name="challan_no" class="form-control form-control-sm" placeholder="Leave empty for auto-generated CHL-XXXX" value="<?= clean($_POST['challan_no'] ?? '') ?>">
                        </div>
                    </div>

                    <!-- MULTI-LOT ITEM PICKER -->
                    <div class="card bg-light border p-3 mb-3 rounded-3 shadow-xs">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <span class="fw-bold text-dark small">
                                <i class="bi bi-box-seam text-primary me-1"></i> Select Finished Goods from Any Lot to Add
                            </span>
                            <span class="text-muted small" style="font-size: 11px;">
                                You can bundle items from any lot into a single dispatch
                            </span>
                        </div>
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-md-5">
                                <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 11px;">Available Finished Stock Item *</label>
                                <select id="selStockPicker" class="form-select form-select-sm fw-semibold">
                                    <option value="">-- Choose Lot / Product / Variant from Warehouse --</option>
                                    <?php foreach ($availableStocks as $stk): ?>
                                        <option value="<?= $stk['id'] ?>"
                                                data-stock-id="<?= $stk['id'] ?>"
                                                data-lot-id="<?= $stk['lot_id'] ?>"
                                                data-lot-number="<?= clean($stk['lot_number']) ?>"
                                                data-product-name="<?= clean($stk['product_name']) ?>"
                                                data-design="<?= clean($stk['design_number'] ?: 'Standard') ?>"
                                                data-color-name="<?= clean($stk['color_name']) ?>"
                                                data-size-name="<?= clean($stk['size_name']) ?>"
                                                data-size-code="<?= clean($stk['size_code']) ?>"
                                                data-max-stock="<?= $stk['current_stock'] ?>"
                                                data-warehouse="<?= clean($stk['warehouse_name']) ?>">
                                            LOT #<?= clean($stk['lot_number']) ?> — <?= clean($stk['product_name']) ?> (<?= $stk['design_number'] ? 'Des #' . $stk['design_number'] : 'Std' ?>) | <?= clean($stk['color_name']) ?> / <?= clean($stk['size_name']) ?> [Available: <?= $stk['current_stock'] ?> pcs]
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 11px;">Dispatch Qty *</label>
                                <input type="number" id="inpAddQty" class="form-control form-control-sm text-center fw-bold text-success" min="1" placeholder="Qty">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small fw-semibold text-muted mb-1" style="font-size: 11px;">Unit Sale Rate (Rs.)</label>
                                <input type="number" id="inpAddRate" class="form-control form-control-sm text-end fw-bold" step="1" min="0" placeholder="0.00">
                            </div>
                            <div class="col-12 col-md-3">
                                <button type="button" id="btnAddItemToList" class="btn btn-sm btn-primary w-100 fw-bold">
                                    <i class="bi bi-plus-circle me-1"></i> Add to Dispatch List
                                </button>
                            </div>
                        </div>
                        <div id="pickerMaxStockHint" class="form-text small mt-1 text-muted" style="font-size: 11px;"></div>
                    </div>

                    <!-- MULTI-LOT ITEMS TABLE -->
                    <div class="table-responsive border rounded-3 mb-3 bg-white">
                        <table class="table table-bordered table-hover align-middle mb-0" id="dispatchItemsTable">
                            <thead class="table-light small">
                                <tr>
                                    <th style="width: 40px;" class="text-center">#</th>
                                    <th style="width: 140px;">Lot Number</th>
                                    <th>Product & Design</th>
                                    <th style="width: 150px;">Color / Size</th>
                                    <th style="width: 90px;" class="text-center">Available</th>
                                    <th style="width: 120px;" class="text-center">Dispatch Qty *</th>
                                    <th style="width: 130px;" class="text-end">Unit Rate (Rs.)</th>
                                    <th style="width: 140px;" class="text-end">Line Total (Rs.)</th>
                                    <th style="width: 50px;" class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody id="dispatchItemsTbody">
                                <tr id="emptyItemsRow">
                                    <td colspan="9" class="text-center py-4 text-muted">
                                        <i class="bi bi-box-seam d-block fs-3 opacity-50 mb-1"></i>
                                        No items added to dispatch yet. Select finished stock above and click "Add to Dispatch List".
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="table-light fw-bold border-top-2">
                                <tr>
                                    <td colspan="5" class="text-end text-dark">Total Dispatched Items:</td>
                                    <td class="text-center fs-6 text-success" id="lblTableTotalPieces">0 pcs</td>
                                    <td class="text-end text-dark">Total Bill Value:</td>
                                    <td class="text-end fs-6 text-primary" id="lblTableTotalValue">Rs. 0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Customer Payment & Settlement Box -->
                    <div class="card border border-primary-subtle bg-light-subtle rounded-3 p-3 shadow-xs mb-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                            <div>
                                <span class="fw-bold text-dark fs-6">
                                    <i class="bi bi-wallet2 text-success me-1"></i> Customer Payment & Ledger Settlement
                                </span>
                                <span class="text-muted small ms-2 d-none d-md-inline">• Record cash receipt, advance payment, or credit settlement with invoice</span>
                            </div>
                            <div class="btn-group btn-group-sm" role="group">
                                <input type="radio" class="btn-check" name="payment_status" id="payStatusCredit" value="Credit" autocomplete="off" checked>
                                <label class="btn btn-outline-danger fw-bold px-3 py-1" for="payStatusCredit">
                                    <i class="bi bi-clock-history me-1"></i> Credit / Unpaid
                                </label>

                                <input type="radio" class="btn-check" name="payment_status" id="payStatusPartial" value="Partial" autocomplete="off">
                                <label class="btn btn-outline-warning fw-bold px-3 py-1 text-dark" for="payStatusPartial">
                                    <i class="bi bi-pie-chart-fill me-1"></i> Partial
                                </label>

                                <input type="radio" class="btn-check" name="payment_status" id="payStatusPaid" value="Paid" autocomplete="off">
                                <label class="btn btn-outline-success fw-bold px-3 py-1" for="payStatusPaid">
                                    <i class="bi bi-check-circle-fill me-1"></i> Full Paid
                                </label>
                            </div>
                        </div>

                        <div class="row g-3 align-items-center">
                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold text-muted mb-1">Paid / Advance</label>
                                <div class="input-group">
                                    <span class="input-group-text fw-bold text-success">Rs.</span>
                                    <input type="number" name="paid_amount" id="dispPaidAmount" class="form-control form-control-lg fw-bold text-end text-success fs-5" step="0.01" min="0" placeholder="0.00" value="0.00">
                                </div>
                            </div>

                            <div class="col-12 col-md-3">
                                <label class="form-label small fw-semibold text-muted mb-1">Bill Balance Due</label>
                                <div class="p-2 border rounded bg-white text-end fw-bold fs-5 text-danger" id="lblDispBalance">
                                    Rs. 0.00
                                </div>
                            </div>

                            <div class="col-12 col-md-3" id="payAccountWrapper">
                                <label class="form-label small fw-semibold text-muted mb-1">Deposit To</label>
                                <select name="account_id" id="dispAccountId" class="form-select form-select-sm fw-semibold">
                                    <option value="" data-type="cash">-- Direct Factory Cash Drawer --</option>
                                    <?php foreach ($accounts as $acc): 
                                        $isCash = (stripos($acc['account_name'], 'cash') !== false || stripos($acc['bank_name'] ?? '', 'cash') !== false || stripos($acc['bank_name'] ?? '', 'drawer') !== false);
                                        $accType = $isCash ? 'cash' : 'bank';
                                    ?>
                                        <option value="<?= $acc['id'] ?>" data-type="<?= $accType ?>">
                                            <?= clean($acc['account_name']) ?> (Bal: Rs. <?= number_format((float)$acc['current_balance'], 2) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-6 col-md-3" id="payMethodWrapper">
                                <label class="form-label small fw-semibold text-muted mb-1">Payment Method</label>
                                <select name="payment_method" id="dispPaymentMethod" class="form-select form-select-sm fw-semibold">
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer">Bank Transfer</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Online / Mobile">Online (JazzCash / EasyPaisa / Raast)</option>
                                    <?php foreach ($paymentMethods as $pm): ?>
                                        <?php if (!in_array($pm['name'], ['Cash', 'Bank Transfer', 'Cheque', 'Online / Mobile'])): ?>
                                            <option value="<?= clean($pm['name']) ?>"><?= clean($pm['name']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-6 col-md-3" id="payRefWrapper">
                                <label class="form-label small fw-semibold text-muted mb-1">Cheque / Trx #</label>
                                <input type="text" name="payment_reference" id="dispPaymentRef" class="form-control form-control-sm" placeholder="e.g. Chq# 4022 or Trx#">
                            </div>

                            <!-- Customer Financial Summary Pill Card -->
                            <div class="col-12">
                                <div class="p-3 border rounded-3 bg-white">
                                    <div class="row text-center g-2">
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted small d-block">Dispatch Total Bill</span>
                                            <span class="fw-bold text-dark fs-6" id="sumBillVal">Rs. 0.00</span>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted small d-block">Received Now</span>
                                            <span class="fw-bold text-success fs-6" id="sumPaidVal">Rs. 0.00</span>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted small d-block">Previous Balance</span>
                                            <span class="fw-bold fs-6" id="sumPrevBal">Rs. 0.00</span>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <span class="text-muted small d-block">Customer Net Closing</span>
                                            <span class="fw-bold fs-6" id="sumNetBal">Rs. 0.00</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div id="payStatusBanner" class="alert alert-secondary py-2 px-3 mb-0 small fw-semibold border">
                                    <i class="bi bi-info-circle me-1"></i> Select customer and items to calculate billing and ledger balance.
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold text-muted">Vehicle / Transport Details</label>
                            <input type="text" name="vehicle_details" class="form-control form-control-sm" placeholder="e.g. Suzuki Pickup / Driver Billa (0300-...)">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold text-muted">Warehouse Source</label>
                            <select name="warehouse_id" class="form-select form-select-sm">
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?= $wh['id'] ?>"><?= clean($wh['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold text-muted">Dispatch Notes / Gate Pass Instructions</label>
                            <input type="text" name="notes" class="form-control form-control-sm" placeholder="Carton count, transport freight terms, destination boutique or gate clearance notes">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                        <a href="<?= BASE_URL ?>modules/dispatch/index.php" class="btn btn-outline-secondary px-4">Cancel</a>
                        <button type="submit" id="btnSubmitDispatch" class="btn btn-primary px-5 fw-bold shadow-sm" disabled>
                            <i class="bi bi-printer me-1"></i> Generate Gate Pass & Delivery Challan
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const custSelect = document.getElementById('dispatchCustomerId');
    const custBalanceNotice = document.getElementById('custBalanceNotice');

    const selStockPicker = document.getElementById('selStockPicker');
    const inpAddQty = document.getElementById('inpAddQty');
    const inpAddRate = document.getElementById('inpAddRate');
    const btnAddItemToList = document.getElementById('btnAddItemToList');
    const pickerMaxStockHint = document.getElementById('pickerMaxStockHint');

    const dispatchItemsTbody = document.getElementById('dispatchItemsTbody');
    const emptyItemsRow = document.getElementById('emptyItemsRow');
    const lblTableTotalPieces = document.getElementById('lblTableTotalPieces');
    const lblTableTotalValue = document.getElementById('lblTableTotalValue');
    const btnSubmitDispatch = document.getElementById('btnSubmitDispatch');

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

    let rowIndexCounter = 0;

    function formatRs(num) {
        return 'Rs. ' + Number(num).toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    function getSelectedStatus() {
        if (payStatusPaid && payStatusPaid.checked) return 'Paid';
        if (payStatusPartial && payStatusPartial.checked) return 'Partial';
        return 'Credit';
    }

    // Update picker stock hint when a stock item is selected
    if (selStockPicker) {
        selStockPicker.addEventListener('change', function() {
            const opt = this.selectedOptions[0];
            if (opt && opt.dataset.maxStock) {
                const max = parseInt(opt.dataset.maxStock) || 0;
                pickerMaxStockHint.innerHTML = `<span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-box-seam me-1"></i> Available Stock: <strong>${max} pieces</strong> (Warehouse: ${opt.dataset.warehouse})</span>`;
                if (inpAddQty) {
                    inpAddQty.max = max;
                    if (!inpAddQty.value || parseInt(inpAddQty.value) <= 0) {
                        inpAddQty.value = max > 0 ? max : 1;
                    }
                }
            } else {
                pickerMaxStockHint.textContent = '';
            }
        });
    }

    // Add item to table
    if (btnAddItemToList) {
        btnAddItemToList.addEventListener('click', function() {
            const opt = selStockPicker.selectedOptions[0];
            if (!opt || !opt.value) {
                alert('Please select a lot or product from the stock list.');
                selStockPicker.focus();
                return;
            }

            const stockId = opt.value;
            const maxStock = parseInt(opt.dataset.maxStock) || 0;
            const qty = parseInt(inpAddQty.value) || 0;
            const rate = parseFloat(inpAddRate.value) || 0.00;

            if (qty <= 0) {
                alert('Please enter a valid dispatch quantity.');
                inpAddQty.focus();
                return;
            }

            if (qty > maxStock) {
                alert(`Quantity cannot exceed available stock (${maxStock} pcs).`);
                inpAddQty.value = maxStock;
                inpAddQty.focus();
                return;
            }

            // Check if stock item is already in table
            const existingRow = document.querySelector(`.dispatch-item-row[data-stock-id="${stockId}"]`);
            if (existingRow) {
                alert(`This item (LOT #${opt.dataset.lotNumber}) is already in the list. You can adjust its quantity directly in the table.`);
                const existingQtyInp = existingRow.querySelector('.item-qty-input');
                if (existingQtyInp) existingQtyInp.focus();
                return;
            }

            // Hide empty row
            if (emptyItemsRow) emptyItemsRow.classList.add('d-none');

            const idx = rowIndexCounter++;
            const lotNumber = opt.dataset.lotNumber || '';
            const productName = opt.dataset.productName || '';
            const design = opt.dataset.design || 'Standard';
            const colorName = opt.dataset.colorName || '';
            const sizeName = opt.dataset.sizeName || '';
            const sizeCode = opt.dataset.sizeCode || '';
            const lineTotal = Math.round(qty * rate * 100) / 100;

            const tr = document.createElement('tr');
            tr.className = 'dispatch-item-row';
            tr.dataset.stockId = stockId;
            tr.dataset.rowIndex = idx;

            tr.innerHTML = `
                <td class="text-center fw-bold text-muted row-number"></td>
                <td>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold fs-6">
                        LOT #${lotNumber}
                    </span>
                    <input type="hidden" name="items[${idx}][stock_id]" value="${stockId}">
                    <input type="hidden" name="items[${idx}][lot_id]" value="${opt.dataset.lotId}">
                </td>
                <td>
                    <div class="fw-bold text-dark">${productName}</div>
                    <div class="small text-muted" style="font-size: 11px;">Design: ${design}</div>
                </td>
                <td>
                    <span class="fw-semibold text-secondary">${colorName} / ${sizeName}</span>
                    <span class="badge bg-light text-muted border ms-1">${sizeCode}</span>
                </td>
                <td class="text-center text-muted fw-bold">
                    ${maxStock}
                </td>
                <td class="text-center">
                    <input type="number" name="items[${idx}][qty]" class="form-control form-control-sm text-center fw-bold text-success item-qty-input" min="1" max="${maxStock}" value="${qty}" required>
                </td>
                <td class="text-end">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text px-1 small">Rs</span>
                        <input type="number" name="items[${idx}][unit_rate]" class="form-control form-control-sm text-end fw-bold item-rate-input" step="1" min="0" value="${rate.toFixed(2)}">
                    </div>
                </td>
                <td class="text-end fw-bold text-primary item-line-total">
                    ${formatRs(lineTotal)}
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger p-1 btn-remove-row" title="Remove item">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;

            dispatchItemsTbody.appendChild(tr);

            // Re-bind row inputs
            const rowQtyInp = tr.querySelector('.item-qty-input');
            const rowRateInp = tr.querySelector('.item-rate-input');
            const btnRemove = tr.querySelector('.btn-remove-row');

            rowQtyInp.addEventListener('input', function() {
                const q = parseInt(this.value) || 0;
                if (q > maxStock) {
                    this.value = maxStock;
                }
                updateRowLineTotal(tr);
                calcFinancials(false);
            });

            rowRateInp.addEventListener('input', function() {
                updateRowLineTotal(tr);
                calcFinancials(false);
            });

            btnRemove.addEventListener('click', function() {
                tr.remove();
                renumberRows();
                calcFinancials(false);
            });

            renumberRows();
            calcFinancials(false);

            // Reset picker inputs
            inpAddQty.value = '';
            inpAddRate.value = '';
            selStockPicker.value = '';
            pickerMaxStockHint.textContent = '';
        });
    }

    function updateRowLineTotal(tr) {
        const q = parseInt(tr.querySelector('.item-qty-input')?.value) || 0;
        const r = parseFloat(tr.querySelector('.item-rate-input')?.value) || 0;
        const total = Math.round(q * r * 100) / 100;
        const lineTotalEl = tr.querySelector('.item-line-total');
        if (lineTotalEl) {
            lineTotalEl.textContent = formatRs(total);
        }
    }

    function renumberRows() {
        const rows = document.querySelectorAll('.dispatch-item-row');
        if (rows.length === 0) {
            if (emptyItemsRow) emptyItemsRow.classList.remove('d-none');
            if (btnSubmitDispatch) btnSubmitDispatch.disabled = true;
        } else {
            if (emptyItemsRow) emptyItemsRow.classList.add('d-none');
            if (btnSubmitDispatch) btnSubmitDispatch.disabled = false;
        }
        rows.forEach((row, i) => {
            const numCell = row.querySelector('.row-number');
            if (numCell) numCell.textContent = i + 1;
        });
    }

    function calcFinancials(fromUserTyping = false) {
        let totalPieces = 0;
        let totalBill = 0.00;

        document.querySelectorAll('.dispatch-item-row').forEach(row => {
            const q = parseInt(row.querySelector('.item-qty-input')?.value) || 0;
            const r = parseFloat(row.querySelector('.item-rate-input')?.value) || 0;
            const line = Math.round(q * r * 100) / 100;
            totalPieces += q;
            totalBill += line;
        });

        totalBill = Math.round(totalBill * 100) / 100;

        if (lblTableTotalPieces) lblTableTotalPieces.textContent = totalPieces.toLocaleString() + ' pcs';
        if (lblTableTotalValue) lblTableTotalValue.textContent = formatRs(totalBill);

        // Get customer previous balance
        const selectedCustOpt = custSelect ? custSelect.options[custSelect.selectedIndex] : null;
        const prevBal = (selectedCustOpt && selectedCustOpt.dataset.balance) ? parseFloat(selectedCustOpt.dataset.balance) : 0;

        if (selectedCustOpt && selectedCustOpt.value) {
            custBalanceNotice.style.display = 'block';
            if (prevBal > 0) {
                custBalanceNotice.innerHTML = `<span class="badge bg-danger-subtle text-danger border px-2 py-1"><i class="bi bi-clock-history me-1"></i> Previous Balance Due (Receivable): <strong>${formatRs(prevBal)}</strong></span>`;
            } else if (prevBal < 0) {
                custBalanceNotice.innerHTML = `<span class="badge bg-success-subtle text-success border px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> Previous Advance (Credit): <strong>${formatRs(Math.abs(prevBal))}</strong></span>`;
            } else {
                custBalanceNotice.innerHTML = `<span class="badge bg-light text-muted border px-2 py-1"><i class="bi bi-dash-circle me-1"></i> Previous Balance: Zero (Nil Balance)</span>`;
            }
        } else {
            custBalanceNotice.style.display = 'none';
        }

        const mode = getSelectedStatus();
        let paid = parseFloat(paidInput ? paidInput.value : 0) || 0;

        if (mode === 'Paid') {
            paid = totalBill;
            if (paidInput) {
                paidInput.value = totalBill.toFixed(2);
                paidInput.readOnly = true;
            }
        } else if (mode === 'Credit') {
            paid = 0;
            if (paidInput) {
                paidInput.value = '0.00';
                paidInput.readOnly = true;
            }
        } else { // Partial
            if (paidInput) {
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
        }

        const remainingBill = Math.max(0, Math.round((totalBill - paid) * 100) / 100);
        if (lblDispBalance) {
            lblDispBalance.textContent = formatRs(remainingBill);
            lblDispBalance.className = remainingBill > 0 
                ? 'p-2 border rounded bg-white text-end fw-bold fs-5 text-danger' 
                : 'p-2 border rounded bg-white text-end fw-bold fs-5 text-success';
        }

        // Net Customer Balance = Previous Balance + Total Bill - Paid
        const netBal = Math.round((prevBal + totalBill - paid) * 100) / 100;

        if (sumBillVal) sumBillVal.textContent = formatRs(totalBill);
        if (sumPaidVal) sumPaidVal.textContent = formatRs(paid);

        // Previous balance pill
        if (sumPrevBal) {
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
        }

        // Net balance pill
        if (sumNetBal) {
            if (netBal > 0) {
                sumNetBal.className = 'fw-bold fs-6 text-danger';
                sumNetBal.textContent = formatRs(netBal) + ' (Receivable / Receivable)';
            } else if (netBal < 0) {
                sumNetBal.className = 'fw-bold fs-6 text-success';
                sumNetBal.textContent = formatRs(Math.abs(netBal)) + ' (Advance / Customer Advance)';
            } else {
                sumNetBal.className = 'fw-bold fs-6 text-success';
                sumNetBal.textContent = 'Rs. 0.00 (Clear / Account Settled)';
            }
        }

        // Update status banner
        if (payStatusBanner) {
            if (mode === 'Paid') {
                payStatusBanner.className = 'alert alert-success py-2 px-3 mb-0 small fw-semibold border';
                payStatusBanner.innerHTML = `<i class="bi bi-check2-circle me-1"></i> <strong>Full Paid:</strong> Full invoice amount (${formatRs(totalBill)}) will be recorded as received in cash/bank with zero credit balance.`;
            } else if (mode === 'Credit') {
                payStatusBanner.className = 'alert alert-danger py-2 px-3 mb-0 small fw-semibold border';
                payStatusBanner.innerHTML = `<i class="bi bi-clock-history me-1"></i> <strong>Credit / Unpaid:</strong> Full invoice value (${formatRs(totalBill)}) will be debited to customer receivable ledger.`;
            } else {
                payStatusBanner.className = 'alert alert-warning py-2 px-3 mb-0 small fw-semibold border';
                payStatusBanner.innerHTML = `<i class="bi bi-pie-chart-fill me-1"></i> <strong>Partial / Advance:</strong> From invoice, <strong>${formatRs(paid)}</strong> received via Cash/Bank, and remaining <strong>${formatRs(remainingBill)}</strong> added to customer credit ledger.`;
            }
        }

        // Update submit button state
        const rowsCount = document.querySelectorAll('.dispatch-item-row').length;
        if (btnSubmitDispatch) {
            btnSubmitDispatch.disabled = (rowsCount === 0 || !custSelect || !custSelect.value);
        }
    }

    if (custSelect) {
        custSelect.addEventListener('change', function() {
            calcFinancials(false);
        });
    }

    [payStatusCredit, payStatusPartial, payStatusPaid].forEach(function(radio) {
        if (radio) {
            radio.addEventListener('change', function() {
                calcFinancials(false);
            });
        }
    });

    if (paidInput) {
        paidInput.addEventListener('input', function() {
            calcFinancials(true);
        });
    }

    renumberRows();
    calcFinancials(false);
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
