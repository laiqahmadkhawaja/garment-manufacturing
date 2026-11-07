<?php
/**
 * Garment Manufacturing ERP - Accounts & Cash/Bank Management
 * Manage Cash Drawers, Bank Accounts, Transfers, Deposits, Withdrawals, and Master Balances
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Cash & Bank Accounts';
$activeNav = 'accounts';
$db = get_db();

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    // 1. Create New Account
    if ($action === 'create_account') {
        $name = trim($_POST['account_name'] ?? '');
        $bank = trim($_POST['bank_name'] ?? '');
        $number = trim($_POST['account_number'] ?? '');
        $opening = max(0, (float)($_POST['opening_balance'] ?? 0));

        if (empty($name)) {
            set_flash('danger', 'Account name is required.');
        } else {
            try {
                $db->beginTransaction();
                $stmt = $db->prepare("INSERT INTO cash_bank_accounts (account_name, bank_name, account_number, current_balance, is_active) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$name, $bank ?: 'Cash / Bank', $number, 0.00]);
                $accId = (int)$db->lastInsertId();

                if ($opening > 0) {
                    record_account_transaction(
                        $accId,
                        date('Y-m-d'),
                        'Opening Balance',
                        $opening,
                        'inflow',
                        'Initial opening balance upon creation',
                        'ACCOUNT_OPENING',
                        $accId,
                        'OPEN-' . $accId
                    );
                }

                $db->commit();
                log_audit('CREATE_ACCOUNT', 'cash_bank_accounts', $accId, null, ['name' => $name, 'opening' => $opening]);
                redirect('modules/accounts/index.php', "Account '$name' created successfully!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('danger', 'Error creating account: ' . $e->getMessage());
            }
        }
    }

    // 2. Edit Account Details
    if ($action === 'edit_account') {
        $accId = (int)($_POST['account_id'] ?? 0);
        $name = trim($_POST['account_name'] ?? '');
        $bank = trim($_POST['bank_name'] ?? '');
        $number = trim($_POST['account_number'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($accId > 0 && !empty($name)) {
            try {
                $stmt = $db->prepare("UPDATE cash_bank_accounts SET account_name = ?, bank_name = ?, account_number = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$name, $bank, $number, $isActive, $accId]);
                log_audit('UPDATE_ACCOUNT', 'cash_bank_accounts', $accId, null, ['name' => $name, 'is_active' => $isActive]);
                redirect('modules/accounts/index.php', "Account '$name' updated successfully!");
            } catch (Exception $e) {
                set_flash('danger', 'Error updating account: ' . $e->getMessage());
            }
        }
    }

    // 3. Fund Transfer Between Accounts
    if ($action === 'transfer_funds') {
        $fromId = (int)($_POST['from_account_id'] ?? 0);
        $toId = (int)($_POST['to_account_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $date = trim($_POST['transfer_date'] ?? date('Y-m-d'));
        $refNo = trim($_POST['reference_no'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            set_flash('danger', 'Please select two different accounts for fund transfer.');
        } elseif ($amount <= 0) {
            set_flash('danger', 'Transfer amount must be greater than zero.');
        } else {
            try {
                $db->beginTransaction();

                // Check source balance
                $chkStmt = $db->prepare("SELECT account_name, current_balance FROM cash_bank_accounts WHERE id = ? FOR UPDATE");
                $chkStmt->execute([$fromId]);
                $source = $chkStmt->fetch();

                $toStmt = $db->prepare("SELECT account_name FROM cash_bank_accounts WHERE id = ? FOR UPDATE");
                $toStmt->execute([$toId]);
                $target = $toStmt->fetch();

                if (!$source || !$target) {
                    throw new Exception('Invalid source or destination account.');
                }

                if ((float)$source['current_balance'] < $amount) {
                    throw new Exception("Insufficient balance in {$source['account_name']}. Available: " . format_currency($source['current_balance']));
                }

                $txRef = $refNo ?: ('TRF-' . strtoupper(substr(uniqid(), -6)));

                // Outflow from Source
                record_account_transaction(
                    $fromId,
                    $date,
                    'Transfer Out',
                    $amount,
                    'outflow',
                    "Fund transfer to {$target['account_name']}" . ($notes ? " ($notes)" : ""),
                    'ACCOUNT_TRANSFER',
                    $toId,
                    $txRef,
                    $toId
                );

                // Inflow to Target
                record_account_transaction(
                    $toId,
                    $date,
                    'Transfer In',
                    $amount,
                    'inflow',
                    "Fund transfer from {$source['account_name']}" . ($notes ? " ($notes)" : ""),
                    'ACCOUNT_TRANSFER',
                    $fromId,
                    $txRef,
                    $fromId
                );

                $db->commit();
                log_audit('TRANSFER_FUNDS', 'cash_bank_accounts', $fromId, null, ['to' => $toId, 'amount' => $amount, 'ref' => $txRef]);
                redirect('modules/accounts/index.php', "Successfully transferred " . format_currency($amount) . " from {$source['account_name']} to {$target['account_name']}!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('danger', 'Transfer failed: ' . $e->getMessage());
            }
        }
    }

    // 4. Deposit / Withdrawal / Adjustment
    if ($action === 'adjust_account') {
        $accId = (int)($_POST['account_id'] ?? 0);
        $type = trim($_POST['adjustment_type'] ?? 'Deposit'); // 'Deposit' or 'Withdrawal'
        $amount = (float)($_POST['amount'] ?? 0);
        $date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
        $refNo = trim($_POST['reference_no'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($accId <= 0 || $amount <= 0) {
            set_flash('danger', 'Valid account and positive amount are required.');
        } else {
            try {
                $db->beginTransaction();

                $stmt = $db->prepare("SELECT account_name, current_balance FROM cash_bank_accounts WHERE id = ? FOR UPDATE");
                $stmt->execute([$accId]);
                $acc = $stmt->fetch();

                if (!$acc) {
                    throw new Exception('Account not found.');
                }

                if ($type === 'Withdrawal' && (float)$acc['current_balance'] < $amount) {
                    throw new Exception("Insufficient balance for withdrawal. Available: " . format_currency($acc['current_balance']));
                }

                $flow = ($type === 'Deposit') ? 'inflow' : 'outflow';
                record_account_transaction(
                    $accId,
                    $date,
                    $type,
                    $amount,
                    $flow,
                    $description ?: ($type . " recorded directly"),
                    'MANUAL_' . strtoupper($type),
                    $accId,
                    $refNo
                );

                $db->commit();
                log_audit('MANUAL_ADJUSTMENT', 'cash_bank_accounts', $accId, null, ['type' => $type, 'amount' => $amount]);
                redirect('modules/accounts/index.php', "$type of " . format_currency($amount) . " recorded on {$acc['account_name']}!");
            } catch (Exception $e) {
                if ($db->inTransaction()) $db->rollBack();
                set_flash('danger', 'Operation failed: ' . $e->getMessage());
            }
        }
    }
}

// Fetch Accounts Data
$accounts = $db->query("SELECT * FROM cash_bank_accounts ORDER BY is_active DESC, current_balance DESC, id ASC")->fetchAll();

$totalFunds = 0;
$cashTotal = 0;
$bankTotal = 0;
$activeCount = 0;

foreach ($accounts as $a) {
    $bal = (float)$a['current_balance'];
    $totalFunds += $bal;
    $isCash = (stripos($a['account_name'], 'cash') !== false || stripos($a['bank_name'], 'cash') !== false || stripos($a['account_number'], 'cash') !== false);
    if ($isCash) {
        $cashTotal += $bal;
    } else {
        $bankTotal += $bal;
    }
    if ($a['is_active']) $activeCount++;
}

// Recent Transactions
$recentTx = $db->query("SELECT at.*, cba.account_name, cba.bank_name, rel.account_name AS related_name, u.full_name AS recorder_name
                        FROM account_transactions at
                        JOIN cash_bank_accounts cba ON at.account_id = cba.id
                        LEFT JOIN cash_bank_accounts rel ON at.related_account_id = rel.id
                        LEFT JOIN users u ON at.created_by = u.id
                        ORDER BY at.transaction_date DESC, at.id DESC
                        LIMIT 20")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <!-- Top Action Bar -->
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-bank2 text-primary me-2"></i>Cash & Bank Accounts Management
                </h4>
                <p class="text-muted small mb-0">Monitor factory cash drawers, commercial bank accounts, inter-account transfers, and real-time ledger statements</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm fw-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#transferModal">
                    <i class="bi bi-arrow-left-right me-1"></i> Transfer Funds
                </button>
                <button type="button" class="btn btn-outline-success btn-sm fw-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#adjustmentModal">
                    <i class="bi bi-plus-slash-minus me-1"></i> Deposit / Withdraw
                </button>
                <button type="button" class="btn btn-primary btn-sm fw-semibold shadow-xs" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                    <i class="bi bi-plus-circle-fill me-1"></i> Add Account
                </button>
            </div>
        </div>

        <!-- Metric KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-modern p-3 border-start border-4 border-primary shadow-xs">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Total Liquid Funds</span>
                            <h4 class="fw-bold text-dark mt-1 mb-0"><?= format_currency($totalFunds) ?></h4>
                            <span class="small text-muted mt-1 d-block"><?= count($accounts) ?> Accounts Configured</span>
                        </div>
                        <div class="avatar avatar-md bg-primary-subtle text-primary rounded-3">
                            <i class="bi bi-wallet2 fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-modern p-3 border-start border-4 border-success shadow-xs">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">In-House Cash Drawers</span>
                            <h4 class="fw-bold text-success mt-1 mb-0"><?= format_currency($cashTotal) ?></h4>
                            <span class="small text-muted mt-1 d-block">Factory Floor Petty Cash</span>
                        </div>
                        <div class="avatar avatar-md bg-success-subtle text-success rounded-3">
                            <i class="bi bi-cash-stack fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-modern p-3 border-start border-4 border-info shadow-xs">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Bank Current & Savings</span>
                            <h4 class="fw-bold text-info mt-1 mb-0"><?= format_currency($bankTotal) ?></h4>
                            <span class="small text-muted mt-1 d-block">Commercial Bank Balances</span>
                        </div>
                        <div class="avatar avatar-md bg-info-subtle text-info rounded-3">
                            <i class="bi bi-building fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-modern p-3 border-start border-4 border-warning shadow-xs">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted small text-uppercase fw-bold">Account Statements</span>
                            <h4 class="fw-bold text-dark mt-1 mb-0"><?= $activeCount ?> Active</h4>
                            <a href="<?= BASE_URL ?>modules/accounts/statement.php" class="small text-primary text-decoration-none fw-semibold mt-1 d-block">
                                Open Cash Book <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                        <div class="avatar avatar-md bg-warning-subtle text-warning-emphasis rounded-3">
                            <i class="bi bi-journal-text fs-4"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accounts Grid -->
        <div class="row g-3 mb-4">
            <?php foreach ($accounts as $acc): 
                $isCash = (stripos($acc['account_name'], 'cash') !== false || stripos($acc['bank_name'], 'cash') !== false || stripos($acc['account_number'], 'cash') !== false);
                $cardIcon = $isCash ? 'bi-cash-coin text-success' : 'bi-bank text-primary';
                $badgeBg = $isCash ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary';
            ?>
                <div class="col-12 col-md-6 col-xl-4">
                    <div class="card-modern h-100 shadow-xs border <?= $acc['is_active'] ? '' : 'opacity-75 bg-light' ?>">
                        <div class="card-modern-body p-4">
                            <div class="d-flex align-items-start justify-content-between mb-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="p-3 rounded-3 <?= $badgeBg ?>">
                                        <i class="bi <?= $cardIcon ?> fs-3"></i>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold text-dark mb-1"><?= clean($acc['account_name']) ?></h6>
                                        <span class="small text-muted"><?= clean($acc['bank_name']) ?></span>
                                    </div>
                                </div>
                                <span class="badge <?= $acc['is_active'] ? 'bg-success' : 'bg-secondary' ?> rounded-pill">
                                    <?= $acc['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>

                            <div class="bg-light p-3 rounded-3 mb-3 border">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted small">Account / IBAN:</span>
                                    <span class="fw-semibold font-monospace small text-dark"><?= clean($acc['account_number'] ?: '—') ?></span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="text-muted small">Live Available Balance:</span>
                                    <span class="fs-5 fw-bold <?= (float)$acc['current_balance'] >= 0 ? 'text-primary' : 'text-danger' ?>">
                                        <?= format_currency($acc['current_balance']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center pt-1">
                                <a href="<?= BASE_URL ?>modules/accounts/statement.php?account_id=<?= $acc['id'] ?>" class="btn btn-sm btn-outline-primary fw-semibold">
                                    <i class="bi bi-file-earmark-spreadsheet me-1"></i> Statement
                                </a>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-outline-secondary btn-quick-tx" 
                                            data-id="<?= $acc['id'] ?>" 
                                            data-name="<?= clean($acc['account_name']) ?>" 
                                            title="Quick Deposit/Withdrawal">
                                        <i class="bi bi-plus-slash-minus"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-edit-account" 
                                            data-id="<?= $acc['id'] ?>" 
                                            data-name="<?= clean($acc['account_name']) ?>" 
                                            data-bank="<?= clean($acc['bank_name']) ?>" 
                                            data-number="<?= clean($acc['account_number']) ?>" 
                                            data-active="<?= (int)$acc['is_active'] ?>" 
                                            title="Edit Account Details">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Recent Transactions Across All Accounts -->
        <div class="card-modern">
            <div class="card-modern-header d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="fw-bold mb-0 text-dark"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Account Transactions</h6>
                    <span class="text-muted small">Latest cash receipts, supplier/labour payments, expenses & transfers</span>
                </div>
                <a href="<?= BASE_URL ?>modules/accounts/statement.php" class="btn btn-sm btn-outline-primary fw-semibold">
                    View Full Cash Book <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Account</th>
                                <th>Type</th>
                                <th>Description / Party</th>
                                <th>Reference</th>
                                <th class="text-end">Credit (Inflow)</th>
                                <th class="text-end">Debit (Outflow)</th>
                                <th class="text-end">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentTx)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4 text-muted">No transactions recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentTx as $tx): 
                                    $isCredit = (float)$tx['credit'] > 0;
                                    $typeBadge = 'bg-secondary';
                                    if (in_array($tx['transaction_type'], ['Deposit', 'Opening Balance', 'Customer Payment'])) $typeBadge = 'bg-success-subtle text-success border border-success-subtle';
                                    elseif (in_array($tx['transaction_type'], ['Withdrawal', 'Expense', 'Supplier Payment', 'Labour Payment'])) $typeBadge = 'bg-danger-subtle text-danger border border-danger-subtle';
                                    elseif (stripos($tx['transaction_type'], 'Transfer') !== false) $typeBadge = 'bg-info-subtle text-info-emphasis border border-info-subtle';
                                ?>
                                    <tr>
                                        <td class="fw-semibold text-dark"><?= format_date($tx['transaction_date']) ?></td>
                                        <td>
                                            <span class="fw-bold text-dark"><?= clean($tx['account_name']) ?></span>
                                            <div class="small text-muted"><?= clean($tx['bank_name']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $typeBadge ?> fw-semibold"><?= clean($tx['transaction_type']) ?></span>
                                        </td>
                                        <td>
                                            <div class="text-dark small fw-medium"><?= clean($tx['description'] ?: '—') ?></div>
                                            <?php if ($tx['related_name']): ?>
                                                <div class="small text-muted"><i class="bi bi-arrow-right-short"></i> Linked: <?= clean($tx['related_name']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-monospace small text-muted"><?= clean($tx['reference_no'] ?: '—') ?></td>
                                        <td class="text-end fw-bold text-success">
                                            <?= (float)$tx['credit'] > 0 ? '+ ' . format_currency($tx['credit']) : '—' ?>
                                        </td>
                                        <td class="text-end fw-bold text-danger">
                                            <?= (float)$tx['debit'] > 0 ? '- ' . format_currency($tx['debit']) : '—' ?>
                                        </td>
                                        <td class="text-end fw-bold text-dark">
                                            <?= format_currency($tx['running_balance']) ?>
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

<!-- MODAL 1: ADD NEW ACCOUNT -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_account">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-bank2 text-primary me-2"></i>Add Cash or Bank Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Account Display Name *</label>
                            <input type="text" name="account_name" class="form-control" placeholder="e.g. Meezan Factory Ops A/C or Cash Drawer 2" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Bank / Entity Name</label>
                            <input type="text" name="bank_name" class="form-control" placeholder="e.g. Meezan Bank Ltd / Cash Register">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Account / IBAN Number</label>
                            <input type="text" name="account_number" class="form-control font-monospace" placeholder="e.g. 0101010098765">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Opening / Initial Balance (Rs.)</label>
                            <input type="number" step="0.01" min="0" name="opening_balance" class="form-control fw-bold" placeholder="0.00" value="0.00">
                            <div class="form-text small">Initial funds available in this drawer or account.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold px-4">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 2: EDIT ACCOUNT -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="edit_account">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="account_id" id="edit_acc_id">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-pencil-square text-primary me-2"></i>Edit Account Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Account Display Name *</label>
                            <input type="text" name="account_name" id="edit_acc_name" class="form-control" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Bank / Entity Name</label>
                            <input type="text" name="bank_name" id="edit_acc_bank" class="form-control">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Account / IBAN Number</label>
                            <input type="text" name="account_number" id="edit_acc_number" class="form-control font-monospace">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_acc_active" value="1">
                                <label class="form-check-label fw-semibold" for="edit_acc_active">Account is Active for transactions</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 3: FUND TRANSFER -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="transfer_funds">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-arrow-left-right text-primary me-2"></i>Inter-Account Fund Transfer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Transfer Date *</label>
                            <input type="date" name="transfer_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Amount (Rs.) *</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control fw-bold text-primary" placeholder="0.00" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">From Account (Source) *</label>
                            <select name="from_account_id" class="form-select fw-semibold" required>
                                <option value="">-- Select Source --</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>">
                                        <?= clean($a['account_name']) ?> (<?= format_currency($a['current_balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">To Account (Destination) *</label>
                            <select name="to_account_id" class="form-select fw-semibold" required>
                                <option value="">-- Select Destination --</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>">
                                        <?= clean($a['account_name']) ?> (<?= format_currency($a['current_balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Reference / Cheque #</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="e.g. Cheque # 102938 or Slip # 449">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Transfer Description / Reason</label>
                            <input type="text" name="notes" class="form-control" placeholder="e.g. Bank cash withdrawal for factory labour pay">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-semibold px-4">Execute Transfer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 4: DEPOSIT / WITHDRAWAL -->
<div class="modal fade" id="adjustmentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="action" value="adjust_account">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-plus-slash-minus text-success me-2"></i>Record Deposit or Withdrawal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Target Account *</label>
                            <select name="account_id" id="adj_account_id" class="form-select fw-semibold" required>
                                <option value="">-- Select Account --</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= $a['id'] ?>">
                                        <?= clean($a['account_name']) ?> (<?= format_currency($a['current_balance']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Movement Type *</label>
                            <select name="adjustment_type" class="form-select fw-semibold" required>
                                <option value="Deposit">Deposit (+) [Capital / Inflow]</option>
                                <option value="Withdrawal">Withdrawal (-) [Drawings / Outflow]</option>
                            </select>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Transaction Date *</label>
                            <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label fw-semibold">Amount (Rs.) *</label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control fw-bold text-success" placeholder="0.00" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Reference # / Slip #</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="e.g. Deposit slip # or bank receipt">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description / Particulars *</label>
                            <input type="text" name="description" class="form-control" placeholder="e.g. Owner capital injection, miscellaneous income, drawing" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-semibold px-4">Record Transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Edit Account Modal Binding
    const editModal = new bootstrap.Modal(document.getElementById('editAccountModal'));
    document.querySelectorAll('.btn-edit-account').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_acc_id').value = this.dataset.id;
            document.getElementById('edit_acc_name').value = this.dataset.name;
            document.getElementById('edit_acc_bank').value = this.dataset.bank;
            document.getElementById('edit_acc_number').value = this.dataset.number;
            document.getElementById('edit_acc_active').checked = (this.dataset.active == '1');
            editModal.show();
        });
    });

    // Quick Adjustment Binding
    const adjModal = new bootstrap.Modal(document.getElementById('adjustmentModal'));
    document.querySelectorAll('.btn-quick-tx').forEach(btn => {
        btn.addEventListener('click', function() {
            const accId = this.dataset.id;
            document.getElementById('adj_account_id').value = accId;
            adjModal.show();
        });
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
