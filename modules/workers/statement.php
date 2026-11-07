<?php
/**
 * Garment Manufacturing ERP - Printable Worker Work & Labour Statement
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/auth.php';

require_login();

$workerId = (int)($_GET['worker_id'] ?? $_GET['id'] ?? 0);
$db = get_db();

// Fetch active workers for quick switching
$allWorkers = $db->query("SELECT w.id, w.name, w.code, dep.name AS department_name 
                          FROM workers w 
                          LEFT JOIN departments dep ON w.department_id = dep.id 
                          WHERE w.is_active = 1 
                          ORDER BY w.name ASC")->fetchAll();

if ($workerId <= 0 && !empty($allWorkers)) {
    $workerId = (int)$allWorkers[0]['id'];
}

$stmt = $db->prepare("SELECT w.*, dep.name AS department_name FROM workers w LEFT JOIN departments dep ON w.department_id = dep.id WHERE w.id = ?");
$stmt->execute([$workerId]);
$worker = $stmt->fetch();

if (!$worker && !empty($allWorkers)) {
    $workerId = (int)$allWorkers[0]['id'];
    $stmt->execute([$workerId]);
    $worker = $stmt->fetch();
}

if (!$worker) {
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Worker Not Found</title><link rel='stylesheet' href='" . BASE_URL . "assets/css/bootstrap.min.css'></head><body class='p-5'><div class='container' style='max-width: 600px;'><div class='card shadow-sm border-0'><div class='card-body p-4 text-center'><div class='display-6 text-warning mb-3'><i class='bi bi-exclamation-triangle'></i></div><h4 class='fw-bold'>Worker Record Not Found</h4><p class='text-muted'>The requested worker could not be found or has been deactivated.</p><a href='" . BASE_URL . "modules/workers/index.php' class='btn btn-primary fw-bold px-4'>Back to Worker Directory</a></div></div></div></body></html>");
}

$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

// Work entries
$wSql = "SELECT pe.*, l.lot_number, p.name AS product_name, o.name AS op_name, c.name AS color_name, s.name AS size_name 
         FROM production_entries pe 
         JOIN lots l ON pe.lot_id = l.id 
         JOIN products p ON l.product_id = p.id 
         JOIN operations o ON pe.operation_id = o.id 
         LEFT JOIN colors c ON pe.color_id = c.id 
         LEFT JOIN sizes s ON pe.size_id = s.id 
         WHERE pe.worker_id = ?";
$wParams = [$workerId];
if (!empty($dateFrom)) { $wSql .= " AND pe.entry_date >= ?"; $wParams[] = $dateFrom; }
if (!empty($dateTo)) { $wSql .= " AND pe.entry_date <= ?"; $wParams[] = $dateTo; }
$wSql .= " ORDER BY pe.entry_date ASC";
$wStmt = $db->prepare($wSql);
$wStmt->execute($wParams);
$workList = $wStmt->fetchAll();

// Payments in date range
$pSql = "SELECT * FROM worker_payments WHERE worker_id = ? AND is_reversed = 0";
$pParams = [$workerId];
if (!empty($dateFrom)) { $pSql .= " AND payment_date >= ?"; $pParams[] = $dateFrom; }
if (!empty($dateTo)) { $pSql .= " AND payment_date <= ?"; $pParams[] = $dateTo; }
$pSql .= " ORDER BY payment_date ASC";
$pStmt = $db->prepare($pSql);
$pStmt->execute($pParams);
$payments = $pStmt->fetchAll();

// Calculate total earnings, total paid, closing balance
$totalLabour = 0;
$totalPieces = 0;
foreach ($workList as $w) {
    $totalLabour += (float)$w['labour_amount'];
    $totalPieces += (int)$w['good_qty'];
}

$totalPaid = 0;
$totalAdvance = 0;
foreach ($payments as $p) {
    if ($p['payment_type'] === 'Advance') {
        $totalAdvance += (float)$p['amount'];
    } else {
        $totalPaid += (float)$p['amount'];
    }
}

// Current running balance from ledger
$balStmt = $db->prepare("SELECT 
    COALESCE(SUM(credit), 0) AS total_earned,
    COALESCE(SUM(debit), 0) AS total_paid
    FROM worker_ledger WHERE worker_id = ?");
$balStmt->execute([$workerId]);
$tot = $balStmt->fetch();
$netClosingBalance = (float)$tot['total_earned'] - (float)$tot['total_paid'];

$companyName = get_setting('company_name', 'Ali Garment Manufacturing Ltd.');
$companyAddress = get_setting('company_address', 'Industrial Area, Karachi / Faisalabad, Pakistan');
$companyPhone = get_setting('company_phone', '+92 300 1234567');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Worker Statement - <?= clean($worker['name']) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bootstrap-icons.css">
    <style>
        body { background: #fff !important; color: #000; font-size: 13px; }
        .statement-box { border: 2px solid #000; padding: 20px; }
        .table-bordered th, .table-bordered td { border: 1px solid #000 !important; }
        @media print {
            .no-print { display: none !important; }
        }
    </style>
</head>
<body class="p-4">
    <div class="no-print d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4 p-3 bg-light border rounded">
        <div>
            <h5 class="mb-0 fw-bold">Worker Labour & Payment Statement</h5>
            <small class="text-muted">Printable formal ledger account for artisan settlement</small>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <form method="GET" action="" class="d-flex align-items-center gap-2 me-2">
                <div class="input-group input-group-sm" style="min-width: 220px;">
                    <span class="input-group-text bg-white"><i class="bi bi-person-badge"></i></span>
                    <select name="worker_id" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                        <?php foreach ($allWorkers as $wOpt): ?>
                            <option value="<?= $wOpt['id'] ?>" <?= $wOpt['id'] == $workerId ? 'selected' : '' ?>>
                                <?= clean($wOpt['name']) ?> (<?= clean($wOpt['code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white small">From</span>
                    <input type="date" name="date_from" value="<?= clean($dateFrom) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
                    <span class="input-group-text bg-white small">To</span>
                    <input type="date" name="date_to" value="<?= clean($dateTo) ?>" class="form-control form-control-sm" onchange="this.form.submit()">
                </div>
                <?php if (!empty($dateFrom) || !empty($dateTo)): ?>
                    <a href="statement.php?worker_id=<?= $workerId ?>" class="btn btn-sm btn-outline-secondary" title="Clear Date Filter">
                        <i class="bi bi-x-circle"></i>
                    </a>
                <?php endif; ?>
            </form>
            <button type="button" onclick="window.print()" class="btn btn-primary fw-bold text-nowrap"><i class="bi bi-printer me-1"></i> Print</button>
            <a href="<?= BASE_URL ?>modules/workers/view.php?id=<?= $worker['id'] ?? '' ?>" class="btn btn-outline-primary text-nowrap">
                <i class="bi bi-person me-1"></i> Profile
            </a>
            <a href="<?= BASE_URL ?>modules/reports/index.php?type=worker_performance" class="btn btn-outline-secondary text-nowrap">
                <i class="bi bi-graph-up me-1"></i> Reports
            </a>
            <button type="button" onclick="closePrintView('<?= BASE_URL ?>modules/workers/view.php?id=<?= $worker['id'] ?? '' ?>')" class="btn btn-outline-secondary text-nowrap">
                <i class="bi bi-x-circle me-1"></i> Close
            </button>
        </div>
    </div>

    <div class="statement-box">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
            <div>
                <h3 class="fw-bold mb-0 text-uppercase"><?= clean($companyName) ?></h3>
                <div class="small text-muted"><?= clean($companyAddress) ?> · Tel: <?= clean($companyPhone) ?></div>
            </div>
            <div class="text-end">
                <div class="badge bg-dark text-white fs-6 px-3 py-2">WORKER STATEMENT</div>
                <div class="small fw-semibold mt-1">Generated: <?= date('d M Y') ?></div>
                <?php if (!empty($dateFrom) || !empty($dateTo)): ?>
                    <div class="small text-muted mt-1">Period: <?= $dateFrom ? format_date($dateFrom) : 'Start' ?> to <?= $dateTo ? format_date($dateTo) : 'Today' ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Worker Meta -->
        <table class="table table-bordered mb-3">
            <tbody>
                <tr>
                    <td class="fw-bold bg-light" style="width: 15%;">Worker Name:</td>
                    <td style="width: 35%;" class="fw-bold fs-6"><?= clean($worker['name']) ?></td>
                    <td class="fw-bold bg-light" style="width: 15%;">Worker Code:</td>
                    <td style="width: 35%;" class="fw-bold text-primary"><?= clean($worker['code']) ?></td>
                </tr>
                <tr>
                    <td class="fw-bold bg-light">Worker Type:</td>
                    <td><?= clean($worker['worker_type']) ?> (<?= clean($worker['department_name'] ?? 'General') ?>)</td>
                    <td class="fw-bold bg-light">Phone / CNIC:</td>
                    <td><?= clean($worker['phone'] ?: '-') ?> / <?= clean($worker['cnic'] ?: '-') ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Work Details Table -->
        <h6 class="fw-bold text-uppercase border-bottom pb-1 mb-2">1. Production Work Log</h6>
        <table class="table table-bordered align-middle text-center mb-4">
            <thead>
                <tr class="bg-light">
                    <th>Date</th>
                    <th>Lot #</th>
                    <th>Product</th>
                    <th>Operation</th>
                    <th>Variant</th>
                    <th>Good Pcs</th>
                    <th>Rate</th>
                    <th class="text-end">Labour Earned</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($workList)): ?>
                    <tr><td colspan="8" class="text-center py-3 text-muted">No work recorded in this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($workList as $w): ?>
                        <tr>
                            <td><?= format_date($w['entry_date']) ?></td>
                            <td class="fw-bold">LOT #<?= clean($w['lot_number']) ?></td>
                            <td><?= clean($w['product_name']) ?></td>
                            <td class="fw-semibold"><?= clean($w['op_name']) ?></td>
                            <td><?= !empty($w['color_name']) ? clean($w['color_name']) . ' (' . clean($w['size_name']) . ')' : '<span class="text-muted small">Batch / All</span>' ?></td>
                            <td class="fw-bold text-success"><?= number_format($w['good_qty']) ?></td>
                            <td>Rs. <?= number_format($w['rate'], 2) ?></td>
                            <td class="text-end fw-bold">Rs. <?= number_format($w['labour_amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="bg-light fw-bold">
                    <td colspan="5" class="text-end">Total Work Done:</td>
                    <td><?= number_format($totalPieces) ?> Pcs</td>
                    <td></td>
                    <td class="text-end fs-6">Rs. <?= number_format($totalLabour, 2) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Payments Table -->
        <h6 class="fw-bold text-uppercase border-bottom pb-1 mb-2">2. Payments & Advances Received</h6>
        <table class="table table-bordered align-middle mb-4">
            <thead>
                <tr class="bg-light text-center">
                    <th>Date</th>
                    <th>Voucher #</th>
                    <th>Type</th>
                    <th>Payment Method</th>
                    <th>Ref / Cheque No</th>
                    <th class="text-end">Amount Paid</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="6" class="text-center py-2 text-muted">No payments disbursed in this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td class="text-center"><?= format_date($p['payment_date']) ?></td>
                            <td class="fw-bold text-center"><?= clean($p['payment_number']) ?></td>
                            <td class="text-center"><?= clean($p['payment_type']) ?></td>
                            <td class="text-center"><?= clean($p['payment_method']) ?></td>
                            <td><?= clean($p['reference_no'] ?: '-') ?></td>
                            <td class="text-end fw-bold">Rs. <?= number_format($p['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr class="bg-light fw-bold">
                    <td colspan="5" class="text-end">Total Disbursed:</td>
                    <td class="text-end fs-6">Rs. <?= number_format($totalPaid + $totalAdvance, 2) ?></td>
                </tr>
            </tfoot>
        </table>

        <!-- Closing Balance Summary Box -->
        <div class="row g-3 mb-4">
            <div class="col-6">
                <table class="table table-bordered mb-0">
                    <tr><td class="bg-light">Total Lifetime Earnings:</td><td class="fw-bold text-end">Rs. <?= number_format($tot['total_earned'], 2) ?></td></tr>
                    <tr><td class="bg-light">Total Lifetime Payments:</td><td class="fw-bold text-end">Rs. <?= number_format($tot['total_paid'], 2) ?></td></tr>
                </table>
            </div>
            <div class="col-6">
                <div class="p-3 border text-center <?= $netClosingBalance > 0 ? 'bg-light' : 'bg-light' ?>">
                    <div class="fw-bold small text-uppercase mb-1">
                        <?= $netClosingBalance > 0 ? 'Closing Payable to Worker (فیکٹری نے کاریگر کو دینے ہیں)' : ($netClosingBalance < 0 ? 'Closing Advance Loan (کاریگر سے وصول کرنے ہیں)' : 'Account Fully Settled') ?>
                    </div>
                    <div class="fs-4 fw-bold <?= $netClosingBalance > 0 ? 'text-danger' : ($netClosingBalance < 0 ? 'text-success' : 'text-dark') ?>">
                        Rs. <?= number_format(abs($netClosingBalance), 2) ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Signature Section -->
        <div class="row pt-4 mt-4 border-top text-center">
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Artisan / Worker Signature</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Accountant Signature</div>
            </div>
            <div class="col-4">
                <div class="border-top border-dark pt-1 fw-bold">Authorized Approval</div>
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
