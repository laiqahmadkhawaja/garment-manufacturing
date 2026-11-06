<?php
/**
 * Garment Manufacturing ERP - Supplier Ledgers & Payment Statement
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Supplier Ledgers & Payments';
$activeNav = 'supplier_ledger';
$db = get_db();

// Handle Standalone Supplier Payment Disbursal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_supplier_payment') {
    check_csrf();
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $payDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $amount = (float)($_POST['amount'] ?? 0.00);
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $refNo = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($supplierId <= 0 || $amount <= 0) {
        set_flash('danger', 'Please choose a valid supplier and enter an amount greater than zero.');
    } else {
        $db->beginTransaction();
        try {
            // Verify supplier
            $sStmt = $db->prepare("SELECT name, opening_balance FROM suppliers WHERE id = ?");
            $sStmt->execute([$supplierId]);
            $supp = $sStmt->fetch();

            if (!$supp) {
                throw new Exception("Supplier not found.");
            }

            // Get current running balance
            $balStmt = $db->prepare("SELECT running_balance FROM supplier_ledger WHERE supplier_id = ? ORDER BY id DESC LIMIT 1");
            $balStmt->execute([$supplierId]);
            $prevBalRow = $balStmt->fetchColumn();
            $lastBal = ($prevBalRow !== false) ? (float)$prevBalRow : (float)$supp['opening_balance'];

            $newBal = round($lastBal - $amount, 2);
            $payDesc = "Payment disbursed via $paymentMethod" . ($refNo ? " [Ref: $refNo]" : "") . ($notes ? " - $notes" : "");

            $ins = $db->prepare("INSERT INTO supplier_ledger 
                (supplier_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                VALUES (?, ?, 'Payment', 'STANDALONE_PAYMENT', NULL, ?, 0.00, ?, ?, ?)");
            $ins->execute([$supplierId, $payDate, $amount, $newBal, $payDesc, $_SESSION['user_id'] ?? null]);
            $payEntryId = (int)$db->lastInsertId();

            // Deduct from Cash / Bank account if selected
            if ($accountId > 0) {
                $db->prepare("UPDATE cash_bank_accounts SET current_balance = current_balance - ? WHERE id = ?")
                   ->execute([$amount, $accountId]);
            }

            log_audit('SUPPLIER_PAYMENT', 'supplier_ledger', $payEntryId, null, [
                'supplier_id' => $supplierId,
                'amount' => $amount,
                'method' => $paymentMethod
            ]);

            $db->commit();
            redirect('modules/raw_materials/supplier_ledger.php?id=' . $supplierId, "Payment of Rs. " . number_format($amount, 2) . " disbursed to " . clean($supp['name']) . " successfully!");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error recording payment: ' . $e->getMessage());
        }
    }
}

$suppliersList = $db->query("SELECT id, name, code, phone, opening_balance FROM suppliers WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, bank_name, current_balance FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();
$methods = $db->query("SELECT id, name, code FROM payment_methods WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

$selectedSupplierId = (int)($_GET['id'] ?? 0);
$selectedSupplier = null;
$transactions = [];
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

if ($selectedSupplierId > 0) {
    $sQuery = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
    $sQuery->execute([$selectedSupplierId]);
    $selectedSupplier = $sQuery->fetch();

    if ($selectedSupplier) {
        $filterSql = "WHERE supplier_id = ?";
        $filterParams = [$selectedSupplierId];

        if (!empty($dateFrom)) {
            $filterSql .= " AND transaction_date >= ?";
            $filterParams[] = $dateFrom;
        }
        if (!empty($dateTo)) {
            $filterSql .= " AND transaction_date <= ?";
            $filterParams[] = $dateTo;
        }

        $txStmt = $db->prepare("SELECT * FROM supplier_ledger $filterSql ORDER BY transaction_date ASC, id ASC");
        $txStmt->execute($filterParams);
        $transactions = $txStmt->fetchAll();

        // Overall ledger metrics
        $sumStmt = $db->prepare("SELECT 
            COALESCE(SUM(debit), 0) AS total_paid,
            COALESCE(SUM(credit), 0) AS total_billed
            FROM supplier_ledger WHERE supplier_id = ?");
        $sumStmt->execute([$selectedSupplierId]);
        $sumRow = $sumStmt->fetch();
        $totalBilled = (float)$sumRow['total_billed'];
        $totalPaid = (float)$sumRow['total_paid'];
        $netPayable = round(((float)$selectedSupplier['opening_balance'] + $totalBilled) - $totalPaid, 2);
    }
}

// Summary overview for all suppliers if none selected
$allSuppliersSummary = [];
if (!$selectedSupplier) {
    $allSuppliersSummary = $db->query("SELECT s.*,
        COALESCE((SELECT SUM(credit) FROM supplier_ledger WHERE supplier_id = s.id), 0) AS total_billed,
        COALESCE((SELECT SUM(debit) FROM supplier_ledger WHERE supplier_id = s.id), 0) AS total_paid,
        (s.opening_balance + 
         COALESCE((SELECT SUM(credit) FROM supplier_ledger WHERE supplier_id = s.id), 0) - 
         COALESCE((SELECT SUM(debit) FROM supplier_ledger WHERE supplier_id = s.id), 0)) AS current_balance
        FROM suppliers s 
        WHERE s.is_active = 1 
        ORDER BY s.name ASC")->fetchAll();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

    <div class="app-content">
        <!-- Top Page Header -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Supplier Ledgers & Payments (سپلائر کھاتہ)</h4>
                <p class="text-muted small mb-0">Track raw material purchases, direct disbursements, outstanding payables & complete statements</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($selectedSupplier): ?>
                    <button type="button" class="btn btn-primary btn-sm fw-semibold shadow-sm" data-bs-toggle="modal" data-bs-target="#disbursePaymentModal">
                        <i class="bi bi-cash-stack me-1"></i> Disburse Payment (ادائیگی کریں)
                    </button>
                    <button type="button" onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-printer me-1"></i> Print Statement
                    </button>
                    <a href="<?= BASE_URL ?>modules/raw_materials/supplier_ledger.php" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-list-ul me-1"></i> All Suppliers
                    </a>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>modules/raw_materials/purchases.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-cart-plus me-1"></i> Stock In / Purchases
                </a>
            </div>
        </div>

        <?php if ($selectedSupplier): ?>
            <!-- Selected Supplier Ledger View -->
            <!-- Supplier Profile & KPI Cards -->
            <div class="row g-3 mb-4">
                <div class="col-12 col-lg-4">
                    <div class="card-modern h-100 p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <span class="badge bg-light text-primary border font-monospace mb-1"><?= clean($selectedSupplier['code']) ?></span>
                                <h5 class="fw-bold text-dark mb-0"><?= clean($selectedSupplier['name']) ?></h5>
                            </div>
                            <span class="badge bg-success-subtle text-success">Active Supplier</span>
                        </div>
                        <div class="small text-muted mb-2">
                            <div><i class="bi bi-telephone me-1"></i> <?= clean($selectedSupplier['phone'] ?: 'No phone') ?></div>
                            <div><i class="bi bi-envelope me-1"></i> <?= clean($selectedSupplier['email'] ?: 'No email') ?></div>
                            <div><i class="bi bi-geo-alt me-1"></i> <?= clean($selectedSupplier['address'] ?: 'Factory Mill / Supplier') ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-lg-8">
                    <div class="row g-3">
                        <div class="col-12 col-sm-4">
                            <div class="card-modern p-3 border-start border-primary border-4 text-center">
                                <span class="text-muted small fw-semibold">Total Purchases Billed</span>
                                <h4 class="fw-bold text-primary my-1"><?= format_currency($totalBilled) ?></h4>
                                <span class="badge bg-primary-subtle text-primary small">Payable Credit</span>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="card-modern p-3 border-start border-success border-4 text-center">
                                <span class="text-muted small fw-semibold">Total Disbursed / Paid</span>
                                <h4 class="fw-bold text-success my-1"><?= format_currency($totalPaid) ?></h4>
                                <span class="badge bg-success-subtle text-success small">Payments Debit</span>
                            </div>
                        </div>
                        <div class="col-12 col-sm-4">
                            <div class="card-modern p-3 border-start <?= $netPayable > 0 ? 'border-danger' : 'border-success' ?> border-4 text-center">
                                <span class="text-muted small fw-semibold">Net Outstanding Balance</span>
                                <h4 class="fw-bold <?= $netPayable > 0 ? 'text-danger' : 'text-success' ?> my-1"><?= format_currency($netPayable) ?></h4>
                                <span class="badge <?= $netPayable > 0 ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?> small">
                                    <?= $netPayable > 0 ? '⚠️ Outstanding Payable' : '✓ All Cleared' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Date Filter Bar -->
            <div class="card-modern mb-4 p-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <input type="hidden" name="id" value="<?= $selectedSupplierId ?>">
                    <div class="col-12 col-sm-auto">
                        <span class="fw-bold text-dark small"><i class="bi bi-filter me-1"></i> Filter Period:</span>
                    </div>
                    <div class="col-6 col-sm-3">
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= clean($dateFrom) ?>" placeholder="From Date">
                    </div>
                    <div class="col-6 col-sm-3">
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= clean($dateTo) ?>" placeholder="To Date">
                    </div>
                    <div class="col-12 col-sm-auto d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i> Filter</button>
                        <a href="<?= BASE_URL ?>modules/raw_materials/supplier_ledger.php?id=<?= $selectedSupplierId ?>" class="btn btn-light btn-sm border">Reset</a>
                    </div>
                </form>
            </div>

            <!-- Ledger Transactions Statement Table -->
            <div class="card-modern">
                <div class="card-modern-header d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-journal-text text-primary me-1"></i> Account Statement Transactions (<?= count($transactions) ?>)
                    </h6>
                    <span class="text-muted small">Opening Balance: <strong><?= format_currency((float)$selectedSupplier['opening_balance']) ?></strong></span>
                </div>
                <div class="card-modern-body p-0">
                    <div class="table-responsive">
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Reference</th>
                                    <th>Description / Bill Details</th>
                                    <th class="text-end">Billed / Credit (Rs.)</th>
                                    <th class="text-end">Paid / Debit (Rs.)</th>
                                    <th class="text-end">Running Balance (Rs.)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4 text-muted">
                                            No ledger transactions found for this supplier within selected period.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $seq = 1; 
                                    foreach ($transactions as $t): 
                                        $type = $t['transaction_type'];
                                        $credit = (float)$t['credit'];
                                        $debit = (float)$t['debit'];
                                        $runBal = (float)$t['running_balance'];
                                    ?>
                                        <tr>
                                            <td class="text-muted small"><?= $seq++ ?></td>
                                            <td><?= format_date($t['transaction_date']) ?></td>
                                            <td>
                                                <?php if ($type === 'Purchase'): ?>
                                                    <span class="badge bg-primary-subtle text-primary border">
                                                        <i class="bi bi-cart-check me-1"></i>Purchase
                                                    </span>
                                                <?php elseif ($type === 'Payment'): ?>
                                                    <span class="badge bg-success-subtle text-success border">
                                                        <i class="bi bi-cash-stack me-1"></i>Payment
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary-subtle text-secondary border"><?= clean($type) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="font-monospace small text-primary fw-semibold">
                                                <?= clean($t['reference_type'] ?: '-') ?> #<?= (int)$t['reference_id'] ?>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?= clean($t['description']) ?></div>
                                            </td>
                                            <td class="text-end fw-bold text-primary">
                                                <?= $credit > 0 ? number_format($credit, 2) : '—' ?>
                                            </td>
                                            <td class="text-end fw-bold text-success">
                                                <?= $debit > 0 ? number_format($debit, 2) : '—' ?>
                                            </td>
                                            <td class="text-end fw-bold <?= $runBal > 0 ? 'text-danger' : 'text-success' ?>">
                                                <?= number_format($runBal, 2) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Standalone Payment Modal -->
            <div class="modal fade" id="disbursePaymentModal" tabindex="-1" aria-labelledby="disbursePaymentModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="record_supplier_payment">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                            <input type="hidden" name="supplier_id" value="<?= $selectedSupplierId ?>">

                            <div class="modal-header bg-light">
                                <h6 class="modal-title fw-bold text-dark" id="disbursePaymentModalLabel">
                                    <i class="bi bi-cash-stack text-success me-1"></i> Disburse Payment to <?= clean($selectedSupplier['name']) ?>
                                </h6>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="alert alert-light border small text-muted mb-3">
                                    Current Outstanding Balance: <strong class="text-danger fs-6 ms-1">Rs. <?= number_format($netPayable, 2) ?></strong>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Payment Date *</label>
                                    <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Amount to Pay (Rs.) *</label>
                                    <div class="input-group">
                                        <span class="input-group-text fw-bold text-success">Rs.</span>
                                        <input type="number" name="amount" class="form-control form-control-lg fw-bold text-end text-success fs-4" step="0.01" min="0.01" max="<?= max(999999999, $netPayable) ?>" placeholder="0.00" value="<?= $netPayable > 0 ? $netPayable : '' ?>" required>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Payment Method</label>
                                    <select name="payment_method" class="form-select">
                                        <option value="Cash">Cash (نقد)</option>
                                        <option value="Bank Transfer">Bank Transfer (بینک ٹرانسفر)</option>
                                        <option value="Cheque">Cheque (بینک چیک)</option>
                                        <option value="Online / Mobile">Online (EasyPaisa / JazzCash)</option>
                                        <?php foreach ($methods as $m): ?>
                                            <?php if (!in_array($m['name'], ['Cash', 'Bank Transfer', 'Cheque', 'Online / Mobile'])): ?>
                                                <option value="<?= clean($m['name']) ?>"><?= clean($m['name']) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">From Account (کیش رجسٹر / بینک)</label>
                                    <select name="account_id" class="form-select">
                                        <option value="">-- Direct Factory Cash Drawer --</option>
                                        <?php foreach ($accounts as $acc): ?>
                                            <option value="<?= $acc['id'] ?>">
                                                <?= clean($acc['account_name']) ?> (Bal: Rs. <?= number_format((float)$acc['current_balance'], 2) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Reference / Cheque # (اختیاری)</label>
                                    <input type="text" name="reference_no" class="form-control" placeholder="e.g. Cheque 4091 or Trx ID">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Notes / Remarks</label>
                                    <input type="text" name="notes" class="form-control" placeholder="Optional settlement remarks">
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-success fw-bold px-4">
                                    <i class="bi bi-check-lg me-1"></i> Disburse Payment
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- All Suppliers Ledger Summary View -->
            <div class="card-modern">
                <div class="card-modern-header d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-people me-1 text-primary"></i> Factory Suppliers & Payable Ledger Directory
                    </h6>
                    <span class="badge bg-light text-muted border">Total: <?= count($allSuppliersSummary) ?> Suppliers</span>
                </div>
                <div class="card-modern-body p-0">
                    <div class="table-responsive">
                        <table class="table table-modern align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Supplier Code</th>
                                    <th>Supplier Name & Mill</th>
                                    <th>Contact</th>
                                    <th class="text-end">Opening Balance</th>
                                    <th class="text-end">Total Billed (Purchases)</th>
                                    <th class="text-end">Total Disbursed (Paid)</th>
                                    <th class="text-end">Net Outstanding Balance</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allSuppliersSummary)): ?>
                                    <tr><td colspan="8" class="text-center py-4 text-muted">No active suppliers found in system.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($allSuppliersSummary as $s): ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-light text-primary border font-monospace"><?= clean($s['code']) ?></span>
                                            </td>
                                            <td>
                                                <a href="<?= BASE_URL ?>modules/raw_materials/supplier_ledger.php?id=<?= $s['id'] ?>" class="fw-bold text-dark text-decoration-none">
                                                    <?= clean($s['name']) ?>
                                                </a>
                                            </td>
                                            <td>
                                                <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= clean($s['phone'] ?: '—') ?></div>
                                            </td>
                                            <td class="text-end fw-semibold text-muted">
                                                <?= format_currency((float)$s['opening_balance']) ?>
                                            </td>
                                            <td class="text-end fw-bold text-primary">
                                                <?= format_currency((float)$s['total_billed']) ?>
                                            </td>
                                            <td class="text-end fw-bold text-success">
                                                <?= format_currency((float)$s['total_paid']) ?>
                                            </td>
                                            <td class="text-end fw-bold <?= (float)$s['current_balance'] > 0 ? 'text-danger fs-6' : 'text-success' ?>">
                                                <?= format_currency((float)$s['current_balance']) ?>
                                            </td>
                                            <td class="text-center">
                                                <a href="<?= BASE_URL ?>modules/raw_materials/supplier_ledger.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary btn-sm py-1 px-3">
                                                    <i class="bi bi-journal-text me-1"></i> View Ledger
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
        <?php endif; ?>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
