<?php
/**
 * Garment Manufacturing ERP - Printable Worker Payment Receipt
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$paymentId = (int)($_GET['id'] ?? 0);
$db = get_db();

$stmt = $db->prepare("SELECT wp.*, w.name AS worker_name, w.code AS worker_code, w.phone AS worker_phone, w.cnic,
                             cba.account_name, u.full_name AS recorder_name 
                      FROM worker_payments wp 
                      JOIN workers w ON wp.worker_id = w.id 
                      LEFT JOIN cash_bank_accounts cba ON wp.account_id = cba.id 
                      LEFT JOIN users u ON wp.created_by = u.id 
                      WHERE wp.id = ?");
$stmt->execute([$paymentId]);
$payment = $stmt->fetch();

if (!$payment) {
    die("Payment voucher not found.");
}

// Current worker balance
$balStmt = $db->prepare("SELECT 
    COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS current_balance 
    FROM worker_ledger WHERE worker_id = ?");
$balStmt->execute([$payment['worker_id']]);
$currentBal = (float)$balStmt->fetchColumn();

$companyName = get_setting('company_name', 'Ali Garment Manufacturing Ltd.');
$companyAddress = get_setting('company_address', 'Industrial Area, Karachi / Faisalabad, Pakistan');
$companyPhone = get_setting('company_phone', '+92 300 1234567');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt - <?= clean($payment['payment_number']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <style>
        body { background: #fff !important; color: #000; font-size: 13px; }
        .receipt-box { border: 2px solid #000; padding: 25px; max-width: 760px; margin: 0 auto; }
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
        @media print {
            .no-print { display: none !important; }
            .receipt-box { border: 2px solid #000; }
        }
    </style>
</head>
<body class="p-4">
    <div class="no-print d-flex justify-content-between align-items-center mb-4 p-3 bg-light border rounded" style="max-width: 760px; margin: 0 auto;">
        <div>
            <h5 class="mb-0 fw-bold">Worker Payment Receipt Voucher</h5>
            <small class="text-muted">Printable receipt voucher for worker acknowledgment</small>
        </div>
        <div class="d-flex gap-2">
            <button type="button" onclick="window.print()" class="btn btn-primary fw-bold"><i class="bi bi-printer me-1"></i> Print Receipt</button>
            <button type="button" onclick="closePrintView('<?= BASE_URL ?>modules/payments/index.php')" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle me-1"></i> Close
            </button>
        </div>
    </div>

    <div class="receipt-box">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <div>
                <h3 class="fw-bold mb-0 text-uppercase"><?= clean($companyName) ?></h3>
                <div class="small text-muted"><?= clean($companyAddress) ?> · Tel: <?= clean($companyPhone) ?></div>
            </div>
            <div class="text-end">
                <div class="badge bg-dark text-white fs-6 px-3 py-2">PAYMENT VOUCHER</div>
                <div class="fw-bold mt-1 text-primary"><?= clean($payment['payment_number']) ?></div>
                <div class="small text-muted">Date: <?= format_date($payment['payment_date']) ?></div>
            </div>
        </div>

        <!-- Voucher Details Table -->
        <table class="table table-bordered mb-4">
            <tbody>
                <tr>
                    <th class="bg-light" style="width: 25%;">Worker / Payee:</th>
                    <td style="width: 75%;" class="fw-bold fs-6">
                        <?= clean($payment['worker_name']) ?> (<?= clean($payment['worker_code']) ?>)
                        <?php if ($payment['worker_phone']): ?> - Tel: <?= clean($payment['worker_phone']) ?><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th class="bg-light">Payment Classification:</th>
                    <td class="fw-semibold"><?= clean($payment['payment_type']) ?> (<?= clean($payment['payment_method']) ?>)</td>
                </tr>
                <tr>
                    <th class="bg-light">Disbursed Amount:</th>
                    <td class="fw-bold fs-5 text-success">
                        Rs. <?= number_format($payment['amount'], 2) ?>
                    </td>
                </tr>
                <tr>
                    <th class="bg-light">Account / Reference:</th>
                    <td>
                        <?= clean($payment['account_name'] ?: 'Direct Factory Cash Drawer') ?>
                        <?= $payment['reference_no'] ? ' (Ref: ' . clean($payment['reference_no']) . ')' : '' ?>
                    </td>
                </tr>
                <?php if ($payment['notes']): ?>
                <tr>
                    <th class="bg-light">Payment Purpose:</th>
                    <td><?= clean($payment['notes']) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th class="bg-light">Remaining Worker Balance:</th>
                    <td class="fw-bold">
                        Rs. <?= number_format(abs($currentBal), 2) ?>
                        <span class="small fw-normal text-muted">
                            (<?= $currentBal > 0 ? 'Payable to Worker' : ($currentBal < 0 ? 'Worker Advance / Receivable' : 'Settled') ?>)
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Signature Section -->
        <div class="row pt-5 mt-5 border-top text-center">
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Worker / Payee Signature</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Cashier / Disbursed By</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Authorized Signatory</div>
            </div>
        </div>
    </div>

    <script>
    function closePrintView(fallbackUrl) {
        window.close();
        setTimeout(function() {
            if (!window.closed) {
                window.location.href = fallbackUrl;
            }
        }, 150);
    }
    </script>
</body>
</html>
