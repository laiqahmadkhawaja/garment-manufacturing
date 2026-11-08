<?php
/**
 * Garment Manufacturing ERP - Factory Overhead & Operational Expenses
 * Record factory operational expenses, electricity/fuel, maintenance, consumables, and link to cash/bank accounts
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Factory Expenses Management';
$activeNav = 'expenses';
$db = get_db();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Record New Expense Voucher
    if ($action === 'create_expense') {
        $date = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
        $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
        $lotId = !empty($_POST['lot_id']) ? (int)$_POST['lot_id'] : null;
        $refNo = trim($_POST['reference_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($categoryId <= 0 || $amount <= 0) {
            set_flash('danger', 'Please select a valid expense category and enter a positive amount.');
        } else {
            try {
                $db->beginTransaction();

                // If paying from an account, verify sufficient balance
                $accName = 'Cash';
                if ($accountId > 0) {
                    $chkAcc = $db->prepare("SELECT account_name, current_balance FROM cash_bank_accounts WHERE id = ? FOR UPDATE");
                    $chkAcc->execute([$accountId]);
                    $acc = $chkAcc->fetch();
                    if (!$acc) {
                        throw new Exception('Selected payment account was not found.');
                    }
                    if ((float)$acc['current_balance'] < $amount) {
                        throw new Exception("Insufficient balance in {$acc['account_name']}. Available: " . format_currency($acc['current_balance']));
                    }
                    $accName = $acc['account_name'];
                }

                // Insert into expenses
                $insExp = $db->prepare("INSERT INTO expenses 
                    (expense_date, category_id, amount, payment_method, account_id, reference_no, lot_id, notes, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insExp->execute([
                    $date,
                    $categoryId,
                    $amount,
                    $paymentMethod,
                    $accountId,
                    $refNo,
                    $lotId,
                    $notes,
                    $_SESSION['user_id'] ?? null
                ]);
                $expenseId = (int)$db->lastInsertId();

                // Record Account Transaction & deduct balance if account was chosen
                if ($accountId > 0) {
                    $catNameStmt = $db->prepare("SELECT name FROM expense_categories WHERE id = ?");
                    $catNameStmt->execute([$categoryId]);
                    $catName = $catNameStmt->fetchColumn() ?: 'Expense';

                    record_account_transaction(
                        $accountId,
                        $date,
                        'Expense',
                        $amount,
                        'outflow',
                        "Expense: $catName" . ($notes ? " - $notes" : ""),
                        'EXPENSE',
                        $expenseId,
                        $refNo ?: ("EXP-" . str_pad($expenseId, 5, '0', STR_PAD_LEFT)),
                        null,
                        $_SESSION['user_id'] ?? null
                    );
                }

                $db->commit();
                log_audit('CREATE_EXPENSE', 'expenses', $expenseId, null, ['amount' => $amount, 'category_id' => $categoryId, 'account_id' => $accountId]);
                redirect('modules/accounts/expenses.php', "Expense voucher of " . format_currency($amount) . " recorded successfully from $accName!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('danger', 'Error recording expense: ' . $e->getMessage());
            }
        }
    }

    // 2. Add New Expense Category
    if ($action === 'create_category') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');

        if (empty($name)) {
            set_flash('danger', 'Category name is required.');
        } else {
            try {
                $chk = $db->prepare("SELECT id FROM expense_categories WHERE LOWER(TRIM(name)) = LOWER(?)");
                $chk->execute([$name]);
                if ($chk->fetch()) {
                    set_flash('danger', "Category '$name' already exists.");
                } else {
                    $stmt = $db->prepare("INSERT INTO expense_categories (name, description) VALUES (?, ?)");
                    $stmt->execute([$name, $desc]);
                    set_flash('success', "Expense category '$name' added successfully!");
                    redirect('modules/accounts/expenses.php');
                }
            } catch (Exception $e) {
                set_flash('danger', 'Error adding category: ' . $e->getMessage());
            }
        }
    }

    // 3. Delete / Reverse Expense Voucher
    if ($action === 'delete_expense') {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        $reason = trim($_POST['reversal_reason'] ?? 'Expense voucher cancelled');

        if ($expenseId > 0) {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("SELECT * FROM expenses WHERE id = ? FOR UPDATE");
                $stmt->execute([$expenseId]);
                $exp = $stmt->fetch();

                if (!$exp) {
                    throw new Exception('Expense voucher not found.');
                }

                // If paid from account, refund back to account
                if ($exp['account_id']) {
                    record_account_transaction(
                        (int)$exp['account_id'],
                        date('Y-m-d'),
                        'Adjustment',
                        (float)$exp['amount'],
                        'inflow',
                        "Reversal of Expense #{$exp['id']} ($reason)",
                        'EXPENSE_REVERSAL',
                        $expenseId,
                        'REV-' . $expenseId
                    );
                }

                $db->prepare("DELETE FROM expenses WHERE id = ?")->execute([$expenseId]);

                $db->commit();
                log_audit('DELETE_EXPENSE', 'expenses', $expenseId, $exp, ['reason' => $reason]);
                redirect('modules/accounts/expenses.php', "Expense voucher #$expenseId cancelled and refunded successfully!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('danger', 'Error cancelling expense: ' . $e->getMessage());
            }
        }
    }
}

// Filters
$catFilter = (int)($_GET['category_id'] ?? 0);
$accFilter = (int)($_GET['account_id'] ?? 0);
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate = trim($_GET['end_date'] ?? date('Y-m-d'));

// Expense Statistics
$monthStart = date('Y-m-01');
$today = date('Y-m-d');

$thisMonthTotal = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date >= '$monthStart'")->fetchColumn();
$todayTotal = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE expense_date = '$today'")->fetchColumn();
$totalCount = (int)$db->query("SELECT COUNT(*) FROM expenses")->fetchColumn();

$topCatStmt = $db->query("SELECT ec.name, SUM(e.amount) AS total 
                          FROM expenses e 
                          JOIN expense_categories ec ON e.category_id = ec.id 
                          WHERE e.expense_date >= '$monthStart' 
                          GROUP BY ec.id 
                          ORDER BY total DESC 
                          LIMIT 1");
$topCat = $topCatStmt->fetch();

// Dropdown options
$categories = $db->query("SELECT ec.*, (SELECT COUNT(*) FROM expenses WHERE category_id = ec.id) AS expense_count FROM expense_categories ec ORDER BY ec.name ASC")->fetchAll();
$accounts = $db->query("SELECT * FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();
$activeLots = $db->query("SELECT l.id, l.lot_number, p.name AS product_name 
                          FROM lots l 
                          LEFT JOIN products p ON l.product_id = p.id 
                          WHERE l.status != 'Closed' 
                          ORDER BY l.id DESC LIMIT 50")->fetchAll();

// Fetch Filtered Expenses List
$sql = "SELECT e.*, ec.name AS category_name, cba.account_name, cba.bank_name, l.lot_number, u.full_name AS recorder_name
        FROM expenses e
        JOIN expense_categories ec ON e.category_id = ec.id
        LEFT JOIN cash_bank_accounts cba ON e.account_id = cba.id
        LEFT JOIN lots l ON e.lot_id = l.id
        LEFT JOIN users u ON e.created_by = u.id
        WHERE e.expense_date BETWEEN ? AND ?";
$params = [$startDate, $endDate];

if ($catFilter > 0) {
    $sql .= " AND e.category_id = ?";
    $params[] = $catFilter;
}

if ($accFilter > 0) {
    $sql .= " AND e.account_id = ?";
    $params[] = $accFilter;
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

$filteredSum = array_sum(array_column($expenses, 'amount'));

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
                        <li class="breadcrumb-item active" aria-current="page">Factory Expenses</li>
                    </ol>
                </nav>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-receipt-cutoff text-danger me-2"></i>Factory Expenses & Overhead
                </h4>
                <p class="text-muted small mb-0">Record factory operating costs, utilities, power, machine spare parts, maintenance, and consumables</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                    <i class="bi bi-folder-plus me-1"></i> New Category
                </button>
                <button type="button" class="btn btn-danger btn-sm fw-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#recordExpenseModal">
                    <i class="bi bi-plus-circle-fill me-1"></i> Record Expense Voucher
                </button>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-modern p-3 border-start border-4 border-danger shadow-xs">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">This Month's Expenses</span>
                            <h4 class="fw-bold text-danger mt-1 mb-0"><?= format_currency($thisMonthTotal) ?></h4>
                            <span class="small text-muted">Since <?= format_date($monthStart) ?></span>
                        </div>
                        <div class="avatar avatar-md bg-danger-subtle text-danger rounded-3">
                            <i class="bi bi-cash-stack fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-modern p-3 border-start border-4 border-warning shadow-xs">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Today's Expenses</span>
                            <h4 class="fw-bold text-dark mt-1 mb-0"><?= format_currency($todayTotal) ?></h4>
                            <span class="small text-muted"><?= format_date($today) ?></span>
                        </div>
                        <div class="avatar avatar-md bg-warning-subtle text-warning-emphasis rounded-3">
                            <i class="bi bi-calendar-event fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-modern p-3 border-start border-4 border-primary shadow-xs">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Top Cost Driver</span>
                            <h5 class="fw-bold text-dark mt-1 mb-0 text-truncate" style="max-width: 170px;">
                                <?= clean($topCat['name'] ?? 'None Recorded') ?>
                            </h5>
                            <span class="small text-muted"><?= isset($topCat['total']) ? format_currency($topCat['total']) : '—' ?></span>
                        </div>
                        <div class="avatar avatar-md bg-primary-subtle text-primary rounded-3">
                            <i class="bi bi-pie-chart-fill fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-modern p-3 border-start border-4 border-info shadow-xs">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Total Vouchers</span>
                            <h4 class="fw-bold text-info mt-1 mb-0"><?= $totalCount ?></h4>
                            <span class="small text-muted"><?= count($categories) ?> Active Categories</span>
                        </div>
                        <div class="avatar avatar-md bg-info-subtle text-info rounded-3">
                            <i class="bi bi-receipt fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card-modern mb-4">
            <div class="card-modern-body p-3">
                <form method="GET" action="" class="row g-2 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold">Expense Category</label>
                        <select name="category_id" class="form-select form-select-sm">
                            <option value="0">-- All Categories --</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $catFilter == $cat['id'] ? 'selected' : '' ?>>
                                    <?= clean($cat['name']) ?> (<?= $cat['expense_count'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold">Payment Account</label>
                        <select name="account_id" class="form-select form-select-sm">
                            <option value="0">-- All Cash/Bank Accounts --</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['id'] ?>" <?= $accFilter == $acc['id'] ? 'selected' : '' ?>>
                                    <?= clean($acc['account_name']) ?>
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

                    <div class="col-12 col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold">
                            <i class="bi bi-funnel me-1"></i> Filter
                        </button>
                        <a href="<?= BASE_URL ?>modules/accounts/expenses.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
                            <i class="bi bi-arrow-clockwise"></i>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Expenses List Table -->
        <div class="card-modern">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="bi bi-table me-2 text-primary"></i>Recorded Expense Vouchers
                    </h6>
                    <span class="text-muted small">Total Filtered: <strong class="text-danger"><?= format_currency($filteredSum) ?></strong> across <?= count($expenses) ?> vouchers</span>
                </div>
                <button type="button" class="btn btn-outline-dark btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i> Print List
                </button>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Voucher #</th>
                                <th>Category</th>
                                <th>Description / Particulars</th>
                                <th>Paid From Account</th>
                                <th>Method / Ref</th>
                                <th>Cost Job (Lot)</th>
                                <th class="text-end">Amount</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($expenses)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4 text-muted">No expense vouchers found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($expenses as $exp): ?>
                                    <tr>
                                        <td class="fw-semibold text-dark"><?= format_date($exp['expense_date']) ?></td>
                                        <td class="font-monospace small fw-bold text-primary">#EXP-<?= str_pad($exp['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                        <td>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-semibold">
                                                <?= clean($exp['category_name']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="text-dark fw-medium"><?= clean($exp['notes'] ?: 'Operational Expense') ?></div>
                                            <?php if ($exp['recorder_name']): ?>
                                                <div class="small text-muted" style="font-size: 0.7rem;">Recorded by: <?= clean($exp['recorder_name']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($exp['account_name']): ?>
                                                <span class="fw-semibold text-dark"><?= clean($exp['account_name']) ?></span>
                                                <div class="small text-muted"><?= clean($exp['bank_name']) ?></div>
                                            <?php else: ?>
                                                <span class="text-muted small fst-italic">Direct Cash / Petty</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= clean($exp['payment_method']) ?></span>
                                            <?php if ($exp['reference_no']): ?>
                                                <div class="font-monospace small text-muted"><?= clean($exp['reference_no']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($exp['lot_number']): ?>
                                                <span class="badge bg-primary-subtle text-primary border">
                                                    <?= clean($exp['lot_number']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold text-danger fs-6">
                                            <?= format_currency($exp['amount']) ?>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-outline-danger btn-sm py-1 px-2 btn-delete-expense" 
                                                    data-id="<?= $exp['id'] ?>" 
                                                    data-amount="<?= format_currency($exp['amount']) ?>" 
                                                    data-cat="<?= clean($exp['category_name']) ?>" 
                                                    title="Cancel & Refund Expense">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot class="table-light fw-bold border-top-2">
                            <tr>
                                <td colspan="7" class="text-end">TOTAL EXPENSES:</td>
                                <td class="text-end text-danger fs-6"><?= format_currency($filteredSum) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

<!-- MODAL 1: RECORD EXPENSE VOUCHER -->
<div class="modal fade" id="recordExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_expense">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-receipt text-danger me-2"></i>Record Factory Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Expense Date *</label>
                            <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Expense Category *</label>
                            <select name="category_id" class="form-select fw-semibold" required>
                                <option value="">-- Choose Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= clean($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Expense Amount (Rs.) *</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control fw-bold text-danger fs-5" placeholder="0.00" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Paid From Account *</label>
                            <select name="account_id" class="form-select fw-bold text-primary" required>
                                <option value="">-- Choose Account --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= clean($acc['account_name']) ?> (<?= format_currency($acc['current_balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer / Online</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Bill # / Reference Slip</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="e.g. Bill # 5894, K-Electric #">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Link to Production Lot (Optional)</label>
                            <select name="lot_id" class="form-select">
                                <option value="">-- No Direct Lot Link (Factory General Overhead) --</option>
                                <?php foreach ($activeLots as $l): ?>
                                    <option value="<?= $l['id'] ?>"><?= clean($l['lot_number']) ?> - <?= clean($l['product_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small">Select if this cost belongs directly to a specific production lot</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description / Purpose *</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Machine needle box & servicing oil purchase" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger fw-semibold px-4">Save Expense Voucher</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 2: ADD CATEGORY -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_category">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-folder-plus text-primary me-2"></i>Add Expense Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Category Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Generator Fuel & Lubricants" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description / Scope</label>
                            <input type="text" name="description" class="form-control" placeholder="e.g. Diesel fuel for backup generator during load shedding">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold px-4">Save Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 3: CANCEL / REVERSE EXPENSE -->
<div class="modal fade" id="deleteExpenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="delete_expense">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="expense_id" id="del_expense_id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Cancel Expense Voucher</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-dark mb-2">Are you sure you want to cancel and reverse this expense voucher?</p>
                    <div class="alert alert-warning py-2 small mb-3">
                        Voucher: <strong id="del_expense_info"></strong><br>
                        The paid amount will be refunded directly back to the source Cash / Bank account.
                    </div>
                    <div>
                        <label class="form-label fw-semibold small">Reason for Reversal *</label>
                        <input type="text" name="reversal_reason" class="form-control" placeholder="e.g. Duplicate entry or bill cancelled" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep Expense</button>
                    <button type="submit" class="btn btn-danger fw-semibold px-4">Confirm Reversal & Refund</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const delModal = new bootstrap.Modal(document.getElementById('deleteExpenseModal'));
    document.querySelectorAll('.btn-delete-expense').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('del_expense_id').value = this.dataset.id;
            document.getElementById('del_expense_info').textContent = '#' + this.dataset.id + ' (' + this.dataset.cat + ' - ' + this.dataset.amount + ')';
            delModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
