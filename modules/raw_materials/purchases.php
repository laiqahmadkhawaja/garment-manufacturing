<?php
/**
 * Garment Manufacturing ERP - Raw Material Purchases & Yarn Color Integration
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Raw Material Purchases';
$activeNav = 'material_purchases';
$db = get_db();

// Handle Purchase Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_purchase') {
    check_csrf();
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $purchaseDate = trim($_POST['purchase_date'] ?? date('Y-m-d'));
    $invoiceNo = trim($_POST['invoice_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $materialId = (int)($_POST['material_id'] ?? 0);
    $quantity = (float)($_POST['quantity'] ?? 0.00);
    $unitPrice = (float)($_POST['unit_price'] ?? 0.00);

    // Payment Settlement Details
    $paymentStatus = in_array($_POST['payment_status'] ?? '', ['Paid', 'Partial', 'Credit']) ? $_POST['payment_status'] : 'Credit';
    $paidAmount = (float)($_POST['paid_amount'] ?? 0.00);
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $paymentRef = trim($_POST['payment_reference'] ?? '');

    // Color integration handling
    $colorMode = trim($_POST['color_mode'] ?? 'existing');
    $colorId = !empty($_POST['color_id']) ? (int)$_POST['color_id'] : null;
    $newColorCode = trim($_POST['new_color_code'] ?? '');
    $newColorName = trim($_POST['new_color_name'] ?? '');
    $newColorHex = trim($_POST['new_color_hex'] ?? '#1a1a1a');

    if ($supplierId <= 0 || $materialId <= 0 || $quantity <= 0) {
        set_flash('danger', 'Please choose a supplier, raw material, and valid quantity.');
    } else {
        $db->beginTransaction();
        try {
            // Check material details
            $matStmt = $db->prepare("SELECT m.name, m.code, m.category, m.color_id, u.code AS unit_code 
                                     FROM materials m 
                                     LEFT JOIN units u ON m.unit_id = u.id 
                                     WHERE m.id = ?");
            $matStmt->execute([$materialId]);
            $matInfo = $matStmt->fetch();
            $isYarn = ($matInfo && strcasecmp($matInfo['category'], 'Yarn') === 0);

            // If user requested to add a new color inline
            if ($colorMode === 'new' && (!empty($newColorCode) || !empty($newColorName))) {
                if (empty($newColorCode)) {
                    $baseCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $newColorName));
                    $newColorCode = !empty($baseCode) ? substr($baseCode, 0, 10) : 'COL';
                }
                if (empty($newColorName)) {
                    $newColorName = 'Shade ' . $newColorCode;
                }

                // Check if color already exists
                $chkCol = $db->prepare("SELECT id, name, color_code, yarn_stock_kg FROM colors WHERE LOWER(TRIM(name)) = LOWER(?) OR (color_code = ? AND color_code != '') LIMIT 1");
                $chkCol->execute([$newColorName, $newColorCode]);
                $existingCol = $chkCol->fetch();

                if ($existingCol) {
                    $colorId = (int)$existingCol['id'];
                    $upCol = $db->prepare("UPDATE colors SET yarn_stock_kg = yarn_stock_kg + ? WHERE id = ?");
                    $upCol->execute([$quantity, $colorId]);
                } else {
                    $insCol = $db->prepare("INSERT INTO colors (color_code, name, hex_code, yarn_stock_kg) VALUES (?, ?, ?, ?)");
                    $insCol->execute([$newColorCode, $newColorName, $newColorHex, $quantity]);
                    $colorId = (int)$db->lastInsertId();
                    log_audit('CREATE_COLOR_PURCHASE', 'colors', $colorId, null, [
                        'code' => $newColorCode, 
                        'name' => $newColorName, 
                        'stock' => $quantity
                    ]);
                }
            } elseif ($colorId > 0) {
                // Update existing color's yarn stock
                $upCol = $db->prepare("UPDATE colors SET yarn_stock_kg = yarn_stock_kg + ? WHERE id = ?");
                $upCol->execute([$quantity, $colorId]);
            }

            $totalAmount = round($quantity * $unitPrice, 2);
            $purNum = generate_doc_number('PUR-', 'material_purchases', 'purchase_number');

            // Compute paid & balance amounts based on settlement mode
            if ($paymentStatus === 'Paid') {
                $paidAmount = $totalAmount;
                $balanceAmount = 0.00;
            } elseif ($paymentStatus === 'Credit') {
                $paidAmount = 0.00;
                $balanceAmount = $totalAmount;
            } else { // Partial
                $paidAmount = min($totalAmount, max(0.00, $paidAmount));
                $balanceAmount = round($totalAmount - $paidAmount, 2);
                if ($paidAmount >= $totalAmount) {
                    $paymentStatus = 'Paid';
                    $balanceAmount = 0.00;
                } elseif ($paidAmount <= 0) {
                    $paymentStatus = 'Credit';
                    $balanceAmount = $totalAmount;
                }
            }

            $pStmt = $db->prepare("INSERT INTO material_purchases 
                (purchase_number, purchase_date, supplier_id, total_amount, payment_status, paid_amount, balance_amount, payment_method, account_id, payment_reference, invoice_no, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $pStmt->execute([
                $purNum, $purchaseDate, $supplierId, $totalAmount, 
                $paymentStatus, $paidAmount, $balanceAmount, 
                $paymentMethod, $accountId, $paymentRef, 
                $invoiceNo, $notes, $_SESSION['user_id'] ?? null
            ]);
            $purchaseId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO material_purchase_items (purchase_id, material_id, color_id, quantity, unit_price, total_price) 
                                      VALUES (?, ?, ?, ?, ?, ?)");
            $itemStmt->execute([$purchaseId, $materialId, $colorId, $quantity, $unitPrice, $totalAmount]);

            // Update Supplier Ledger automatically (prevents separate payment step)
            $suppBalStmt = $db->prepare("SELECT running_balance FROM supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 1");
            $suppBalStmt->execute([$supplierId]);
            $prevBalRow = $suppBalStmt->fetchColumn();
            $lastBal = ($prevBalRow !== false) ? (float)$prevBalRow : (float)$db->query("SELECT opening_balance FROM suppliers WHERE id = $supplierId")->fetchColumn();

            // 1. Record Purchase in Supplier Ledger (Payable Credit)
            $newBalAfterPur = round($lastBal + $totalAmount, 2);
            $matNameText = $matInfo['name'] ?? 'Material';
            $unitCodeText = $matInfo['unit_code'] ?? '';
            $purDesc = "Purchase Bill #$purNum - $matNameText ({$quantity} {$unitCodeText})" . ($invoiceNo ? " [Inv: $invoiceNo]" : "");

            $ledgPur = $db->prepare("INSERT INTO supplier_ledger 
                (supplier_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                VALUES (?, ?, 'Purchase', 'PURCHASE', ?, 0.00, ?, ?, ?, ?)");
            $ledgPur->execute([$supplierId, $purchaseDate, $purchaseId, $totalAmount, $newBalAfterPur, $purDesc, $_SESSION['user_id'] ?? null]);

            // 2. If Paid Amount > 0, Record Immediate Payment Disbursed (Payable Debit)
            if ($paidAmount > 0) {
                $newBalAfterPay = round($newBalAfterPur - $paidAmount, 2);
                $payDesc = "Payment for Purchase #$purNum via $paymentMethod" . ($paymentRef ? " [Ref: $paymentRef]" : "");

                $ledgPay = $db->prepare("INSERT INTO supplier_ledger 
                    (supplier_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                    VALUES (?, ?, 'Payment', 'PURCHASE_PAYMENT', ?, ?, 0.00, ?, ?, ?)");
                $ledgPay->execute([$supplierId, $purchaseDate, $purchaseId, $paidAmount, $newBalAfterPay, $payDesc, $_SESSION['user_id'] ?? null]);

                // Deduct from Cash / Bank account if account selected
                if ($accountId > 0) {
                    record_account_transaction(
                        $accountId,
                        $purchaseDate,
                        'Supplier Payment',
                        $paidAmount,
                        'outflow',
                        "Purchase payment for #$purNum" . ($paymentRef ? " [Ref: $paymentRef]" : ""),
                        'PURCHASE_PAYMENT',
                        $purchaseId,
                        $purNum,
                        null,
                        $_SESSION['user_id'] ?? null
                    );
                }
            }

            // If material has no linked color, but user selected a color for this Yarn, optionally link it
            if ($isYarn && empty($matInfo['color_id']) && $colorId > 0) {
                $db->prepare("UPDATE materials SET color_id = ? WHERE id = ?")->execute([$colorId, $materialId]);
            }

            // Increase raw material stock
            $curCostStmt = $db->prepare("SELECT cost_price FROM materials WHERE id = ?");
            $curCostStmt->execute([$materialId]);
            $existingCost = (float)$curCostStmt->fetchColumn();

            $up = $db->prepare("UPDATE materials SET current_stock = current_stock + ?, cost_price = ? WHERE id = ?");
            $up->execute([$quantity, $unitPrice > 0 ? $unitPrice : $existingCost, $materialId]);

            // Record in inventory_transactions
            $txRemarks = "Supplier Invoice: " . ($invoiceNo ?: 'Direct');
            if ($colorId > 0) {
                $cRow = $db->query("SELECT name, color_code FROM colors WHERE id = $colorId")->fetch();
                if ($cRow) {
                    $txRemarks .= " | Yarn Color: [{$cRow['color_code']}] {$cRow['name']}";
                }
            }
            $whId = get_default_warehouse_id('raw');
            $tx = $db->prepare("INSERT INTO inventory_transactions 
                (transaction_date, item_type, material_id, color_id, warehouse_id, transaction_type, quantity_in, unit_cost, reference_type, reference_id, remarks, created_by) 
                VALUES (?, 'Material', ?, ?, ?, 'Purchase', ?, ?, 'PURCHASE', ?, ?, ?)");
            $tx->execute([$purchaseDate, $materialId, $colorId, $whId, $quantity, $unitPrice, $purchaseId, $txRemarks, $_SESSION['user_id'] ?? null]);

            log_audit('PURCHASE_MATERIAL', 'material_purchases', $purchaseId, null, [
                'amount' => $totalAmount, 
                'paid' => $paidAmount,
                'status' => $paymentStatus,
                'quantity' => $quantity,
                'material_id' => $materialId,
                'color_id' => $colorId
            ]);
            $db->commit();

            $msgColorSuffix = '';
            if ($colorId > 0) {
                $cRow = $db->query("SELECT name, color_code, yarn_stock_kg FROM colors WHERE id = $colorId")->fetch();
                if ($cRow) {
                    $msgColorSuffix = " & Color [{$cRow['color_code']}] {$cRow['name']} stock updated (+{$quantity} KG → Total: {$cRow['yarn_stock_kg']} KG)";
                }
            }

            $payMsg = match($paymentStatus) {
                'Paid' => "✓ Full Paid (Rs. " . number_format($paidAmount, 2) . ")",
                'Partial' => "⚠️ Partial (Paid: Rs. " . number_format($paidAmount, 2) . ", Balance Due: Rs. " . number_format($balanceAmount, 2) . ")",
                'Credit' => "⏳ On Credit (Payable: Rs. " . number_format($balanceAmount, 2) . ")",
                default => ""
            };

            $successMsg = "Purchase #$purNum recorded successfully! Raw stock updated{$msgColorSuffix}. [Settlement: $payMsg] — Supplier ledger automatically updated!";

            $retLotId = (int)($_POST['lot_id'] ?? $_GET['lot_id'] ?? 0);
            if ($retLotId > 0) {
                redirect('modules/lots/view.php?id=' . $retLotId, $successMsg);
            } else {
                redirect('modules/raw_materials/purchases.php', $successMsg);
            }
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error recording purchase: ' . $e->getMessage());
        }
    }
}

// Purchases list with detailed material and color tracking
$purchases = $db->query("SELECT mp.*, s.name AS supplier_name, 
                         (SELECT COUNT(*) FROM material_purchase_items WHERE purchase_id = mp.id) AS items_count,
                         mpi.material_id, mpi.color_id, mpi.quantity, mpi.unit_price,
                         m.name AS material_name, m.code AS material_code, m.category AS material_category,
                         u.code AS unit_code,
                         c.name AS color_name, c.color_code, c.hex_code, c.yarn_stock_kg
                         FROM material_purchases mp 
                         JOIN suppliers s ON mp.supplier_id = s.id 
                         LEFT JOIN material_purchase_items mpi ON mpi.id = (SELECT MIN(id) FROM material_purchase_items WHERE purchase_id = mp.id)
                         LEFT JOIN materials m ON mpi.material_id = m.id 
                         LEFT JOIN units u ON m.unit_id = u.id 
                         LEFT JOIN colors c ON mpi.color_id = c.id
                         ORDER BY mp.id DESC")->fetchAll();

$suppliers = $db->query("SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$materials = $db->query("SELECT m.id, m.name, m.code, m.category, m.cost_price, m.color_id, u.code AS unit_code 
                         FROM materials m 
                         JOIN units u ON m.unit_id = u.id 
                         WHERE m.is_active = 1 ORDER BY m.category ASC, m.name ASC")->fetchAll();

$colors = $db->query("SELECT * FROM colors ORDER BY yarn_stock_kg DESC, name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, bank_name, current_balance FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();
$methods = $db->query("SELECT id, name, code FROM payment_methods WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

// Check if pre-selected via query string
$preColorId = (int)($_GET['color_id'] ?? 0);
$preMaterialId = (int)($_GET['material_id'] ?? 0);
if ($preColorId > 0 && $preMaterialId === 0) {
    // Attempt to locate a Yarn material linked to this color or first yarn material
    foreach ($materials as $m) {
        if (!empty($m['color_id']) && (int)$m['color_id'] === $preColorId) {
            $preMaterialId = (int)$m['id'];
            break;
        }
    }
    if ($preMaterialId === 0) {
        foreach ($materials as $m) {
            if (strcasecmp($m['category'], 'Yarn') === 0) {
                $preMaterialId = (int)$m['id'];
                break;
            }
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Material Purchases & Inward Goods</h4>
                <p class="text-muted small mb-0">Record raw yarn, fabric and accessories received with integrated Color & Yarn Stock sync</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="<?= BASE_URL ?>modules/raw_materials/supplier_ledger.php" class="btn btn-outline-warning btn-sm text-dark fw-semibold">
                    <i class="bi bi-journal-text me-1"></i> Supplier Ledgers (سپلائر کھاتہ)
                </a>
                <a href="<?= BASE_URL ?>modules/settings/products.php#variants" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-palette me-1"></i> Factory Colors
                </a>
                <a href="<?= BASE_URL ?>modules/raw_materials/index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-boxes me-1"></i> Stock Balances
                </a>
            </div>
        </div>

        <!-- Inward Purchase Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-cart-plus me-1 text-primary"></i> Record Supplier Inward Purchase
                </h6>
                <span class="badge bg-primary-subtle text-primary">Live Inventory Sync</span>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="" id="purchaseForm">
                    <input type="hidden" name="action" value="create_purchase">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" name="lot_id" value="<?= (int)($_GET['lot_id'] ?? 0) ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Purchase Date *</label>
                            <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Supplier / Mill *</label>
                            <select name="supplier_id" class="form-select" required>
                                <option value="">-- Choose Supplier --</option>
                                <?php foreach ($suppliers as $s): ?>
                                    <option value="<?= $s['id'] ?>"><?= clean($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Supplier Bill / Invoice #</label>
                            <input type="text" name="invoice_no" class="form-control" placeholder="e.g. INV-2026-9081">
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Raw Material *</label>
                            <select name="material_id" id="purMaterial" class="form-select" required>
                                <option value="">-- Choose Raw Material --</option>
                                <?php foreach ($materials as $m): ?>
                                    <option value="<?= $m['id'] ?>" 
                                            data-cost="<?= $m['cost_price'] ?>"
                                            data-category="<?= clean($m['category']) ?>"
                                            data-unit="<?= clean($m['unit_code']) ?>"
                                            data-color="<?= $m['color_id'] ?? '' ?>"
                                            <?= ($preMaterialId === (int)$m['id']) ? 'selected' : '' ?>>
                                        <?= clean($m['name']) ?> (<?= clean($m['code']) ?>) [<?= clean($m['category']) ?> - <?= clean($m['unit_code']) ?>]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Integrated Yarn Color Selection Box -->
                        <div class="col-12 col-md-8" id="yarnColorContainer">
                            <div class="p-2 px-3 border rounded-3 bg-light-subtle border-primary-subtle shadow-xs h-100">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                    <label class="form-label small fw-bold text-dark mb-0">
                                        <i class="bi bi-palette-fill text-primary me-1"></i> Color / Yarn Shade Integration (رنگ کا انتخاب)
                                    </label>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="color_mode" id="colModeExisting" value="existing" autocomplete="off" checked>
                                        <label class="btn btn-outline-primary py-0 px-2 small" for="colModeExisting"><i class="bi bi-list-check me-1"></i>Existing Color</label>

                                        <input type="radio" class="btn-check" name="color_mode" id="colModeNew" value="new" autocomplete="off">
                                        <label class="btn btn-outline-primary py-0 px-2 small" for="colModeNew"><i class="bi bi-plus-circle me-1"></i>+ New Color (نیا رنگ)</label>
                                    </div>
                                </div>

                                <!-- Mode 1: Select Existing Color -->
                                <div id="existingColorBox" class="row g-2 align-items-center">
                                    <div class="col-12 col-sm-8">
                                        <select name="color_id" id="purColorId" class="form-select form-select-sm fw-semibold">
                                            <option value="" data-hex="#cccccc" data-stock="0">-- Optional: Choose Yarn Color / Shade --</option>
                                            <?php foreach ($colors as $c): ?>
                                                <option value="<?= $c['id'] ?>" 
                                                        data-hex="<?= clean($c['hex_code'] ?: '#cccccc') ?>" 
                                                        data-code="<?= clean($c['color_code'] ?: '') ?>" 
                                                        data-stock="<?= (float)$c['yarn_stock_kg'] ?>"
                                                        <?= ($preColorId === (int)$c['id']) ? 'selected' : '' ?>>
                                                    [<?= clean($c['color_code'] ?: '-') ?>] <?= clean($c['name']) ?> (Live Stock: <?= (float)$c['yarn_stock_kg'] ?> KG)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-sm-4 d-flex align-items-center gap-2">
                                        <span id="colorSwatch" class="d-inline-block rounded-circle border shadow-xs" style="width: 24px; height: 24px; background-color: #cccccc;"></span>
                                        <span id="colorStockBadge" class="badge bg-light text-dark border small">No color</span>
                                    </div>
                                </div>

                                <!-- Mode 2: Add New Color Inline -->
                                <div id="newColorBox" class="row g-2 align-items-end d-none">
                                    <div class="col-12 col-sm-4">
                                        <label class="form-label text-muted small mb-1" style="font-size: 0.75rem;">Color Code *</label>
                                        <input type="text" name="new_color_code" id="newColorCode" class="form-control form-control-sm text-uppercase" placeholder="e.g. 1136">
                                    </div>
                                    <div class="col-12 col-sm-5">
                                        <label class="form-label text-muted small mb-1" style="font-size: 0.75rem;">Color Name *</label>
                                        <input type="text" name="new_color_name" id="newColorName" class="form-control form-control-sm" placeholder="e.g. Lilac Purple">
                                    </div>
                                    <div class="col-12 col-sm-3">
                                        <label class="form-label text-muted small mb-1" style="font-size: 0.75rem;">Shade Hex</label>
                                        <input type="color" name="new_color_hex" id="newColorHex" class="form-control form-control-sm form-control-color w-100" value="#2563eb">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <label class="form-label">Purchased Quantity * <span id="lblPurUnit" class="text-primary fw-bold"></span></label>
                            <input type="number" name="quantity" id="purQty" class="form-control fw-bold text-end" step="0.01" min="0.01" placeholder="0.00" required>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Unit Price (Rs.) *</label>
                            <input type="number" name="unit_price" id="purPrice" class="form-control text-end" step="0.01" min="0" value="0.00" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Total Bill Amount</label>
                            <div class="p-2 border rounded bg-light text-end fw-bold fs-6 text-success" id="lblPurTotal">
                                Rs. 0.00
                            </div>
                        </div>
                        <div class="col-12 col-md-3">
                            <label class="form-label">Notes / Batch Info</label>
                            <input type="text" name="notes" class="form-control" placeholder="Lot / yarn cone notes...">
                        </div>

                        <!-- Supplier Bill Payment Settlement Box -->
                        <div class="col-12">
                            <div class="card border border-primary-subtle bg-light-subtle rounded-3 p-3 shadow-xs">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                                    <div>
                                        <span class="fw-bold text-dark fs-6">
                                            <i class="bi bi-wallet2 text-success me-1"></i> Supplier Payment & Ledger Settlement (ادائیگی کی تفصیلات)
                                        </span>
                                        <span class="text-muted small ms-2 d-none d-md-inline">• بل کے وقت ہی ادھار یا ادائیگی درج کر کے الگ لیجر انٹری کے اضافی مرحلے سے بچیں</span>
                                    </div>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <input type="radio" class="btn-check" name="payment_status" id="payStatusPaid" value="Paid" autocomplete="off" checked>
                                        <label class="btn btn-outline-success fw-bold px-3 py-1" for="payStatusPaid">
                                            <i class="bi bi-check-circle-fill me-1"></i> Full Paid (مکمل نقد ادا)
                                        </label>

                                        <input type="radio" class="btn-check" name="payment_status" id="payStatusPartial" value="Partial" autocomplete="off">
                                        <label class="btn btn-outline-warning fw-bold px-3 py-1 text-dark" for="payStatusPartial">
                                            <i class="bi bi-pie-chart-fill me-1"></i> Partial (جزوی ادائیگی)
                                        </label>

                                        <input type="radio" class="btn-check" name="payment_status" id="payStatusCredit" value="Credit" autocomplete="off">
                                        <label class="btn btn-outline-danger fw-bold px-3 py-1" for="payStatusCredit">
                                            <i class="bi bi-clock-history me-1"></i> Credit / Unpaid (ادھار)
                                        </label>
                                    </div>
                                </div>

                                <div class="row g-3 align-items-center">
                                    <div class="col-12 col-md-3">
                                        <label class="form-label small fw-semibold text-muted mb-1">Paid Amount (ادا کردہ رقم) *</label>
                                        <div class="input-group">
                                            <span class="input-group-text fw-bold text-success">Rs.</span>
                                            <input type="number" name="paid_amount" id="purPaidAmount" class="form-control form-control-lg fw-bold text-end text-success fs-5" step="0.01" min="0" placeholder="0.00" value="0.00">
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-3">
                                        <label class="form-label small fw-semibold text-muted mb-1">Balance Due (باقی واجب الادا رقم)</label>
                                        <div class="p-2 border rounded bg-white text-end fw-bold fs-5 text-success" id="lblPurBalance">
                                            Rs. 0.00
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-3" id="payAccountWrapper">
                                        <label class="form-label small fw-semibold text-muted mb-1">Paid From (اکاؤنٹ / کیش رجسٹر)</label>
                                        <select name="account_id" id="purAccountId" class="form-select form-select-sm fw-semibold">
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
                                        <select name="payment_method" id="purPaymentMethod" class="form-select form-select-sm fw-semibold">
                                            <option value="Cash">Cash (نقد)</option>
                                            <option value="Bank Transfer">Bank Transfer (بینک)</option>
                                            <option value="Cheque">Cheque (چیک)</option>
                                            <option value="Online / Mobile">Online (EasyPaisa / JazzCash)</option>
                                            <?php foreach ($methods as $m): ?>
                                                <?php if (!in_array($m['name'], ['Cash', 'Bank Transfer', 'Cheque', 'Online / Mobile'])): ?>
                                                    <option value="<?= clean($m['name']) ?>"><?= clean($m['name']) ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-6 col-md-3" id="payRefWrapper">
                                        <label class="form-label small fw-semibold text-muted mb-1">Cheque / Trx # (اختیاری)</label>
                                        <input type="text" name="payment_reference" id="purPaymentRef" class="form-control form-control-sm" placeholder="e.g. Chq# 8011 or Trx#">
                                    </div>

                                    <div class="col-12 col-md-9">
                                        <div id="payStatusBanner" class="alert alert-success py-2 px-3 mb-0 small fw-semibold border">
                                            <i class="bi bi-check2-circle me-1"></i> <strong>Full Paid:</strong> پورا بل ادا شدہ سمجھا جائے گا اور سپلائر کھاتہ فوراً بیلنس ہو جائے گا (کوئی بقایا ادھار نہیں بچے گا)۔
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-2 border-top">
                            <button type="submit" class="btn btn-primary px-5 fw-bold shadow-sm">
                                <i class="bi bi-check-lg me-1"></i> Save Inward Stock & Settle Payment
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Purchases Log -->
        <div class="card-modern">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0 text-dark">Purchases History (<?= count($purchases) ?>)</h6>
                <span class="text-muted small">Live Inward Goods Ledger</span>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Purchase #</th>
                                <th>Date</th>
                                <th>Supplier & Bill</th>
                                <th>Raw Material</th>
                                <th>Color / Shade</th>
                                <th class="text-end">Qty & Unit Rate</th>
                                <th class="text-end">Total Amount</th>
                                <th class="text-center">Payment Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($purchases)): ?>
                                <tr><td colspan="8" class="text-center py-4 text-muted">No purchase records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($purchases as $p): ?>
                                    <tr>
                                        <td class="fw-bold text-primary">
                                            <?= clean($p['purchase_number']) ?>
                                        </td>
                                        <td><?= format_date($p['purchase_date']) ?></td>
                                        <td>
                                            <div class="fw-semibold text-dark">
                                                <a href="<?= BASE_URL ?>modules/raw_materials/supplier_ledger.php?id=<?= $p['supplier_id'] ?>" class="text-dark text-decoration-none">
                                                    <?= clean($p['supplier_name']) ?> <i class="bi bi-arrow-up-right-square small text-primary ms-1" title="View Supplier Ledger"></i>
                                                </a>
                                            </div>
                                            <div class="text-muted small">Bill: <?= clean($p['invoice_no'] ?: '-') ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-dark"><?= clean($p['material_name'] ?: 'Material #' . $p['material_id']) ?></div>
                                            <span class="badge bg-light text-dark border small"><?= clean($p['material_category'] ?: 'Raw') ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($p['color_name'])): ?>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="d-inline-block rounded-circle border shadow-xs" style="width: 18px; height: 18px; background-color: <?= clean($p['hex_code'] ?: '#ccc') ?>;"></span>
                                                    <div>
                                                        <span class="badge bg-light text-dark border font-monospace">[<?= clean($p['color_code'] ?: '-') ?>]</span>
                                                        <span class="fw-semibold text-dark small"><?= clean($p['color_name']) ?></span>
                                                    </div>
                                                </div>
                                                <div class="small text-success mt-1">
                                                    <i class="bi bi-arrow-up-circle me-1"></i>+<?= number_format((float)$p['quantity'], 2) ?> KG Yarn Stock
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="fw-bold text-dark"><?= number_format((float)$p['quantity'], 2) ?> <?= clean($p['unit_code'] ?: '') ?></div>
                                            <div class="text-muted small">@ Rs. <?= number_format((float)$p['unit_price'], 2) ?></div>
                                        </td>
                                        <td class="text-end fw-bold text-success fs-6">
                                            <?= format_currency($p['total_amount']) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php
                                            $pStatus = $p['payment_status'] ?? 'Credit';
                                            $paidAmt = (float)($p['paid_amount'] ?? 0);
                                            $balAmt = (float)($p['balance_amount'] ?? $p['total_amount']);
                                            ?>
                                            <?php if ($pStatus === 'Paid'): ?>
                                                <span class="badge bg-success-subtle text-success border">
                                                    <i class="bi bi-check2-circle me-1"></i>Full Paid
                                                </span>
                                                <div class="small text-muted mt-1">Rs. <?= number_format($paidAmt, 2) ?> via <?= clean($p['payment_method'] ?: 'Cash') ?></div>
                                            <?php elseif ($pStatus === 'Partial'): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border">
                                                    <i class="bi bi-pie-chart-fill me-1"></i>Partial
                                                </span>
                                                <div class="small text-muted mt-1">Paid: Rs. <?= number_format($paidAmt, 2) ?></div>
                                                <div class="small text-danger fw-bold">Due: Rs. <?= number_format($balAmt, 2) ?></div>
                                            <?php else: ?>
                                                <span class="badge bg-danger-subtle text-danger border">
                                                    <i class="bi bi-clock-history me-1"></i>Credit (Unpaid)
                                                </span>
                                                <div class="small text-danger fw-bold mt-1">Due: Rs. <?= number_format($balAmt, 2) ?></div>
                                            <?php endif; ?>
                                            <div class="mt-1">
                                                <a href="<?= BASE_URL ?>modules/raw_materials/supplier_ledger.php?id=<?= $p['supplier_id'] ?>" class="text-primary text-decoration-none small" style="font-size: 11px;">
                                                    <i class="bi bi-journal-text me-1"></i>View Ledger
                                                </a>
                                            </div>
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
    const matSelect = document.getElementById('purMaterial');
    const colorSelect = document.getElementById('purColorId');
    const colorSwatch = document.getElementById('colorSwatch');
    const colorStockBadge = document.getElementById('colorStockBadge');
    const qtyInput = document.getElementById('purQty');
    const priceInput = document.getElementById('purPrice');
    const totalDisplay = document.getElementById('lblPurTotal');
    const lblUnit = document.getElementById('lblPurUnit');

    const colModeExisting = document.getElementById('colModeExisting');
    const colModeNew = document.getElementById('colModeNew');
    const existingColorBox = document.getElementById('existingColorBox');
    const newColorBox = document.getElementById('newColorBox');

    const payStatusPaid = document.getElementById('payStatusPaid');
    const payStatusPartial = document.getElementById('payStatusPartial');
    const payStatusCredit = document.getElementById('payStatusCredit');
    const purPaidAmount = document.getElementById('purPaidAmount');
    const lblPurBalance = document.getElementById('lblPurBalance');
    const payAccountWrapper = document.getElementById('payAccountWrapper');
    const payMethodWrapper = document.getElementById('payMethodWrapper');
    const payRefWrapper = document.getElementById('payRefWrapper');
    const payStatusBanner = document.getElementById('payStatusBanner');

    function syncPaymentFields(isFromManualPaidInput = false) {
        const q = parseFloat(qtyInput.value) || 0;
        const p = parseFloat(priceInput.value) || 0;
        const total = q * p;

        if (payStatusPaid.checked) {
            purPaidAmount.value = total.toFixed(2);
            purPaidAmount.readOnly = true;
            lblPurBalance.textContent = 'Rs. 0.00';
            lblPurBalance.className = 'p-2 border rounded bg-white text-end fw-bold fs-5 text-success';
            if (payAccountWrapper) payAccountWrapper.style.opacity = '1';
            if (payMethodWrapper) payMethodWrapper.style.opacity = '1';
            if (payRefWrapper) payRefWrapper.style.opacity = '1';
            payStatusBanner.className = 'alert alert-success py-2 px-3 mb-0 small fw-semibold border';
            payStatusBanner.innerHTML = '<i class="bi bi-check2-circle me-1"></i> <strong>Full Paid:</strong> پورا بل ادا شدہ سمجھا جائے گا اور سپلائر کھاتہ فوراً بیلنس ہو جائے گا (کوئی بقایا ادھار نہیں بچے گا)۔';
        } else if (payStatusCredit.checked) {
            purPaidAmount.value = '0.00';
            purPaidAmount.readOnly = true;
            lblPurBalance.textContent = 'Rs. ' + total.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            lblPurBalance.className = 'p-2 border rounded bg-white text-end fw-bold fs-5 text-danger';
            if (payAccountWrapper) payAccountWrapper.style.opacity = '0.5';
            if (payMethodWrapper) payMethodWrapper.style.opacity = '0.5';
            if (payRefWrapper) payRefWrapper.style.opacity = '0.5';
            payStatusBanner.className = 'alert alert-danger py-2 px-3 mb-0 small fw-semibold border';
            payStatusBanner.innerHTML = '<i class="bi bi-clock-history me-1"></i> <strong>On Credit (ادھار):</strong> مکمل رقم سپلائر کے کھاتے میں بطور ادھار (واجب الادا) درج ہو جائے گی اور بعد میں ادا کی جا سکے گی۔';
        } else { // Partial
            purPaidAmount.readOnly = false;
            let paid = parseFloat(purPaidAmount.value) || 0;
            if (!isFromManualPaidInput && (paid === 0 || paid >= total)) {
                paid = Math.round(total / 2);
                purPaidAmount.value = paid.toFixed(2);
            }
            if (paid > total) {
                paid = total;
                purPaidAmount.value = paid.toFixed(2);
            }
            const bal = Math.max(0, total - paid);
            lblPurBalance.textContent = 'Rs. ' + bal.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            lblPurBalance.className = 'p-2 border rounded bg-white text-end fw-bold fs-5 ' + (bal > 0 ? 'text-warning-emphasis' : 'text-success');
            if (payAccountWrapper) payAccountWrapper.style.opacity = '1';
            if (payMethodWrapper) payMethodWrapper.style.opacity = '1';
            if (payRefWrapper) payRefWrapper.style.opacity = '1';
            payStatusBanner.className = 'alert alert-warning py-2 px-3 mb-0 small fw-semibold border';
            payStatusBanner.innerHTML = '<i class="bi bi-pie-chart-fill me-1"></i> <strong>Partial Payment:</strong> ادا کردہ رقم سپلائر کھاتے میں کریڈٹ کٹ جائے گی اور بقیہ رقم (Rs. ' + bal.toFixed(2) + ') سپلائر کے ادھار واجبات میں رہے گی۔';
        }
    }

    function calcTotal() {
        const q = parseFloat(qtyInput.value) || 0;
        const p = parseFloat(priceInput.value) || 0;
        const tot = q * p;
        totalDisplay.textContent = 'Rs. ' + tot.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        syncPaymentFields();
    }

    function updateColorPreview() {
        const opt = colorSelect.options[colorSelect.selectedIndex];
        if (opt && opt.value) {
            const hex = opt.dataset.hex || '#cccccc';
            const stock = parseFloat(opt.dataset.stock) || 0;
            colorSwatch.style.backgroundColor = hex;
            colorStockBadge.className = 'badge ' + (stock > 0 ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning') + ' border small';
            colorStockBadge.textContent = 'Stock: ' + stock.toFixed(2) + ' KG';
        } else {
            colorSwatch.style.backgroundColor = '#cccccc';
            colorStockBadge.className = 'badge bg-light text-dark border small';
            colorStockBadge.textContent = 'No color';
        }
    }

    function handleMaterialChange() {
        const opt = matSelect.options[matSelect.selectedIndex];
        if (opt) {
            if (opt.dataset.cost && parseFloat(opt.dataset.cost) > 0 && (!priceInput.value || parseFloat(priceInput.value) === 0)) {
                priceInput.value = opt.dataset.cost;
            }
            if (opt.dataset.unit) {
                lblUnit.textContent = '(' + opt.dataset.unit + ')';
            } else {
                lblUnit.textContent = '';
            }
            if (opt.dataset.color && colModeExisting.checked) {
                colorSelect.value = opt.dataset.color;
                updateColorPreview();
            }
            calcTotal();
        }
    }

    colModeExisting.addEventListener('change', function() {
        if (this.checked) {
            existingColorBox.classList.remove('d-none');
            newColorBox.classList.add('d-none');
        }
    });

    colModeNew.addEventListener('change', function() {
        if (this.checked) {
            existingColorBox.classList.add('d-none');
            newColorBox.classList.remove('d-none');
            document.getElementById('newColorCode').focus();
        }
    });

    matSelect.addEventListener('change', handleMaterialChange);
    colorSelect.addEventListener('change', updateColorPreview);
    qtyInput.addEventListener('input', calcTotal);
    priceInput.addEventListener('input', calcTotal);

    if (payStatusPaid) payStatusPaid.addEventListener('change', () => syncPaymentFields(false));
    if (payStatusPartial) payStatusPartial.addEventListener('change', () => syncPaymentFields(false));
    if (payStatusCredit) payStatusCredit.addEventListener('change', () => syncPaymentFields(false));
    if (purPaidAmount) purPaidAmount.addEventListener('input', () => syncPaymentFields(true));

    // Initial triggers
    handleMaterialChange();
    updateColorPreview();
    syncPaymentFields();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
