<?php
/**
 * Garment Manufacturing ERP - Account Statements & Cash Book
 * Real-time ledger statements, cash flow history, opening/closing balance reconciliations, and CSV export
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Account Statements & Cash Book';
$activeNav = 'account_statement';
$db = get_db();

// Filters
$accountId = (int)($_GET['account_id'] ?? 0);
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));
$txType = trim($_GET['transaction_type'] ?? '');
$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';

// Fetch Accounts List for dropdown
$accounts = $db->query("SELECT * FROM cash_bank_accounts ORDER BY is_active DESC, account_name ASC")->fetchAll();
$selectedAccount = null;
if ($accountId > 0) {
    foreach ($accounts as $a) {
        if ($a['id'] == $accountId) {
            $selectedAccount = $a;
            break;
        }
    }
}

// 1. Calculate Opening Balance prior to $startDate
$priorSql = "SELECT COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS prior_bal 
             FROM account_transactions 
             WHERE transaction_date < ?";
$priorParams = [$startDate];
if ($accountId > 0) {
    $priorSql .= " AND account_id = ?";
    $priorParams[] = $accountId;
}
$priorStmt = $db->prepare($priorSql);
$priorStmt->execute($priorParams);
$openingPeriodBalance = (float)$priorStmt->fetchColumn();

// 2. Fetch Transactions in period
$sql = "SELECT at.*, cba.account_name, cba.bank_name, cba.account_number, 
               rel.account_name AS related_name, u.full_name AS recorder_name
        FROM account_transactions at
        JOIN cash_bank_accounts cba ON at.account_id = cba.id
        LEFT JOIN cash_bank_accounts rel ON at.related_account_id = rel.id
        LEFT JOIN users u ON at.created_by = u.id
        WHERE at.transaction_date BETWEEN ? AND ?";
$params = [$startDate, $endDate];

if ($accountId > 0) {
    $sql .= " AND at.account_id = ?";
    $params[] = $accountId;
}

if (!empty($txType)) {
    $sql .= " AND at.transaction_type = ?";
    $params[] = $txType;
}

$sql .= " ORDER BY at.transaction_date ASC, at.id ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Calculate Period Totals and live running balance
$totalCredit = 0;
$totalDebit = 0;
$running = $openingPeriodBalance;

foreach ($transactions as &$tx) {
    $cr = (float)$tx['credit'];
    $dr = (float)$tx['debit'];
    $totalCredit += $cr;
    $totalDebit += $dr;
    $running += ($cr - $dr);
    $tx['calc_running_balance'] = $running;
}
unset($tx);

$netMovement = $totalCredit - $totalDebit;
$closingPeriodBalance = $openingPeriodBalance + $netMovement;

// Handle CSV Export
if ($exportCsv) {
    $filename = "statement_" . ($selectedAccount ? preg_replace('/[^a-zA-Z0-9]/', '_', $selectedAccount['account_name']) : 'all_accounts') . "_{$startDate}_to_{$endDate}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Garment Manufacturing ERP - Account Statement']);
    fputcsv($out, ['Account:', $selectedAccount ? $selectedAccount['account_name'] . ' (' . $selectedAccount['bank_name'] . ')' : 'All Accounts']);
    fputcsv($out, ['Period:', $startDate . ' to ' . $endDate]);
    fputcsv($out, ['Opening Balance:', number_format($openingPeriodBalance, 2)]);
    fputcsv($out, ['Total Credits (Inflows):', number_format($totalCredit, 2)]);
    fputcsv($out, ['Total Debits (Outflows):', number_format($totalDebit, 2)]);
    fputcsv($out, ['Closing Balance:', number_format($closingPeriodBalance, 2)]);
    fputcsv($out, []); // Blank line
    fputcsv($out, ['Date', 'ID', 'Account', 'Type', 'Particulars / Description', 'Reference #', 'Inflow (Credit)', 'Outflow (Debit)', 'Running Balance']);

    foreach ($transactions as $t) {
        fputcsv($out, [
            $t['transaction_date'],
            $t['id'],
            $t['account_name'],
            $t['transaction_type'],
            $t['description'] . ($t['related_name'] ? " (Linked: {$t['related_name']})" : ''),
            $t['reference_no'],
            $t['credit'] > 0 ? $t['credit'] : '0.00',
            $t['debit'] > 0 ? $t['debit'] : '0.00',
            number_format($t['calc_running_balance'], 2)
        ]);
    }
    fclose($out);
    exit;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Top Controls Bar -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-1 small">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>modules/accounts/index.php" class="text-decoration-none">Accounts</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Statements & Cash Book</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-journal-check text-info me-2"></i>Account Statement & Cash Book
                </h4>
                <p class="text-muted small mb-0">Audit live inflows, outflows, vouchers, and running balances for any cash drawer or bank account</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>modules/accounts/index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i> All Accounts
                </a>
                <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Statement
                </button>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success btn-sm fw-semibold">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i> Export to CSV
                </a>
            </div>
        </div>

        <!-- Filter Form -->
        <div class="card-modern mb-4">
            <div class="card-modern-body p-3">
                <form method="GET" action="" class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold">Target Account</label>
                        <select name="account_id" class="form-select form-select-sm fw-bold">
                            <option value="0">-- All Cash & Bank Accounts --</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $accountId == $a['id'] ? 'selected' : '' ?>>
                                    <?= clean($a['account_name']) ?> (<?= format_currency($a['current_balance']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-semibold">From Date</label>
                        <input type="date" name="start_date" class="form-control form-control-sm" value="<?= clean($startDate) ?>">
                    </div>

                    <div class="col-6 col-md-2">
                        <label class="form-label small fw-semibold">To Date</label>
                        <input type="date" name="end_date" class="form-control form-control-sm" value="<?= clean($endDate) ?>">
                    </div>

                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold">Transaction Type</label>
                        <select name="transaction_type" class="form-select form-select-sm">
                            <option value="">-- All Movement Types --</option>
                            <option value="Deposit" <?= $txType === 'Deposit' ? 'selected' : '' ?>>Deposit (+)</option>
                            <option value="Customer Payment" <?= $txType === 'Customer Payment' ? 'selected' : '' ?>>Customer Payment (+)</option>
                            <option value="Transfer In" <?= $txType === 'Transfer In' ? 'selected' : '' ?>>Transfer In (+)</option>
                            <option value="Withdrawal" <?= $txType === 'Withdrawal' ? 'selected' : '' ?>>Withdrawal (-)</option>
                            <option value="Transfer Out" <?= $txType === 'Transfer Out' ? 'selected' : '' ?>>Transfer Out (-)</option>
                            <option value="Expense" <?= $txType === 'Expense' ? 'selected' : '' ?>>Factory Expense (-)</option>
                            <option value="Labour Payment" <?= $txType === 'Labour Payment' ? 'selected' : '' ?>>Labour / Worker Payment (-)</option>
                            <option value="Supplier Payment" <?= $txType === 'Supplier Payment' ? 'selected' : '' ?>>Supplier Purchase Payment (-)</option>
                            <option value="Opening Balance" <?= $txType === 'Opening Balance' ? 'selected' : '' ?>>Opening Balance</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                            <i class="bi bi-funnel me-1"></i> Filter
                        </button>
                        <a href="<?= BASE_URL ?>modules/accounts/statement.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                            <i class="bi bi-arrow-clockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Period Financial Summary Bar -->
        <div class="card-modern mb-4 bg-light border">
            <div class="card-modern-body p-3">
                <div class="row g-3 text-center text-sm-start align-items-center">
                    <div class="col-12 col-sm-6 col-md-3 border-end-md">
                        <span class="text-muted small text-uppercase fw-bold">Period Opening Balance</span>
                        <h5 class="fw-bold text-dark mt-1 mb-0"><?= format_currency($openingPeriodBalance) ?></h5>
                        <span class="small text-muted">Prior to <?= format_date($startDate) ?></span>
                    </div>
                    <div class="col-6 col-sm-3 col-md-2 border-end-md">
                        <span class="text-muted small text-uppercase fw-bold text-success">+ Total Credits</span>
                        <h5 class="fw-bold text-success mt-1 mb-0"><?= format_currency($totalCredit) ?></h5>
                        <span class="small text-muted">Inflows & Receipts</span>
                    </div>
                    <div class="col-6 col-sm-3 col-md-2 border-end-md">
                        <span class="text-muted small text-uppercase fw-bold text-danger">- Total Debits</span>
                        <h5 class="fw-bold text-danger mt-1 mb-0"><?= format_currency($totalDebit) ?></h5>
                        <span class="small text-muted">Outflows & Payments</span>
                    </div>
                    <div class="col-6 col-sm-3 col-md-2 border-end-md">
                        <span class="text-muted small text-uppercase fw-bold">Net Movement</span>
                        <h5 class="fw-bold mt-1 mb-0 <?= $netMovement >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= ($netMovement >= 0 ? '+ ' : '') . format_currency($netMovement) ?>
                        </h5>
                        <span class="small text-muted">Inflow vs Outflow</span>
                    </div>
                    <div class="col-6 col-sm-3 col-md-3">
                        <span class="text-muted small text-uppercase fw-bold">Closing Balance</span>
                        <h5 class="fw-bold text-primary mt-1 mb-0"><?= format_currency($closingPeriodBalance) ?></h5>
                        <span class="small text-muted">As of <?= format_date($endDate) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Detailed Statement Table -->
        <div class="card-modern">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-list-columns-reverse me-2 text-primary"></i>
                        <?= $selectedAccount ? clean($selectedAccount['account_name']) . " Ledger Statement" : "Consolidated General Cash Book" ?>
                    </h6>
                    <span class="text-muted small">Showing <?= count($transactions) ?> transaction entries between <?= format_date($startDate) ?> and <?= format_date($endDate) ?></span>
                </div>
                <span class="badge bg-light text-secondary border"><?= count($transactions) ?> Rows</span>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0" id="statementTable">
                        <thead>
                            <tr>
                                <th style="width: 105px;">Date</th>
                                <th style="width: 70px;">ID</th>
                                <th>Account</th>
                                <th>Type</th>
                                <th>Particulars / Description</th>
                                <th>Ref #</th>
                                <th class="text-end" style="width: 140px;">Inflow (Credit)</th>
                                <th class="text-end" style="width: 140px;">Outflow (Debit)</th>
                                <th class="text-end" style="width: 150px;">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Period Initial Row -->
                            <tr class="table-light">
                                <td class="fw-semibold text-muted"><?= format_date($startDate) ?></td>
                                <td class="text-muted">—</td>
                                <td colspan="4" class="fw-bold text-dark">
                                    <i class="bi bi-arrow-return-right me-1 text-primary"></i> PERIOD OPENING BALANCE BROUGHT FORWARD
                                </td>
                                <td class="text-end text-muted">—</td>
                                <td class="text-end text-muted">—</td>
                                <td class="text-end fw-bold text-dark fs-6"><?= format_currency($openingPeriodBalance) ?></td>
                            </tr>

                            <?php if (empty($transactions)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">No transaction movements recorded during this period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $t): 
                                    $typeBadge = 'bg-secondary';
                                    if (in_array($t['transaction_type'], ['Deposit', 'Opening Balance', 'Customer Payment'])) $typeBadge = 'bg-success-subtle text-success border border-success-subtle';
                                    elseif (in_array($t['transaction_type'], ['Withdrawal', 'Expense', 'Supplier Payment', 'Labour Payment'])) $typeBadge = 'bg-danger-subtle text-danger border border-danger-subtle';
                                    elseif (stripos($t['transaction_type'], 'Transfer') !== false) $typeBadge = 'bg-info-subtle text-info-emphasis border border-info-subtle';
                                ?>
                                    <tr>
                                        <td class="fw-semibold text-dark"><?= format_date($t['transaction_date']) ?></td>
                                        <td class="font-monospace small text-muted">#<?= $t['id'] ?></td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= clean($t['account_name']) ?></span>
                                            <div class="small text-muted" style="font-size: 0.72rem;"><?= clean($t['bank_name']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $typeBadge ?> fw-semibold"><?= clean($t['transaction_type']) ?></span>
                                        </td>
                                        <td>
                                            <div class="text-dark fw-medium"><?= clean($t['description'] ?: '—') ?></div>
                                            <?php if ($t['related_name']): ?>
                                                <div class="small text-muted"><i class="bi bi-arrow-right-short"></i> Linked Account: <?= clean($t['related_name']) ?></div>
                                            <?php endif; ?>
                                            <?php if ($t['recorder_name']): ?>
                                                <div class="small text-muted" style="font-size: 0.7rem;">Recorded by: <?= clean($t['recorder_name']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-monospace small text-muted"><?= clean($t['reference_no'] ?: '—') ?></td>
                                        <td class="text-end fw-bold text-success">
                                            <?= (float)$t['credit'] > 0 ? '+ ' . format_currency($t['credit']) : '—' ?>
                                        </td>
                                        <td class="text-end fw-bold text-danger">
                                            <?= (float)$t['debit'] > 0 ? '- ' . format_currency($t['debit']) : '—' ?>
                                        </td>
                                        <td class="text-end fw-bold text-dark fs-6">
                                            <?= format_currency($t['calc_running_balance']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold border-top-2">
                            <tr>
                                <td colspan="6" class="text-end text-dark">PERIOD TOTALS & CLOSING BALANCE:</td>
                                <td class="text-end text-success">+ <?= format_currency($totalCredit) ?></td>
                                <td class="text-end text-danger">- <?= format_currency($totalDebit) ?></td>
                                <td class="text-end text-primary fs-6"><?= format_currency($closingPeriodBalance) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

<style>
@media print {
    .app-sidebar, #sidebarBackdrop, .app-header, .topbar, .btn, form, nav[aria-label="breadcrumb"] {
        display: none !important;
    }
    .app-main {
        margin: 0 !important;
        padding: 0 !important;
    }
    .card-modern {
        border: none !important;
        box-shadow: none !important;
    }
    .table {
        font-size: 11px !important;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
