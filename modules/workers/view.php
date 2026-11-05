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

// Save Custom Element Rates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_element_rates') {
    check_csrf();
    $ratesData = $_POST['element_rates'] ?? [];
    foreach ($ratesData as $elId => $r) {
        $elId = (int)$elId;
        $customSam = (isset($r['sam']) && $r['sam'] !== '') ? (float)$r['sam'] : null;
        $customRate = (isset($r['rate']) && $r['rate'] !== '') ? (float)$r['rate'] : null;

        $ins = $db->prepare("INSERT INTO worker_element_rates (worker_id, element_id, custom_sam_minutes, custom_piece_rate)
                             VALUES (?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE custom_sam_minutes = VALUES(custom_sam_minutes), custom_piece_rate = VALUES(custom_piece_rate)");
        $ins->execute([$workerId, $elId, $customSam, $customRate]);
    }
    redirect("modules/workers/view.php?id=$workerId#tab-elements", "Worker custom element rates and SAM minutes saved successfully!");
}

// Worker Assigned Operations
$opStmt = $db->prepare("SELECT o.* FROM worker_operations wo JOIN operations o ON wo.operation_id = o.id WHERE wo.worker_id = ?");
$opStmt->execute([$workerId]);
$assignedOps = $opStmt->fetchAll();

// Product Elements and Worker Custom Rates
$elStmt = $db->prepare("SELECT pe.*, p.name AS product_name,
                               wer.custom_sam_minutes, wer.custom_piece_rate
                        FROM product_elements pe
                        JOIN products p ON pe.product_id = p.id
                        LEFT JOIN worker_element_rates wer ON wer.element_id = pe.id AND wer.worker_id = ?
                        ORDER BY p.id ASC, pe.sort_order ASC, pe.id ASC");
$elStmt->execute([$workerId]);
$workerElementRates = $elStmt->fetchAll();

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
                        LEFT JOIN colors c ON pe.color_id = c.id 
                        LEFT JOIN sizes s ON pe.size_id = s.id 
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
                        <a href="<?= BASE_URL ?>modules/workers/edit.php?id=<?= $worker['id'] ?>" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 shadow-xs">
                            <i class="bi bi-pencil-square"></i>
                            <span>Edit Profile</span>
                        </a>
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
                <button class="nav-link fw-semibold" data-bs-toggle="tab" data-bs-target="#tab-elements">
                    <i class="bi bi-puzzle me-1"></i> Element Rates & SAM (<?= count($workerElementRates) ?>)
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
                                                <td><?= !empty($wh['color_name']) ? clean($wh['color_name']) . ' (' . clean($wh['size_name']) . ')' : '<span class="text-muted small">Batch / All</span>' ?></td>
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
            <!-- TAB 5: ELEMENT RATES & SAM BREAKDOWN -->
            <div class="tab-pane fade" id="tab-elements">
                <div class="card-modern shadow-sm border-0">
                    <div class="card-modern-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="bi bi-puzzle-fill text-primary me-2"></i> Garment Element Rates & SAM Breakdown (حصوں کے مخصوص ریٹ اور اوسط وقت)
                            </h6>
                            <span class="text-muted small">Define custom SAM minutes or piece rates for each element when produced by <?= clean($worker['name']) ?></span>
                        </div>
                        <span class="badge bg-success-subtle text-success border px-2 py-1 fw-bold">Worker Benchmark: Rs. <?= number_format($worker['rate_per_minute'] ?? 2.50, 2) ?>/min</span>
                    </div>
                    <div class="card-modern-body p-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="save_element_rates">
                            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle text-center mb-0" style="font-size: 0.88rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-start">Garment Component / Element (حصہ)</th>
                                            <th>Product</th>
                                            <th>Standard SAM</th>
                                            <th>Worker Min Rate</th>
                                            <th>Standard Computed Rate</th>
                                            <th style="width: 155px;">Worker Custom SAM (min)</th>
                                            <th style="width: 165px;">Worker Custom Rate (Rs.)</th>
                                            <th class="text-start">Effective Piece Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($workerElementRates)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4 text-muted">No product cutting elements configured in settings.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($workerElementRates as $el): 
                                                $stdSam = (float)$el['default_sam_minutes'];
                                                $wMinRate = (float)$worker['rate_per_minute'];
                                                $stdRate = ($stdSam > 0 && $wMinRate > 0) ? ($stdSam * $wMinRate) : 0.00;
                                                $cSam = $el['custom_sam_minutes'] !== null ? (float)$el['custom_sam_minutes'] : '';
                                                $cRate = $el['custom_piece_rate'] !== null ? (float)$el['custom_piece_rate'] : '';
                                                
                                                if ($cRate !== '') {
                                                    $effRate = (float)$cRate;
                                                    $effSource = 'Custom Worker Rate';
                                                } elseif ($cSam !== '') {
                                                    $effRate = (float)$cSam * $wMinRate;
                                                    $effSource = 'Custom SAM × Min Rate';
                                                } else {
                                                    $effRate = $stdRate;
                                                    $effSource = 'Standard SAM × Min Rate';
                                                }
                                            ?>
                                                <tr class="wer-row">
                                                    <td class="text-start fw-bold text-dark">
                                                        <span class="text-primary me-1">🧵</span> <?= clean($el['element_name']) ?>
                                                        <span class="badge bg-light text-muted border">×<?= $el['quantity'] ?: $el['multiplier'] ?: 1 ?></span>
                                                    </td>
                                                    <td class="text-muted small"><?= clean($el['product_name']) ?></td>
                                                    <td class="fw-semibold text-muted"><?= number_format($stdSam, 1) ?> min</td>
                                                    <td class="text-muted small">Rs. <?= number_format($wMinRate, 2) ?>/m</td>
                                                    <td class="text-muted fw-semibold">Rs. <?= number_format($stdRate, 2) ?></td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="element_rates[<?= $el['id'] ?>][sam]" class="form-control form-control-sm text-center fw-bold text-primary wer-sam" data-minrate="<?= $wMinRate ?>" value="<?= $cSam !== '' ? number_format((float)$cSam, 1) : '' ?>" step="0.1" min="0" placeholder="<?= number_format($stdSam, 1) ?>">
                                                            <span class="input-group-text px-1 small text-muted">m</span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="input-group input-group-sm">
                                                            <span class="input-group-text px-1 small text-muted">Rs</span>
                                                            <input type="number" name="element_rates[<?= $el['id'] ?>][rate]" class="form-control form-control-sm text-center fw-bold text-dark wer-rate" value="<?= $cRate !== '' ? number_format((float)$cRate, 2) : '' ?>" step="0.25" min="0" placeholder="<?= number_format($stdRate, 2) ?>">
                                                        </div>
                                                    </td>
                                                    <td class="text-start">
                                                        <span class="fw-bold text-success fs-6 wer-eff-rate">Rs. <?= number_format($effRate, 2) ?></span>
                                                        <span class="badge bg-light text-secondary border ms-1 wer-eff-badge" style="font-size: 10px;"><?= $effSource ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="p-3 bg-light border-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                                <span class="text-muted small">
                                    <i class="bi bi-info-circle me-1"></i> ریٹ یا SAM خالی رکھنے پر ورکر کا بنیادی فی منٹ ریٹ اور سٹینڈرڈ SAM خود بخود استعمال ہوگا۔
                                </span>
                                <button type="submit" class="btn btn-sm btn-primary px-4 fw-bold shadow-sm">
                                    <i class="bi bi-save me-1"></i> Save Element Rates (ریٹس محفوظ کریں)
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Live recalculation in Worker Element Rates tab
    document.querySelectorAll('.wer-row').forEach(row => {
        const samInp = row.querySelector('.wer-sam');
        const rateInp = row.querySelector('.wer-rate');
        const effSpan = row.querySelector('.wer-eff-rate');
        const effBadge = row.querySelector('.wer-eff-badge');
        const minRate = parseFloat(samInp?.dataset.minrate) || 2.50;

        function recalcRow() {
            const customRate = rateInp && rateInp.value !== '' ? parseFloat(rateInp.value) : null;
            const customSam = samInp && samInp.value !== '' ? parseFloat(samInp.value) : null;

            if (customRate !== null) {
                if (effSpan) effSpan.textContent = 'Rs. ' + customRate.toFixed(2);
                if (effBadge) effBadge.textContent = 'Custom Worker Rate';
            } else if (customSam !== null) {
                const calcRate = customSam * minRate;
                if (rateInp && !rateInp.dataset.userEdited) rateInp.placeholder = calcRate.toFixed(2);
                if (effSpan) effSpan.textContent = 'Rs. ' + calcRate.toFixed(2);
                if (effBadge) effBadge.textContent = 'Custom SAM × Min Rate';
            }
        }

        if (samInp) {
            samInp.addEventListener('input', function() {
                if (rateInp && rateInp.value === '') {
                    const s = parseFloat(this.value) || 0;
                    rateInp.placeholder = (s * minRate).toFixed(2);
                }
                recalcRow();
            });
        }
        if (rateInp) {
            rateInp.addEventListener('input', function() {
                this.dataset.userEdited = '1';
                recalcRow();
            });
        }
    });

    // Auto switch to tab from hash URL if present (e.g. #tab-elements)
    if (window.location.hash) {
        const tabBtn = document.querySelector(`button[data-bs-target="${window.location.hash}"]`);
        if (tabBtn) {
            const tab = new bootstrap.Tab(tabBtn);
            tab.show();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
