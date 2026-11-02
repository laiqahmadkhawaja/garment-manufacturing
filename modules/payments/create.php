<?php
/**
 * Garment Manufacturing ERP - Worker Payment & Advance Entry
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Disburse Worker Payment';
$activeNav = 'payments';
$db = get_db();

$preselectedWorkerId = (int)($_GET['worker_id'] ?? 0);
$suggestedPayNumber = generate_doc_number('PAY-', 'worker_payments', 'payment_number');

$workers = $db->query("SELECT w.*, dep.name AS department_name,
                       COALESCE((SELECT SUM(credit) - SUM(debit) FROM worker_ledger WHERE worker_id = w.id), 0) AS current_balance
                       FROM workers w 
                       LEFT JOIN departments dep ON w.department_id = dep.id 
                       WHERE w.is_active = 1 
                       ORDER BY w.name ASC")->fetchAll();

$accounts = $db->query("SELECT * FROM cash_bank_accounts WHERE is_active = 1 ORDER BY account_name ASC")->fetchAll();
$methods = $db->query("SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY id ASC")->fetchAll();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $workerId = (int)($_POST['worker_id'] ?? 0);
    $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $amount = (float)($_POST['amount'] ?? 0.00);
    $paymentType = trim($_POST['payment_type'] ?? 'Payment'); // Payment or Advance
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $refNo = trim($_POST['reference_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($workerId <= 0 || $amount <= 0) {
        $error = 'Please select a worker and enter a valid payment amount greater than zero.';
    } else {
        $db->beginTransaction();
        try {
            $payNum = generate_doc_number('PAY-', 'worker_payments', 'payment_number');

            // 1. Insert Payment Record
            $pStmt = $db->prepare("INSERT INTO worker_payments 
                (payment_number, worker_id, payment_date, amount, payment_type, payment_method, account_id, reference_no, notes, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $pStmt->execute([
                $payNum,
                $workerId,
                $paymentDate,
                $amount,
                $paymentType,
                $paymentMethod,
                $accountId,
                $refNo,
                $notes,
                $_SESSION['user_id'] ?? null
            ]);
            $paymentId = (int)$db->lastInsertId();

            // 2. Insert Debit Transaction in Worker Ledger
            // Get last running balance
            $balStmt = $db->prepare("SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1");
            $balStmt->execute([$workerId]);
            $lastBal = $balStmt->fetchColumn();
            if ($lastBal === false) {
                $wStmt = $db->prepare("SELECT opening_balance FROM workers WHERE id = ?");
                $wStmt->execute([$workerId]);
                $lastBal = (float)$wStmt->fetchColumn();
            } else {
                $lastBal = (float)$lastBal;
            }

            // Debit reduces factory liability (decreases running payable balance)
            $newBal = $lastBal - $amount;

            $desc = "$paymentType disbursed via $paymentMethod ($payNum)" . ($refNo ? " Ref: $refNo" : "");
            $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
                (worker_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
                VALUES (?, ?, ?, 'WORKER_PAYMENT', ?, ?, 0.00, ?, ?, ?)");
            $ledgStmt->execute([
                $workerId,
                $paymentDate,
                $paymentType,
                $paymentId,
                $amount,
                $newBal,
                $desc,
                $_SESSION['user_id'] ?? null
            ]);

            // 3. Deduct from Cash/Bank Account balance if account chosen
            if ($accountId) {
                $accUp = $db->prepare("UPDATE cash_bank_accounts SET current_balance = current_balance - ? WHERE id = ?");
                $accUp->execute([$amount, $accountId]);
            }

            log_audit('DISBURSE_PAYMENT', 'worker_payments', $paymentId, null, [
                'worker_id' => $workerId,
                'amount' => $amount,
                'type' => $paymentType
            ]);

            $db->commit();
            redirect("modules/payments/receipt.php?id=$paymentId", "Payment of " . format_currency($amount) . " disbursed successfully!");
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to disburse payment: ' . $e->getMessage();
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
                <h4 class="fw-bold mb-1 text-dark">Disburse Worker Payment / Advance</h4>
                <p class="text-muted small mb-0">Record wage settlements, advance cash loans, or bank transfers</p>
            </div>
            <a href="<?= BASE_URL ?>modules/payments/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Payments
            </a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="card-modern" style="max-width: 800px;">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Payment Voucher Form</h6>
            </div>
            <div class="card-modern-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label">Worker / Artisan *</label>
                            <select name="worker_id" id="workerSelect" class="form-select fw-bold" required>
                                <option value="">-- Choose Artisan --</option>
                                <?php foreach ($workers as $w): ?>
                                    <option value="<?= $w['id'] ?>" 
                                            data-balance="<?= $w['current_balance'] ?>" 
                                            <?= ($preselectedWorkerId === $w['id']) ? 'selected' : '' ?>>
                                        <?= clean($w['name']) ?> (<?= clean($w['code']) ?>) — Current: Rs. <?= number_format($w['current_balance'], 2) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Payment Date *</label>
                            <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <!-- Live Worker Balance Card -->
                        <div class="col-12 d-none" id="balanceAlertBox">
                            <div class="p-3 bg-light border rounded-3 d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted small">Current Ledger Status:</span>
                                    <div class="fw-bold fs-6 text-dark" id="balanceText">Rs. 0.00</div>
                                </div>
                                <span class="badge" id="balanceBadge">Status</span>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Payment Type *</label>
                            <select name="payment_type" class="form-select">
                                <option value="Payment">Regular Payment (Wage/Labour)</option>
                                <option value="Advance">Advance Loan / Kharcha</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Payment Amount (Rs.) *</label>
                            <div class="input-group">
                                <span class="input-group-text">Rs.</span>
                                <input type="number" name="amount" id="payAmount" class="form-control form-control-lg fw-bold text-success text-end" step="1" min="1" placeholder="0" required>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <?php foreach ($methods as $m): ?>
                                    <option value="<?= clean($m['name']) ?>"><?= clean($m['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">From Account (Cash Register / Bank)</label>
                            <select name="account_id" class="form-select">
                                <option value="">-- Direct Factory Cash Drawer --</option>
                                <?php foreach ($accounts as $acc): ?>
                                    <option value="<?= $acc['id'] ?>">
                                        <?= clean($acc['account_name']) ?> (Balance: Rs. <?= number_format($acc['current_balance'], 2) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6">
                            <label class="form-label">Reference / Cheque / Transaction #</label>
                            <input type="text" name="reference_no" class="form-control" placeholder="Optional transaction ID">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Payment Notes / Purpose</label>
                            <input type="text" name="notes" class="form-control" placeholder="Weekly settlement, kharcha advance, festival bonus, etc.">
                        </div>

                        <div class="col-12 d-flex justify-content-end gap-2 pt-3 border-top">
                            <a href="<?= BASE_URL ?>modules/payments/index.php" class="btn btn-outline-secondary px-4">Cancel</a>
                            <button type="submit" class="btn btn-success px-5 fw-bold shadow-sm">
                                <i class="bi bi-cash me-1"></i> Confirm & Disburse Payment
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('workerSelect');
    const alertBox = document.getElementById('balanceAlertBox');
    const balanceText = document.getElementById('balanceText');
    const balanceBadge = document.getElementById('balanceBadge');
    const payAmount = document.getElementById('payAmount');

    function updateWorkerBalance() {
        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) {
            alertBox.classList.add('d-none');
            return;
        }

        const bal = parseFloat(opt.dataset.balance || 0);
        alertBox.classList.remove('d-none');

        if (bal > 0) {
            balanceText.textContent = 'Rs. ' + bal.toLocaleString('en-PK', {minimumFractionDigits: 2}) + ' (Payable to Worker)';
            balanceBadge.className = 'badge bg-danger-subtle text-danger fw-bold';
            balanceBadge.textContent = 'Factory Owes Worker';
            payAmount.placeholder = bal;
        } else if (bal < 0) {
            balanceText.textContent = 'Rs. ' + Math.abs(bal).toLocaleString('en-PK', {minimumFractionDigits: 2}) + ' (Advance Loan Given)';
            balanceBadge.className = 'badge bg-success-subtle text-success fw-bold';
            balanceBadge.textContent = 'Worker Owes Factory';
        } else {
            balanceText.textContent = 'Rs. 0.00 (Fully Settled)';
            balanceBadge.className = 'badge bg-light text-muted border';
            balanceBadge.textContent = 'Settled';
        }
    }

    select.addEventListener('change', updateWorkerBalance);
    if (select.value) {
        updateWorkerBalance();
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
