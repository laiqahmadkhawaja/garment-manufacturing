<?php
/**
 * Garment Manufacturing ERP - Customer Account Profile & Running Ledger
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$customerId = (int)($_GET['id'] ?? 0);
$db = get_db();

// Fetch Customer
$stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
$customer = $stmt->fetch();

if (!$customer) {
    redirect('modules/customers/index.php', 'Customer party not found.', 'danger');
}

$pageTitle = $customer['name'] . ' - Customer Ledger & Profile';
$activeNav = 'customers';

// Handle Customer Payment Collection
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'receive_payment') {
    $payDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $amount = (float)($_POST['amount'] ?? 0);
    $method = trim($_POST['payment_method'] ?? 'Cash');
    $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $refNo = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0) {
        set_flash('danger', 'Payment amount must be greater than zero.');
    } else {
        $db->beginTransaction();
        try {
            // Get latest running balance
            $balStmt = $db->prepare("SELECT running_balance FROM customer_ledger WHERE customer_id = ? ORDER BY id DESC LIMIT 1");
            $balStmt->execute([$customerId]);
            $lastBal = $balStmt->fetchColumn();

            if ($lastBal === false) {
                $lastBal = (float)$customer['opening_balance'];
            } else {
                $lastBal = (float)$lastBal;
            }

            // Payment reduces customer debt (credit)
            $newBal = $lastBal - $amount;

            $desc = "Payment received via $method";
            if (!empty($refNo)) {
                $desc .= " (Ref/Cheque: $refNo)";
            }
            if (!empty($notes)) {
                $desc .= " - $notes";
            }

            // Insert into customer_ledger
            $ledgStmt = $db->prepare("INSERT INTO customer_ledger 
                (customer_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                VALUES (?, ?, 'Payment', 'PAYMENT_RECEIPT', NULL, 0.00, ?, ?, ?, ?)");
            $ledgStmt->execute([
                $customerId,
                $payDate,
                $amount,
                $newBal,
                $desc,
                $_SESSION['user_id'] ?? null
            ]);
            $receiptId = (int)$db->lastInsertId();

            // Update cash/bank balance if account selected
            if ($accountId > 0) {
                $db->prepare("UPDATE cash_bank_accounts SET current_balance = current_balance + ? WHERE id = ?")
                   ->execute([$amount, $accountId]);
            }

            log_audit('RECEIVE_CUSTOMER_PAYMENT', 'customer_ledger', $receiptId, null, [
                'customer_id' => $customerId,
                'amount' => $amount,
                'method' => $method,
                'new_balance' => $newBal
            ]);

            $db->commit();
            redirect("modules/customers/view.php?id=$customerId", "Payment of " . format_currency($amount) . " received and credited successfully!");
        } catch (Exception $e) {
            $db->rollBack();
            set_flash('danger', 'Error processing payment: ' . $e->getMessage());
        }
    }
}

// Date filters
$fromDate = trim($_GET['from_date'] ?? '');
$toDate = trim($_GET['to_date'] ?? '');

$filterSql = "WHERE customer_id = ?";
$filterParams = [$customerId];

if (!empty($fromDate)) {
    $filterSql .= " AND transaction_date >= ?";
    $filterParams[] = $fromDate;
}
if (!empty($toDate)) {
    $filterSql .= " AND transaction_date <= ?";
    $filterParams[] = $toDate;
}

// Fetch Full Running Ledger
$ledgSql = "SELECT * FROM customer_ledger $filterSql ORDER BY transaction_date ASC, id ASC";
$ledgStmt = $db->prepare($ledgSql);
$ledgStmt->execute($filterParams);
$ledgerEntries = $ledgStmt->fetchAll();

// Overall Totals (Unfiltered)
$totalsStmt = $db->prepare("SELECT 
    COALESCE(SUM(debit), 0) AS total_debit,
    COALESCE(SUM(credit), 0) AS total_credit,
    COALESCE(SUM(CASE WHEN transaction_type = 'Dispatch' THEN debit ELSE 0 END), 0) AS total_dispatched,
    COALESCE(SUM(CASE WHEN transaction_type = 'Payment' THEN credit ELSE 0 END), 0) AS total_paid,
    COALESCE(SUM(CASE WHEN transaction_type = 'Return' THEN credit ELSE 0 END), 0) AS total_returned
    FROM customer_ledger WHERE customer_id = ?");
$totalsStmt->execute([$customerId]);
$totals = $totalsStmt->fetch();

$totalDebit = (float)$totals['total_debit'];
$totalCredit = (float)$totals['total_credit'];
$netBalance = $totalDebit - $totalCredit;

// Dispatches history
$dispStmt = $db->prepare("SELECT d.*, wh.name AS warehouse_name,
       (SELECT GROUP_CONCAT(DISTINCT l.lot_number SEPARATOR ', ') 
        FROM dispatch_items di JOIN lots l ON di.lot_id = l.id 
        WHERE di.dispatch_id = d.id) AS lot_numbers,
       (SELECT GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') 
        FROM dispatch_items di JOIN products p ON di.product_id = p.id 
        WHERE di.dispatch_id = d.id) AS product_names
FROM dispatches d
JOIN warehouses wh ON d.warehouse_id = wh.id
WHERE d.customer_id = ?
ORDER BY d.dispatch_date DESC, d.id DESC");
$dispStmt->execute([$customerId]);
$dispatches = $dispStmt->fetchAll();

// Returns history
$retStmt = $db->prepare("SELECT dr.*, d.dispatch_number,
       COALESCE((SELECT SUM(quantity) FROM dispatch_return_items WHERE return_id = dr.id), 0) AS total_pieces,
       COALESCE((SELECT SUM(total_amount) FROM dispatch_return_items WHERE return_id = dr.id), 0) AS credit_amount,
       (SELECT GROUP_CONCAT(DISTINCT l.lot_number SEPARATOR ', ') 
        FROM dispatch_return_items dri JOIN lots l ON dri.lot_id = l.id 
        WHERE dri.return_id = dr.id) AS lot_numbers,
       (SELECT GROUP_CONCAT(DISTINCT p.name SEPARATOR ', ') 
        FROM dispatch_return_items dri JOIN products p ON dri.product_id = p.id 
        WHERE dri.return_id = dr.id) AS product_names
FROM dispatch_returns dr
JOIN dispatches d ON dr.dispatch_id = d.id
WHERE dr.customer_id = ?
ORDER BY dr.return_date DESC, dr.id DESC");
$retStmt->execute([$customerId]);
$returns = $retStmt->fetchAll();

// Cash/Bank Accounts for modal
$accounts = $db->query("SELECT * FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Customer Profile Header Card -->
        <div class="card-modern mb-4">
            <div class="card-modern-body p-4">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 pb-3 border-bottom mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-3 shadow-sm" style="width: 60px; height: 60px;">
                            <?= strtoupper(substr($customer['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <h4 class="fw-bold text-dark mb-0"><?= clean($customer['name']) ?></h4>
                                <span class="badge bg-primary-subtle text-primary border fw-bold"><?= clean($customer['code']) ?></span>
                                <span class="badge bg-light text-secondary border"><?= clean($customer['city'] ?: 'Local') ?></span>
                            </div>
                            <div class="text-muted small mt-1">
                                <?php if ($customer['contact_person']): ?>
                                    <span class="me-3"><i class="bi bi-person me-1"></i><?= clean($customer['contact_person']) ?></span>
                                <?php endif; ?>
                                <?php if ($customer['phone']): ?>
                                    <span class="me-3"><i class="bi bi-telephone me-1"></i><?= clean($customer['phone']) ?></span>
                                <?php endif; ?>
                                <?php if ($customer['address']): ?>
                                    <span><i class="bi bi-geo-alt me-1"></i><?= clean($customer['address']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="button" class="btn btn-success fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#paymentModal">
                            <i class="bi bi-cash-stack me-1"></i> Receive Payment
                        </button>
                        <a href="<?= BASE_URL ?>modules/dispatch/create.php?customer_id=<?= $customer['id'] ?>" class="btn btn-primary fw-semibold shadow-sm">
                            <i class="bi bi-truck me-1"></i> New Dispatch
                        </a>
                        <a href="<?= BASE_URL ?>modules/customers/statement.php?id=<?= $customer['id'] ?>" target="_blank" class="btn btn-outline-secondary fw-semibold">
                            <i class="bi bi-printer me-1"></i> Print Statement
                        </a>
                        <a href="<?= BASE_URL ?>modules/customers/index.php" class="btn btn-light border text-muted">
                            <i class="bi bi-arrow-left me-1"></i> Directory
                        </a>
                    </div>
                </div>

                <!-- 4 KPI Metrics -->
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-light border rounded-3 text-center text-md-start">
                            <span class="text-muted small text-uppercase fw-semibold" style="font-size: 0.72rem;">Total Dispatched (Billed)</span>
                            <div class="fs-4 fw-bold text-dark mt-1"><?= format_currency($totals['total_dispatched']) ?></div>
                            <span class="text-muted small"><?= count($dispatches) ?> total shipments</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-success-subtle border border-success-subtle rounded-3 text-center text-md-start">
                            <span class="text-success small text-uppercase fw-semibold" style="font-size: 0.72rem;">Payments Recovered</span>
                            <div class="fs-4 fw-bold text-success mt-1"><?= format_currency($totals['total_paid']) ?></div>
                            <span class="text-success small">Total credits received</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 bg-warning-subtle border border-warning-subtle rounded-3 text-center text-md-start">
                            <span class="text-warning-emphasis small text-uppercase fw-semibold" style="font-size: 0.72rem;">Sales Returns Credited</span>
                            <div class="fs-4 fw-bold text-warning-emphasis mt-1"><?= format_currency($totals['total_returned']) ?></div>
                            <span class="text-warning-emphasis small"><?= count($returns) ?> returns recorded</span>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded-3 text-center text-md-start <?= $netBalance > 0 ? 'bg-danger-subtle border border-danger-subtle' : 'bg-primary-subtle border border-primary-subtle' ?>">
                            <span class="small text-uppercase fw-semibold <?= $netBalance > 0 ? 'text-danger' : 'text-primary' ?>" style="font-size: 0.72rem;">
                                <?= $netBalance > 0 ? 'Outstanding Receivable' : ($netBalance < 0 ? 'Customer Advance' : 'Settled Balance') ?>
                            </span>
                            <div class="fs-4 fw-bold mt-1 <?= $netBalance > 0 ? 'text-danger' : 'text-primary' ?>">
                                <?= format_currency(abs($netBalance)) ?>
                            </div>
                            <span class="small <?= $netBalance > 0 ? 'text-danger' : 'text-muted' ?>">
                                <?= $netBalance > 0 ? 'Due from customer' : ($netBalance < 0 ? 'Customer has credit balance' : 'Zero balance') ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ledger Tabs & Filter -->
        <div class="card-modern">
            <div class="card-modern-header p-3 d-flex flex-wrap justify-content-between align-items-center gap-3">
                <ul class="nav nav-pills" id="customerTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active fw-semibold" id="ledger-tab" data-bs-toggle="tab" data-bs-target="#ledgerPane" type="button" role="tab">
                            <i class="bi bi-journal-text me-1"></i> Running Account Ledger (<?= count($ledgerEntries) ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold" id="dispatches-tab" data-bs-toggle="tab" data-bs-target="#dispatchesPane" type="button" role="tab">
                            <i class="bi bi-truck me-1"></i> Dispatches & Invoices (<?= count($dispatches) ?>)
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link fw-semibold" id="returns-tab" data-bs-toggle="tab" data-bs-target="#returnsPane" type="button" role="tab">
                            <i class="bi bi-arrow-return-left me-1"></i> Sales Returns (<?= count($returns) ?>)
                        </button>
                    </li>
                </ul>

                <!-- Date Range Filter Form -->
                <form method="GET" action="" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="id" value="<?= $customerId ?>">
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= clean($fromDate) ?>" title="From Date">
                    <span class="text-muted small">to</span>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= clean($toDate) ?>" title="To Date">
                    <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                    <?php if (!empty($fromDate) || !empty($toDate)): ?>
                        <a href="<?= BASE_URL ?>modules/customers/view.php?id=<?= $customerId ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="card-modern-body p-0">
                <div class="tab-content" id="customerTabContent">
                    <!-- ========================================== -->
                    <!-- TAB 1: RUNNING ACCOUNT LEDGER -->
                    <!-- ========================================== -->
                    <div class="tab-pane fade show active" id="ledgerPane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 110px;">Date</th>
                                        <th style="width: 140px;">Type</th>
                                        <th style="width: 140px;">Reference</th>
                                        <th>Description</th>
                                        <th class="text-end" style="width: 140px;">Debit (Billed)</th>
                                        <th class="text-end" style="width: 140px;">Credit (Paid/Ret)</th>
                                        <th class="text-end" style="width: 160px;">Running Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ledgerEntries)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-muted">
                                                <i class="bi bi-inbox display-6 text-secondary opacity-50 d-block mb-2"></i>
                                                No ledger transactions found for the selected period.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php 
                                        $subDebit = 0;
                                        $subCredit = 0;
                                        foreach ($ledgerEntries as $entry): 
                                            $d = (float)$entry['debit'];
                                            $c = (float)$entry['credit'];
                                            $rb = (float)$entry['running_balance'];
                                            $subDebit += $d;
                                            $subCredit += $c;

                                            $badgeClass = 'bg-secondary';
                                            if ($entry['transaction_type'] === 'Dispatch') $badgeClass = 'bg-primary';
                                            if ($entry['transaction_type'] === 'Payment') $badgeClass = 'bg-success';
                                            if ($entry['transaction_type'] === 'Return') $badgeClass = 'bg-warning text-dark';
                                            if ($entry['transaction_type'] === 'Opening Balance') $badgeClass = 'bg-info text-dark';
                                        ?>
                                            <tr>
                                                <td class="text-muted small fw-semibold"><?= format_date($entry['transaction_date']) ?></td>
                                                <td>
                                                    <span class="badge <?= $badgeClass ?> px-2 py-1"><?= clean($entry['transaction_type']) ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($entry['reference_type'] === 'DISPATCH' && $entry['reference_id']): ?>
                                                        <a href="<?= BASE_URL ?>modules/dispatch/challan.php?id=<?= $entry['reference_id'] ?>" target="_blank" class="fw-semibold text-primary text-decoration-none" title="Print Delivery Challan">
                                                            <i class="bi bi-receipt me-1"></i>View Challan
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted small"><?= clean($entry['reference_type'] ?: '-') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="text-dark"><?= clean($entry['description']) ?></span>
                                                </td>
                                                <td class="text-end fw-semibold <?= $d > 0 ? 'text-dark' : 'text-muted opacity-50' ?>">
                                                    <?= $d > 0 ? format_currency($d) : '—' ?>
                                                </td>
                                                <td class="text-end fw-semibold <?= $c > 0 ? 'text-success' : 'text-muted opacity-50' ?>">
                                                    <?= $c > 0 ? format_currency($c) : '—' ?>
                                                </td>
                                                <td class="text-end fw-bold fs-6 <?= $rb > 0 ? 'text-primary' : ($rb < 0 ? 'text-success' : 'text-muted') ?>">
                                                    <?= format_currency($rb) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <?php if (!empty($ledgerEntries)): ?>
                                    <tfoot class="table-light fw-bold">
                                        <tr>
                                            <td colspan="4" class="text-end">Period Totals:</td>
                                            <td class="text-end text-dark"><?= format_currency($subDebit) ?></td>
                                            <td class="text-end text-success"><?= format_currency($subCredit) ?></td>
                                            <td class="text-end text-primary"><?= format_currency($netBalance) ?></td>
                                        </tr>
                                    </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

                    <!-- ========================================== -->
                    <!-- TAB 2: DISPATCHES & INVOICES -->
                    <!-- ========================================== -->
                    <div class="tab-pane fade" id="dispatchesPane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Challan / Inv #</th>
                                        <th>Date</th>
                                        <th>Lot / Garment</th>
                                        <th>Warehouse</th>
                                        <th class="text-center">Dispatched Pcs</th>
                                        <th class="text-end">Total Billed</th>
                                        <th class="text-center">Challan Ref</th>
                                        <th class="text-end">Slip</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dispatches)): ?>
                                        <tr><td colspan="8" class="text-center py-4 text-muted">No dispatches found for this customer.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($dispatches as $dsp): ?>
                                            <tr>
                                                <td class="fw-bold text-primary">#<?= clean($dsp['dispatch_number']) ?></td>
                                                <td><?= format_date($dsp['dispatch_date']) ?></td>
                                                <td>
                                                    <span class="fw-semibold">Lot #<?= clean($dsp['lot_numbers'] ?: 'N/A') ?></span>
                                                    <div class="text-muted small"><?= clean($dsp['product_names'] ?: 'Garments') ?></div>
                                                </td>
                                                <td><span class="badge bg-light text-secondary border"><?= clean($dsp['warehouse_name']) ?></span></td>
                                                <td class="text-center fw-bold"><?= number_format($dsp['total_pieces']) ?> pcs</td>
                                                <td class="text-end fw-bold text-dark"><?= format_currency($dsp['total_value']) ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-success-subtle text-success"><?= clean($dsp['challan_no'] ?: 'Challan Issued') ?></span>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= BASE_URL ?>modules/dispatch/challan.php?id=<?= $dsp['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" title="Print Delivery Challan">
                                                        <i class="bi bi-printer"></i> Slip
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ========================================== -->
                    <!-- TAB 3: SALES RETURNS -->
                    <!-- ========================================== -->
                    <div class="tab-pane fade" id="returnsPane" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Return #</th>
                                        <th>Date</th>
                                        <th>Original Dispatch</th>
                                        <th>Product / Lot</th>
                                        <th class="text-center">Returned Pcs</th>
                                        <th class="text-end">Credit Amount</th>
                                        <th>Reason / Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($returns)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted">No sales returns recorded from this customer.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($returns as $ret): ?>
                                            <tr>
                                                <td class="fw-bold text-danger">#<?= clean($ret['return_number']) ?></td>
                                                <td><?= format_date($ret['return_date']) ?></td>
                                                <td class="text-primary fw-semibold">#<?= clean($ret['dispatch_number']) ?></td>
                                                <td>
                                                    <span><?= clean($ret['product_names'] ?: 'Returned Garments') ?></span>
                                                    <div class="text-muted small">Lot #<?= clean($ret['lot_numbers'] ?: 'N/A') ?></div>
                                                </td>
                                                <td class="text-center fw-bold text-danger"><?= number_format($ret['total_pieces']) ?> pcs</td>
                                                <td class="text-end fw-bold text-success"><?= format_currency($ret['credit_amount']) ?></td>
                                                <td>
                                                    <span class="badge bg-warning-subtle text-dark"><?= clean($ret['return_type']) ?></span>
                                                    <?php if ($ret['remarks']): ?>
                                                        <div class="small text-muted"><?= clean($ret['remarks']) ?></div>
                                                    <?php endif; ?>
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
        </div>

    </div>

<!-- ============================================================= -->
<!-- MODAL: RECEIVE CUSTOMER PAYMENT -->
<!-- ============================================================= -->
<div class="modal fade" id="paymentModal" tabindex="-1" aria-labelledby="paymentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="action" value="receive_payment">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold" id="paymentModalLabel">
                        <i class="bi bi-cash-stack me-2"></i>Receive Customer Payment
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="p-3 bg-light border rounded-3 mb-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">Customer / Party:</span>
                            <span class="fw-bold text-dark"><?= clean($customer['name']) ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <span class="text-muted small">Current Outstanding:</span>
                            <span class="fw-bold fs-6 <?= $netBalance > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= format_currency($netBalance) ?>
                            </span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Payment Date *</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Amount Received (Rs.) *</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" name="amount" class="form-control fw-bold text-success" step="0.01" min="1" placeholder="0.00" value="<?= $netBalance > 0 ? $netBalance : '' ?>" required>
                            </div>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Payment Method *</label>
                            <select name="payment_method" class="form-select" required>
                                <option value="Cash">Cash in Hand</option>
                                <option value="Online Bank Transfer">Online Bank Transfer</option>
                                <option value="Cheque">Crossed Cheque</option>
                                <option value="Pay Order">Pay Order / Demand Draft</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label small fw-semibold">Deposit Account</label>
                            <select name="account_id" class="form-select">
                                <option value="">-- No Account (Direct) --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= clean($acc['account_name']) ?> (<?= format_currency($acc['current_balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Cheque / Reference / Transaction #</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="e.g. Cheque #891024 or UTR / IBAN Transfer Ref">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Remarks / Payment Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="e.g. Part payment against Lot #101 shipment">
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-top">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold px-4">
                        <i class="bi bi-check2-circle me-1"></i> Credit Payment to Ledger
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
