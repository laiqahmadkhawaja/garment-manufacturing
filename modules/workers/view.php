<?php
/**
 * Garment Manufacturing ERP - Worker Profile & Full Running Ledger
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$workerId = (int)($_GET['id'] ?? 0);
$db = get_db();

// Worker details
$stmt = $db->prepare("SELECT w.*, dep.name AS department_name 
                      FROM workers w 
                      LEFT JOIN departments dep ON w.department_id = dep.id 
                      WHERE w.id = ?");
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

if (!$worker) {
    redirect('modules/workers/index.php', 'Worker not found.', 'danger');
}

$pageTitle = $worker['name'] . " - Worker Profile";
$activeNav = 'workers';

// Quick update minute rate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_minute_rate') {
    check_csrf();
    $newMinRate = (float)($_POST['rate_per_minute'] ?? 2.50);
    if ($newMinRate > 0) {
        $up = $db->prepare("UPDATE workers SET rate_per_minute = ? WHERE id = ?");
        $up->execute([$newMinRate, $workerId]);
        redirect("modules/workers/view.php?id=$workerId", "Worker minute rate updated to Rs. " . number_format($newMinRate, 2) . "/min!");
    }
}

// Worker Assigned Operations
$opStmt = $db->prepare("SELECT o.* FROM worker_operations wo JOIN operations o ON wo.operation_id = o.id WHERE wo.worker_id = ?");
$opStmt->execute([$workerId]);
$assignedOps = $opStmt->fetchAll();

// Running Ledger Transactions
$ledgStmt = $db->prepare("SELECT wl.*, l.lot_number, o.name AS op_name 
                          FROM worker_ledger wl 
                          LEFT JOIN lots l ON wl.lot_id = l.id 
                          LEFT JOIN operations o ON wl.operation_id = o.id 
                          WHERE wl.worker_id = ? 
                          ORDER BY wl.transaction_date ASC, wl.id ASC");
$ledgStmt->execute([$workerId]);
$ledgerEntries = $ledgStmt->fetchAll();

// Calculate totals
$totalCredit = 0; // Labour earned
$totalDebit = 0;  // Payments made
foreach ($ledgerEntries as $le) {
    $totalCredit += (float)$le['credit'];
    $totalDebit += (float)$le['debit'];
}
$netBalance = $totalCredit - $totalDebit;
$isPayable = ($netBalance > 0);
$isAdvance = ($netBalance < 0);

// Work Entries on lots
$weStmt = $db->prepare("SELECT pe.*, l.lot_number, p.name AS product_name, o.name AS op_name, c.name AS color_name, s.name AS size_name 
                        FROM production_entries pe 
                        JOIN lots l ON pe.lot_id = l.id 
                        JOIN products p ON l.product_id = p.id 
                        JOIN operations o ON pe.operation_id = o.id 
                        JOIN colors c ON pe.color_id = c.id 
                        JOIN sizes s ON pe.size_id = s.id 
                        WHERE pe.worker_id = ? 
                        ORDER BY pe.id DESC");
$weStmt->execute([$workerId]);
$workHistory = $weStmt->fetchAll();

// Payments received
$pmStmt = $db->prepare("SELECT wp.*, cba.account_name, u.full_name AS recorder_name 
                        FROM worker_payments wp 
                        LEFT JOIN cash_bank_accounts cba ON wp.account_id = cba.id 
                        LEFT JOIN users u ON wp.created_by = u.id 
                        WHERE wp.worker_id = ? 
                        ORDER BY wp.id DESC");
$pmStmt->execute([$workerId]);
$paymentsList = $pmStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Worker Header Card -->
        <div class="card-modern mb-4">
            <div class="card-modern-body p-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 pb-3 border-bottom mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-3" style="width: 58px; height: 58px;">
                            <?= strtoupper(substr($worker['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2">
                                <h4 class="fw-bold text-dark mb-0"><?= clean($worker['name']) ?></h4>
                                <span class="badge bg-light text-primary border fw-bold"><?= clean($worker['code']) ?></span>
                                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small fw-semibold">
                                    <i class="bi bi-stopwatch me-1"></i>Rate: Rs. <?= number_format($worker['rate_per_minute'] ?? 2.50, 2) ?>/min
                                </span>
                            </div>
                            <div class="text-muted small mt-1 d-flex flex-wrap align-items-center gap-2">
                                <span><?= clean($worker['worker_type']) ?> · <?= clean($worker['department_name'] ?? 'General') ?></span>
                                <?php if ($worker['phone']): ?>
                                    <span>• Tel: <?= clean($worker['phone']) ?></span>
                                <?php endif; ?>
                                <span class="vr"></span>
                                <form method="POST" action="" class="d-inline-flex align-items-center gap-1">
                                    <input type="hidden" name="action" value="update_minute_rate">
                                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                    <span class="small text-muted">Change Minute Rate:</span>
                                    <div class="input-group input-group-sm" style="width: 130px;">
                                        <span class="input-group-text py-0 px-1 bg-light small">Rs</span>
                                        <input type="number" name="rate_per_minute" class="form-control form-control-sm text-center fw-bold text-success py-0" step="0.05" min="0" value="<?= number_format($worker['rate_per_minute'] ?? 2.50, 2) ?>">
                                        <span class="input-group-text py-0 px-1 bg-light small">/m</span>
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-outline-success py-0 px-2" title="Save Minute Rate">
                                        <i class="bi bi-check"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <a href="<?= BASE_URL ?>modules/payments/create.php?worker_id=<?= $worker['id'] ?>" class="btn btn-success btn-sm d-inline-flex align-items-center gap-1 shadow-sm">
                            <i class="bi bi-cash"></i>
                            <span>Disburse Payment</span>
                        </a>
                        <a href="<?= BASE_URL ?>modules/workers/statement.php?id=<?= $worker['id'] ?>" target="_blank" class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1">
                            <i class="bi bi-printer"></i>
                            <span>Print Statement</span>
                        </a>
                    </div>
                </div>

                <!-- Financial Balance Summary -->
                <div class="row g-3 text-center text-sm-start">
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded bg-light border">
                            <div class="text-muted small">Total Labour Earned</div>
                            <div class="fs-4 fw-bold text-dark"><?= format_currency($totalCredit) ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded bg-light border">
                            <div class="text-muted small">Total Payments Disbursed</div>
                            <div class="fs-4 fw-bold text-secondary"><?= format_currency($totalDebit) ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="p-3 rounded border <?= $isPayable ? 'bg-danger-subtle border-danger' : ($isAdvance ? 'bg-success-subtle border-success' : 'bg-light') ?>">
                            <div class="small fw-semibold <?= $isPayable ? 'text-danger' : ($isAdvance ? 'text-success' : 'text-muted') ?>">
                                <?= $isPayable ? 'Net Outstanding Payable to Worker (Factory Liability)' : ($isAdvance ? 'Net Worker Advance / Receivable (Factory Asset)' : 'Account Fully Settled') ?>
                            </div>
                            <div class="fs-3 fw-bold <?= $isPayable ? 'text-danger' : ($isAdvance ? 'text-success' : 'text-dark') ?>">
                                <?= format_currency(abs($netBalance)) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Tabs -->
        <ul class="nav nav-tabs nav-fill mb-4" id="workerTab" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-ledger">
                    <i class="bi bi-journal-text me-1"></i> Running Ledger (<?= count($ledgerEntries) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-work">
                    <i class="bi bi-scissors me-1"></i> Production Work History (<?= count($workHistory) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-payments">
                    <i class="bi bi-cash-stack me-1"></i> Payment Vouchers (<?= count($paymentsList) ?>)
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-profile">
                    <i class="bi bi-person-lines-fill me-1"></i> Personal Profile
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- TAB 1: RUNNING LEDGER -->
            <div class="tab-pane fade show active" id="tab-ledger">
                <div class="card-modern">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">Double-Entry Artisan Ledger Account</h6>
                            <span class="text-muted small">Credit = Labour Earned | Debit = Paid to Worker</span>
                        </div>
                        <a href="<?= BASE_URL ?>modules/workers/statement.php?id=<?= $worker['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-printer me-1"></i> Print Statement
                        </a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Transaction Type</th>
                                        <th>Ref / Lot / Op</th>
                                        <th>Description</th>
                                        <th class="text-end">Debit (Paid)</th>
                                        <th class="text-end">Credit (Earned)</th>
                                        <th class="text-end">Running Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ledgerEntries)): ?>
                                        <tr><td colspan="7" class="text-center py-4 text-muted">No ledger transactions recorded yet.</td></tr>
                                    <?php else: ?>
                                        <?php 
                                        $running = 0;
                                        foreach ($ledgerEntries as $le): 
                                            $credit = (float)$le['credit'];
                                            $debit = (float)$le['debit'];
                                            $running += ($credit - $debit);
                                        ?>
                                            <tr>
                                                <td><?= format_date($le['transaction_date']) ?></td>
                                                <td>
                                                    <?php if ($le['transaction_type'] === 'Labour'): ?>
                                                        <span class="badge bg-primary-subtle text-primary fw-semibold"><i class="bi bi-scissors me-1"></i> Labour</span>
                                                    <?php elseif ($le['transaction_type'] === 'Payment'): ?>
                                                        <span class="badge bg-success-subtle text-success fw-semibold"><i class="bi bi-cash me-1"></i> Payment</span>
                                                    <?php elseif ($le['transaction_type'] === 'Advance'): ?>
                                                        <span class="badge bg-warning-subtle text-warning-emphasis fw-semibold"><i class="bi bi-wallet2 me-1"></i> Advance</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-dark border"><?= clean($le['transaction_type']) ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($le['lot_number']): ?>
                                                        <span class="fw-semibold text-primary">LOT #<?= clean($le['lot_number']) ?></span>
                                                        <div class="text-muted small"><?= clean($le['op_name']) ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted small"><?= clean($le['reference_type'] ?: '-') ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="small text-muted"><?= clean($le['description']) ?></td>
                                                <td class="text-end fw-semibold text-secondary">
                                                    <?= $debit > 0 ? format_currency($debit) : '-' ?>
                                                </td>
                                                <td class="text-end fw-semibold text-dark">
                                                    <?= $credit > 0 ? format_currency($credit) : '-' ?>
                                                </td>
                                                <td class="text-end fw-bold fs-6 <?= $running > 0 ? 'text-danger' : ($running < 0 ? 'text-success' : 'text-dark') ?>">
                                                    <?= format_currency(abs($running)) ?>
                                                    <span class="small fw-normal d-block" style="font-size: 10px;">
                                                        <?= $running > 0 ? 'Payable' : ($running < 0 ? 'Advance' : 'Settled') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="4" class="text-end">Total Summary:</td>
                                        <td class="text-end text-secondary"><?= format_currency($totalDebit) ?></td>
                                        <td class="text-end text-dark"><?= format_currency($totalCredit) ?></td>
                                        <td class="text-end <?= $netBalance > 0 ? 'text-danger' : ($netBalance < 0 ? 'text-success' : 'text-dark') ?>">
                                            <?= format_currency(abs($netBalance)) ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: WORK HISTORY -->
            <div class="tab-pane fade" id="tab-work">
                <div class="card-modern">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Batch-wise Work Submissions</h6>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Lot Number</th>
                                        <th>Product</th>
                                        <th>Operation</th>
                                        <th>Variant</th>
                                        <th class="text-center">Good Qty</th>
                                        <th class="text-center">Rejected</th>
                                        <th class="text-end">Rate</th>
                                        <th class="text-end">Labour Earned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($workHistory)): ?>
                                        <tr><td colspan="9" class="text-center py-4 text-muted">No production work recorded for this worker yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($workHistory as $wh): ?>
                                            <tr>
                                                <td><?= format_date($wh['entry_date']) ?></td>
                                                <td>
                                                    <a href="<?= BASE_URL ?>modules/lots/view.php?id=<?= $wh['lot_id'] ?>" class="fw-bold text-decoration-none">
                                                        LOT #<?= clean($wh['lot_number']) ?>
                                                    </a>
                                                </td>
                                                <td><?= clean($wh['product_name']) ?></td>
                                                <td class="fw-semibold text-dark"><?= clean($wh['op_name']) ?></td>
                                                <td><?= clean($wh['color_name']) ?> (<?= clean($wh['size_name']) ?>)</td>
                                                <td class="text-center fw-bold text-success"><?= number_format($wh['good_qty']) ?></td>
                                                <td class="text-center text-danger"><?= number_format($wh['rejected_qty']) ?></td>
                                                <td class="text-end">Rs. <?= number_format($wh['rate'], 2) ?></td>
                                                <td class="text-end fw-bold text-dark"><?= format_currency($wh['labour_amount']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: PAYMENTS VOUCHERS -->
            <div class="tab-pane fade" id="tab-payments">
                <div class="card-modern">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark">Disbursed Payments & Advance Loans</h6>
                        <a href="<?= BASE_URL ?>modules/payments/create.php?worker_id=<?= $worker['id'] ?>" class="btn btn-sm btn-success">
                            <i class="bi bi-cash me-1"></i> Make Payment
                        </a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Payment #</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Method</th>
                                        <th>Account</th>
                                        <th>Ref No</th>
                                        <th class="text-end">Amount</th>
                                        <th>Status</th>
                                        <th class="text-end">Receipt</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($paymentsList)): ?>
                                        <tr><td colspan="9" class="text-center py-4 text-muted">No payments disbursed yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($paymentsList as $pm): ?>
                                            <tr>
                                                <td class="fw-bold text-primary"><?= clean($pm['payment_number']) ?></td>
                                                <td><?= format_date($pm['payment_date']) ?></td>
                                                <td>
                                                    <span class="badge bg-light text-dark border"><?= clean($pm['payment_type']) ?></span>
                                                </td>
                                                <td><?= clean($pm['payment_method']) ?></td>
                                                <td><?= clean($pm['account_name'] ?: 'Cash Drawer') ?></td>
                                                <td class="small text-muted"><?= clean($pm['reference_no'] ?: '-') ?></td>
                                                <td class="text-end fw-bold text-dark fs-6"><?= format_currency($pm['amount']) ?></td>
                                                <td>
                                                    <?php if ($pm['is_reversed']): ?>
                                                        <span class="badge bg-danger-subtle text-danger">Reversed</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success-subtle text-success">Paid</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <a href="<?= BASE_URL ?>modules/payments/receipt.php?id=<?= $pm['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Print Receipt">
                                                        <i class="bi bi-receipt"></i>
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

            <!-- TAB 4: PERSONAL PROFILE -->
            <div class="tab-pane fade" id="tab-profile">
                <div class="card-modern" style="max-width: 700px;">
                    <div class="card-modern-header">
                        <h6 class="fw-bold mb-0 text-dark">Worker Details & Assigned Operations</h6>
                    </div>
                    <div class="card-modern-body">
                        <table class="table table-bordered mb-3">
                            <tbody>
                                <tr>
                                    <th class="bg-light" style="width: 30%;">Full Name</th>
                                    <td><?= clean($worker['name']) ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Worker Code</th>
                                    <td class="fw-bold text-primary"><?= clean($worker['code']) ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Worker Type</th>
                                    <td><?= clean($worker['worker_type']) ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Department</th>
                                    <td><?= clean($worker['department_name'] ?? 'General') ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Phone</th>
                                    <td><?= clean($worker['phone'] ?: '-') ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light">CNIC</th>
                                    <td><?= clean($worker['cnic'] ?: '-') ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Address</th>
                                    <td><?= clean($worker['address'] ?: '-') ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Joining Date</th>
                                    <td><?= format_date($worker['joining_date']) ?></td>
                                </tr>
                                <tr>
                                    <th class="bg-light">Default Piece Rate</th>
                                    <td>Rs. <?= number_format($worker['default_rate'], 2) ?></td>
                                </tr>
                            </tbody>
                        </table>

                        <h6 class="fw-bold mb-2">Assigned Skilled Operations:</h6>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (empty($assignedOps)): ?>
                                <span class="text-muted small">All operations allowed</span>
                            <?php else: ?>
                                <?php foreach ($assignedOps as $op): ?>
                                    <span class="badge bg-primary-subtle text-primary border py-2 px-3 fw-semibold">
                                        <?= clean($op['name']) ?> (Rs. <?= number_format($op['default_rate'], 2) ?>)
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
