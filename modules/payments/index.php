<?php
/**
 * Garment Manufacturing ERP - Worker Payments List & Reversals
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$pageTitle = 'Worker Payments';
$activeNav = 'payments';
$db = get_db();

// Handle Payment Reversal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reverse_payment') {
    $paymentId = (int)($_POST['payment_id'] ?? 0);
    $reversalReason = trim($_POST['reversal_reason'] ?? 'Payment reversed by authorized user');

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT * FROM worker_payments WHERE id = ? AND is_reversed = 0");
        $stmt->execute([$paymentId]);
        $pm = $stmt->fetch();

        if (!$pm) {
            throw new Exception("Payment not found or already reversed.");
        }

        // Mark payment as reversed
        $up = $db->prepare("UPDATE worker_payments SET is_reversed = 1, reversed_at = NOW(), reversed_by = ? WHERE id = ?");
        $up->execute([$_SESSION['user_id'] ?? null, $paymentId]);

        // Add Reversal Transaction in Worker Ledger (Credit back the worker)
        // Get last balance
        $balStmt = $db->prepare("SELECT running_balance FROM worker_ledger WHERE worker_id = ? ORDER BY id DESC LIMIT 1");
        $balStmt->execute([$pm['worker_id']]);
        $lastBal = (float)$balStmt->fetchColumn();
        $newBal = $lastBal + (float)$pm['amount'];

        $ledgStmt = $db->prepare("INSERT INTO worker_ledger 
            (worker_id, transaction_date, transaction_type, reference_type, reference_id, debit, credit, running_balance, description, created_by) 
            VALUES (?, CURRENT_DATE(), 'Reversal', 'WORKER_PAYMENT_REVERSAL', ?, 0.00, ?, ?, ?, ?)");
        $ledgStmt->execute([
            $pm['worker_id'],
            $paymentId,
            $pm['amount'],
            $newBal,
            "Reversal of Voucher {$pm['payment_number']} ($reversalReason)",
            $_SESSION['user_id'] ?? null
        ]);

        // If from account, credit back the account
        if ($pm['account_id']) {
            record_account_transaction(
                (int)$pm['account_id'],
                date('Y-m-d'),
                'Adjustment',
                (float)$pm['amount'],
                'inflow',
                "Reversal of Worker Payment Voucher {$pm['payment_number']} ($reversalReason)",
                'WORKER_PAYMENT_REVERSAL',
                $paymentId,
                'REV-' . $pm['payment_number'],
                null,
                $_SESSION['user_id'] ?? null
            );
        }

        log_audit('REVERSE_PAYMENT', 'worker_payments', $paymentId, ['status' => 'Paid'], ['status' => 'Reversed', 'reason' => $reversalReason]);
        $db->commit();
        redirect('modules/payments/index.php', "Payment voucher {$pm['payment_number']} reversed successfully! Ledger corrected.");
    } catch (Exception $e) {
        $db->rollBack();
        set_flash('danger', 'Failed to reverse payment: ' . $e->getMessage());
    }
}

// Filters
$workerId = (int)($_GET['worker_id'] ?? 0);
$type = trim($_GET['type'] ?? '');

$sql = "SELECT wp.*, w.name AS worker_name, w.code AS worker_code, cba.account_name, u.full_name AS recorder_name 
        FROM worker_payments wp 
        JOIN workers w ON wp.worker_id = w.id 
        LEFT JOIN cash_bank_accounts cba ON wp.account_id = cba.id 
        LEFT JOIN users u ON wp.created_by = u.id 
        WHERE 1=1";

$params = [];
if ($workerId > 0) {
    $sql .= " AND wp.worker_id = ?";
    $params[] = $workerId;
}
if (!empty($type)) {
    $sql .= " AND wp.payment_type = ?";
    $params[] = $type;
}

$sql .= " ORDER BY wp.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$workers = $db->query("SELECT id, name, code FROM workers ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>

<div class="app-main">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    
    <div class="app-content">
        <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3 mb-4">
            <div>
                <h4 class="fw-bold mb-1 text-dark">Worker Payments & Advances</h4>
                <p class="text-muted small mb-0">Disbursed factory cash and bank transfers with double-entry ledger sync</p>
            </div>
            <a href="<?= BASE_URL ?>modules/payments/create.php" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
                <i class="bi bi-cash me-1"></i>
                <span>Disburse Payment</span>
            </a>
        </div>

        <!-- Filter Card -->
        <div class="card-modern mb-4">
            <div class="card-modern-body py-3">
                <form method="GET" action="" class="row g-2 align-items-center">
                    <div class="col-12 col-md-4">
                        <select name="worker_id" class="form-select form-select-sm">
                            <option value="">-- All Workers --</option>
                            <?php foreach ($workers as $w): ?>
                                <option value="<?= $w['id'] ?>" <?= $workerId === $w['id'] ? 'selected' : '' ?>>
                                    <?= clean($w['name']) ?> (<?= clean($w['code']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="type" class="form-select form-select-sm">
                            <option value="">-- All Types --</option>
                            <option value="Payment" <?= $type === 'Payment' ? 'selected' : '' ?>>Payment</option>
                            <option value="Advance" <?= $type === 'Advance' ? 'selected' : '' ?>>Advance Loan</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-secondary w-100">Filter</button>
                        <a href="<?= BASE_URL ?>modules/payments/index.php" class="btn btn-sm btn-outline-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payments Table -->
        <div class="card-modern">
            <div class="card-modern-header">
                <h6 class="fw-bold mb-0 text-dark">Payment Vouchers (<?= count($payments) ?>)</h6>
            </div>
            <div class="card-modern-body p-0">
                <div class="table-responsive">
                    <table class="table table-modern align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Voucher #</th>
                                <th>Date</th>
                                <th>Artisan / Worker</th>
                                <th>Type</th>
                                <th>Payment Method</th>
                                <th>Account</th>
                                <th>Ref #</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr><td colspan="10" class="text-center py-4 text-muted">No payments disbursed yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payments as $p): ?>
                                    <tr class="<?= $p['is_reversed'] ? 'table-light text-decoration-line-through text-muted' : '' ?>">
                                        <td class="fw-bold text-primary"><?= clean($p['payment_number']) ?></td>
                                        <td><?= format_date($p['payment_date']) ?></td>
                                        <td>
                                            <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $p['worker_id'] ?>" class="fw-bold text-decoration-none">
                                                <?= clean($p['worker_name']) ?>
                                            </a>
                                            <div class="text-muted small"><?= clean($p['worker_code']) ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border"><?= clean($p['payment_type']) ?></span>
                                        </td>
                                        <td><?= clean($p['payment_method']) ?></td>
                                        <td><?= clean($p['account_name'] ?: 'Cash Drawer') ?></td>
                                        <td class="small text-muted"><?= clean($p['reference_no'] ?: '-') ?></td>
                                        <td class="text-end fw-bold fs-6 text-dark"><?= format_currency($p['amount']) ?></td>
                                        <td>
                                            <?php if ($p['is_reversed']): ?>
                                                <span class="badge bg-danger-subtle text-danger fw-semibold">Reversed</span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success fw-semibold">Paid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <a href="<?= BASE_URL ?>modules/payments/receipt.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-1 px-2" title="Print Receipt">
                                                    <i class="bi bi-printer"></i>
                                                </a>
                                                <?php if (!$p['is_reversed']): ?>
                                                    <!-- Reversal Inline Trigger (No Modals) -->
                                                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('Reverse payment voucher <?= clean($p['payment_number']) ?>? This will credit the worker ledger back.');">
                                                        <input type="hidden" name="action" value="reverse_payment">
                                                        <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-2" title="Reverse Payment">
                                                            <i class="bi bi-arrow-counterclockwise"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
