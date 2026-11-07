<?php
/**
 * Garment Manufacturing ERP - Financial Flow & Cash Analysis Overview
 * Comprehensive financial view of Inflows (Customer receipts, deposits) vs Outflows (Labour, Materials, Expenses)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Financial Flow & P&L Overview';
$activeNav = 'financial_flow';
$db = get_db();

// Period Filter (defaults to Current Month)
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));

// 1. INFLOWS
// Customer Receipts (from customer_ledger or account_transactions)
$custInflowStmt = $db->prepare("SELECT COALESCE(SUM(credit), 0) FROM customer_ledger 
                                WHERE transaction_date BETWEEN ? AND ? AND transaction_type IN ('Payment', 'Advance')");
$custInflowStmt->execute([$startDate, $endDate]);
$customerInflows = (float)$custInflowStmt->fetchColumn();

// Manual Capital / Deposits
$depStmt = $db->prepare("SELECT COALESCE(SUM(credit), 0) FROM account_transactions 
                         WHERE transaction_date BETWEEN ? AND ? AND transaction_type = 'Deposit'");
$depStmt->execute([$startDate, $endDate]);
$depositInflows = (float)$depStmt->fetchColumn();

$totalInflows = $customerInflows + $depositInflows;

// 2. OUTFLOWS
// Labour / Worker Payments
$labourOutStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM worker_payments 
                              WHERE payment_date BETWEEN ? AND ? AND is_reversed = 0");
$labourOutStmt->execute([$startDate, $endDate]);
$labourOutflows = (float)$labourOutStmt->fetchColumn();

// Raw Material Supplier Payments
$suppOutStmt = $db->prepare("SELECT COALESCE(SUM(debit), 0) FROM supplier_ledger 
                            WHERE transaction_date BETWEEN ? AND ? AND transaction_type = 'Payment'");
$suppOutStmt->execute([$startDate, $endDate]);
$supplierOutflows = (float)$suppOutStmt->fetchColumn();

// Factory Overhead Expenses
$expStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses 
                         WHERE expense_date BETWEEN ? AND ?");
$expStmt->execute([$startDate, $endDate]);
$factoryExpenseOutflows = (float)$expStmt->fetchColumn();

// Manual Withdrawals
$wthStmt = $db->prepare("SELECT COALESCE(SUM(debit), 0) FROM account_transactions 
                         WHERE transaction_date BETWEEN ? AND ? AND transaction_type = 'Withdrawal'");
$wthStmt->execute([$startDate, $endDate]);
$withdrawalOutflows = (float)$wthStmt->fetchColumn();

$totalOutflows = $labourOutflows + $supplierOutflows + $factoryExpenseOutflows + $withdrawalOutflows;

// Net Cash Flow
$netOperatingCash = $totalInflows - $totalOutflows;

// 3. Category-wise Expense Breakdown
$catExpStmt = $db->prepare("SELECT ec.name, COALESCE(SUM(e.amount), 0) AS total, COUNT(e.id) AS voucher_count
                           FROM expense_categories ec
                           LEFT JOIN expenses e ON ec.id = e.category_id AND (e.expense_date BETWEEN ? AND ?)
                           GROUP BY ec.id
                           ORDER BY total DESC");
$catExpStmt->execute([$startDate, $endDate]);
$categoryExpenses = $catExpStmt->fetchAll();

// 4. Current Balances across all accounts
$accounts = $db->query("SELECT * FROM cash_bank_accounts ORDER BY is_active DESC, current_balance DESC")->fetchAll();
$totalLiquidFunds = array_sum(array_column($accounts, 'current_balance'));

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
                        <li class="breadcrumb-item active" aria-current="page">Financial Flow & Overview</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-wallet2 text-success me-2"></i>Factory Financial Flow & Cash Flow Analysis
                </h4>
                <p class="text-muted small mb-0">High-level summary of total factory inflows, disbursements, labour costs, material spend, and net operating liquidity</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>modules/accounts/statement.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-journal-text me-1"></i> Cash Book
                </a>
                <a href="<?= BASE_URL ?>modules/accounts/expenses.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-receipt me-1"></i> Factory Expenses
                </a>
                <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print Summary
                </button>
            </div>
        </div>

        <!-- Date Range Filter -->
        <div class="card-modern mb-4">
            <div class="card-modern-body p-3">
                <form method="GET" action="" class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <span class="small fw-bold text-muted text-uppercase d-block mb-1">Accounting Analysis Period</span>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                            <input type="date" name="start_date" class="form-control" value="<?= clean($startDate) ?>">
                            <span class="input-group-text">to</span>
                            <input type="date" name="end_date" class="form-control" value="<?= clean($endDate) ?>">
                        </div>
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                            <i class="bi bi-filter me-1"></i> Analyze
                        </button>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="btn-group btn-group-sm w-100">
                            <a href="?start_date=<?= date('Y-m-01') ?>&end_date=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary <?= $startDate === date('Y-m-01') ? 'active' : '' ?>">This Month</a>
                            <a href="?start_date=<?= date('Y-01-01') ?>&end_date=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary <?= $startDate === date('Y-01-01') ? 'active' : '' ?>">This Year</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- 3 Master Flow Cards -->
        <div class="row g-3 mb-4">
            <!-- Total Inflow -->
            <div class="col-12 col-md-4">
                <div class="card-modern p-4 border-start border-4 border-success shadow-xs h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small text-uppercase fw-bold text-success">Total Cash Inflows</span>
                        <div class="avatar avatar-sm bg-success-subtle text-success rounded-3">
                            <i class="bi bi-arrow-down-left fs-5"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-success mb-2"><?= format_currency($totalInflows) ?></h3>
                    <div class="small text-muted border-top pt-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Customer Collections:</span>
                            <strong class="text-dark"><?= format_currency($customerInflows) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Capital / Deposits:</span>
                            <strong class="text-dark"><?= format_currency($depositInflows) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Outflow -->
            <div class="col-12 col-md-4">
                <div class="card-modern p-4 border-start border-4 border-danger shadow-xs h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small text-uppercase fw-bold text-danger">Total Disbursements</span>
                        <div class="avatar avatar-sm bg-danger-subtle text-danger rounded-3">
                            <i class="bi bi-arrow-up-right fs-5"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold text-danger mb-2"><?= format_currency($totalOutflows) ?></h3>
                    <div class="small text-muted border-top pt-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Labour / Worker Pay:</span>
                            <strong class="text-dark"><?= format_currency($labourOutflows) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span>Supplier Materials:</span>
                            <strong class="text-dark"><?= format_currency($supplierOutflows) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Factory Expenses & Misc:</span>
                            <strong class="text-dark"><?= format_currency($factoryExpenseOutflows + $withdrawalOutflows) ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Net Flow -->
            <div class="col-12 col-md-4">
                <div class="card-modern p-4 border-start border-4 <?= $netOperatingCash >= 0 ? 'border-primary' : 'border-warning' ?> shadow-xs h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small text-uppercase fw-bold">Net Period Liquidity Flow</span>
                        <div class="avatar avatar-sm <?= $netOperatingCash >= 0 ? 'bg-primary-subtle text-primary' : 'bg-warning-subtle text-warning-emphasis' ?> rounded-3">
                            <i class="bi bi-wallet2 fs-5"></i>
                        </div>
                    </div>
                    <h3 class="fw-bold <?= $netOperatingCash >= 0 ? 'text-primary' : 'text-danger' ?> mb-2">
                        <?= ($netOperatingCash >= 0 ? '+ ' : '') . format_currency($netOperatingCash) ?>
                    </h3>
                    <div class="small text-muted border-top pt-2">
                        <div class="d-flex justify-content-between mb-1">
                            <span>Status:</span>
                            <span class="badge <?= $netOperatingCash >= 0 ? 'bg-success' : 'bg-danger' ?> rounded-pill">
                                <?= $netOperatingCash >= 0 ? 'Cash Surplus' : 'Cash Deficit' ?>
                            </span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Total Current Liquid Funds:</span>
                            <strong class="text-dark"><?= format_currency($totalLiquidFunds) ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Expenses by Category Breakdown -->
            <div class="col-12 col-lg-7">
                <div class="card-modern h-100">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-pie-chart-fill me-2 text-primary"></i>Operational Expenses by Category</h6>
                            <span class="text-muted small">Period breakdown: <?= format_date($startDate) ?> to <?= format_date($endDate) ?></span>
                        </div>
                        <a href="<?= BASE_URL ?>modules/accounts/expenses.php" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-plus-circle me-1"></i> Add Expense
                        </a>
                    </div>
                    <div class="card-modern-body p-0">
                        <div class="table-responsive">
                            <table class="table table-modern align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Category Name</th>
                                        <th class="text-center">Vouchers</th>
                                        <th class="text-end">Total Amount</th>
                                        <th class="text-end" style="width: 140px;">Share (%)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($categoryExpenses)): ?>
                                        <tr><td colspan="4" class="text-center py-4 text-muted">No expense categories registered.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($categoryExpenses as $ce): 
                                            $pct = $factoryExpenseOutflows > 0 ? (($ce['total'] / $factoryExpenseOutflows) * 100) : 0;
                                        ?>
                                            <tr>
                                                <td class="fw-semibold text-dark"><?= clean($ce['name']) ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-light text-dark border"><?= $ce['voucher_count'] ?></span>
                                                </td>
                                                <td class="text-end fw-bold text-dark">
                                                    <?= format_currency($ce['total']) ?>
                                                </td>
                                                <td class="text-end">
                                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                                        <div class="progress flex-grow-1" style="height: 6px;">
                                                            <div class="progress-bar bg-danger" style="width: <?= min(100, $pct) ?>%"></div>
                                                        </div>
                                                        <span class="small text-muted fw-bold" style="min-width: 40px;"><?= number_format($pct, 1) ?>%</span>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                                <tfoot class="table-light fw-bold border-top-2">
                                    <tr>
                                        <td colspan="2" class="text-end">TOTAL FACTORY EXPENSES:</td>
                                        <td class="text-end text-danger fs-6"><?= format_currency($factoryExpenseOutflows) ?></td>
                                        <td class="text-end">100.0%</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Cash & Bank Balances -->
            <div class="col-12 col-lg-5">
                <div class="card-modern h-100">
                    <div class="card-modern-header d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-bank2 me-2 text-primary"></i>Live Account Balances</h6>
                        <span class="badge bg-primary-subtle text-primary border fw-bold">Total: <?= format_currency($totalLiquidFunds) ?></span>
                    </div>
                    <div class="card-modern-body p-3">
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($accounts as $acc): 
                                $isCash = (stripos($acc['account_name'], 'cash') !== false || stripos($acc['bank_name'], 'cash') !== false);
                            ?>
                                <div class="p-3 border rounded-3 bg-light">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <div>
                                            <h6 class="fw-bold text-dark mb-0"><?= clean($acc['account_name']) ?></h6>
                                            <span class="small text-muted"><?= clean($acc['bank_name']) ?> • <?= clean($acc['account_number'] ?: '—') ?></span>
                                        </div>
                                        <span class="badge <?= $acc['is_active'] ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                                            <?= $acc['is_active'] ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
                                        <span class="small text-muted">Available Funds:</span>
                                        <span class="fw-bold fs-6 <?= (float)$acc['current_balance'] >= 0 ? 'text-primary' : 'text-danger' ?>">
                                            <?= format_currency($acc['current_balance']) ?>
                                        </span>
                                    </div>
                                    <div class="text-end mt-2">
                                        <a href="<?= BASE_URL ?>modules/accounts/statement.php?account_id=<?= $acc['id'] ?>" class="btn btn-xs btn-outline-primary py-0 px-2 small">
                                            View Statement <i class="bi bi-arrow-right"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
